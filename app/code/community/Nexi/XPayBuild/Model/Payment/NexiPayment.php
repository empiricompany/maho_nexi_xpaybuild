<?php

declare(strict_types=1);

/**
 * XPay Build payment method model.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Maho\DataObject;

class Nexi_XPayBuild_Model_Payment_NexiPayment extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'nexi_xpaybuild';
    protected $_formBlockType = 'nexi_xpaybuild/form_build';
    protected $_infoBlockType = 'nexi_xpaybuild/info';

    protected $_isGateway = true;
    protected $_isInitializeNeeded = false;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;

    #[\Override]
    public function isAvailable($quote = null): bool
    {
        $helper = Mage::helper('nexi_xpaybuild');

        if (!parent::isAvailable($quote)) {
            return false;
        }

        if (!$helper->getXpayAlias() || !$helper->getXpayMacKey()) {
            return false;
        }

        if ($helper->getXpayEnvironment() === 'test' && !Mage::helper('core')->isDevAllowed()) {
            return false;
        }

        return true;
    }

    /**
     * Card data never touches the server (PCI-DSS hosted fields), so there is
     * nothing to validate server-side.
     */
    #[\Override]
    public function validate(): static
    {
        return $this;
    }

    #[\Override]
    public function assignData($data): static
    {
        parent::assignData($data);

        if (!($data instanceof DataObject)) {
            $data = new DataObject($data);
        }

        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('nexi_gateway', 'XPAY');

        $nonce = $data->getData('xpay_nonce');
        if ($nonce) {
            $info->setAdditionalInformation('nexi_nonce', (string) $nonce);
        }

        $codTrans = $data->getData('xpay_cod_trans');
        if ($codTrans) {
            $info->setAdditionalInformation('nexi_cod_trans', (string) $codTrans);
        }

        $savedCardId = $data->getData('saved_card_id');
        $info->setAdditionalInformation('nexi_saved_card_id', $savedCardId !== null ? (int) $savedCardId : 0);

        $saveCard = $data->getData('save_card');
        $info->setAdditionalInformation('nexi_save_card', $saveCard !== null ? (bool) $saveCard : false);

        return $this;
    }

    /**
     * Determine the payment action based on the configured accounting type:
     * TCONTAB=C (immediate) → authorize_capture, TCONTAB=D (deferred) → authorize.
     */
    #[\Override]
    public function getConfigPaymentAction(): ?string
    {
        $helper = Mage::helper('nexi_xpaybuild');
        $accountingType = $helper->getAccountingType();

        return $accountingType === Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE
            ? self::ACTION_AUTHORIZE_CAPTURE
            : self::ACTION_AUTHORIZE;
    }

    #[\Override]
    public function authorize(DataObject $payment, $amount): static
    {
        return $this->_authorizeXpay($payment, (float) $amount);
    }

    #[\Override]
    public function capture(DataObject $payment, $amount): static
    {
        $helper = Mage::helper('nexi_xpaybuild');

        // During place order with ACTION_AUTHORIZE_CAPTURE (TCONTAB=C),
        // the framework calls capture() — the API call (pagaNonce with
        // TCONTAB=C) authorizes AND captures in one step, so delegate
        // to _authorizeXpay() which does the real work.
        if ($helper->getAccountingType() === Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE
            && $payment->getAdditionalInformation('nexi_nonce')
        ) {
            return $this->_authorizeXpay($payment, (float) $amount);
        }

        // Admin capture (invoice capture) or deferred capture: call the
        // Nexi contabilizza API to capture a previously authorized amount.
        return $this->_captureXpay($payment, (float) $amount);
    }

    #[\Override]
    public function refund(DataObject $payment, $amount): static
    {
        return $this->_refundXpay($payment, (float) $amount);
    }

    #[\Override]
    public function void(DataObject $payment): never
    {
        Mage::throwException(
            Mage::helper('nexi_xpaybuild')->__('Void not supported for XPay gateway. Use refund instead.')
        );
    }

    #[\Override]
    public function getTitle(): string
    {
        $title = Mage::helper('nexi_xpaybuild')->getConfig('title');
        return $title ? (string) $title : parent::getTitle();
    }

    protected function _authorizeXpay(DataObject $payment, float $amount): static
    {
        $helper = Mage::helper('nexi_xpaybuild');
        $order = $payment->getOrder();

        $nonce = (string) $payment->getAdditionalInformation('nexi_nonce');
        $codTrans = (string) $payment->getAdditionalInformation('nexi_cod_trans');

        if ($nonce === '') {
            Mage::throwException(
                $helper->__('XPay nonce is missing. Please retry the payment.')
            );
        }

        if ($codTrans === '') {
            $codTrans = $helper->generateTransactionId($order);
        }

        $currencyCode = $order->getOrderCurrencyCode();
        $importo = $helper->formatAmountToMinorUnit($amount, $currencyCode);
        $divisa = $helper->getCurrencyNumericCode($currencyCode);
        $accountingType = $helper->getAccountingType();
        $billingAddress = $order->getBillingAddress();

        $savedCardId = (int) $payment->getAdditionalInformation('nexi_saved_card_id');
        $customerId = (int) $order->getCustomerId();
        $savedCard = null;
        if ($savedCardId > 0 && $customerId > 0) {
            $savedCard = Mage::helper('nexi_xpaybuild/savedCard')->loadCard($savedCardId, $customerId);
            if ($savedCard === null) {
                Mage::throwException(
                    $helper->__('The selected saved card is not valid. Please use a new card.')
                );
            }
        }

        $saveCard = (bool) $payment->getAdditionalInformation('nexi_save_card');

        $result = Mage::getModel('nexi_xpaybuild/service_authorize')->authorize(
            $payment,
            $codTrans,
            $importo,
            $divisa,
            $nonce,
            $accountingType,
            $billingAddress?->getFirstname(),
            $billingAddress?->getLastname(),
            $order->getCustomerEmail(),
            $order->getIncrementId(),
            $saveCard && $customerId > 0 && $savedCard === null,
            $customerId,
            $savedCard !== null,
        );

        $payment->setTransactionId($codTrans);

        $esito = $result['esito'];
        $rawDetails = $result['rawDetails'];
        $numeroContratto = $result['numeroContratto'];

        if ($esito === 'OK' && $accountingType === Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE) {
            $payment->setIsTransactionClosed(true);
            $payment->setIsTransactionPending(false);
        } elseif ($esito === 'PEN') {
            $payment->setIsTransactionPending(true);
            $payment->setIsTransactionClosed(false);
        } else {
            $payment->setIsTransactionClosed(false);
        }

        if ($esito === 'OK' && $saveCard && $customerId > 0) {
            Mage::helper('nexi_xpaybuild/savedCard')->saveCardFromResponse(
                $result['response'],
                $numeroContratto,
                $order,
            );
        }

        if ($savedCard !== null) {
            Mage::helper('nexi_xpaybuild/savedCard')->enrichFromSavedCard(
                $payment,
                $rawDetails,
                $savedCardId,
                $customerId,
            );
        }

        return $this;
    }

    protected function _captureXpay(DataObject $payment, float $amount): static
    {
        $helper = Mage::helper('nexi_xpaybuild');
        $order = $payment->getOrder();
        $codTrans = (string) $payment->getAdditionalInformation('nexi_cod_trans');

        if ($codTrans === '') {
            $codTrans = (string) $payment->getLastTransId();
        }

        $currencyCode = $order->getOrderCurrencyCode();
        $importo = $helper->formatAmountToMinorUnit($amount, $currencyCode);
        $divisa = $helper->getCurrencyNumericCode($currencyCode);

        /** @var Nexi_XPayBuild_Model_Api_XpayClient $client */
        $client = Mage::getModel('nexi_xpaybuild/api_xpayClient');
        $response = $client->capture($codTrans, $importo, $divisa);

        $esito = $response['esito'] ?? '';
        if ($esito !== 'OK') {
            $errorMsg = $response['errore']['messaggio'] ?? $helper->__('Capture failed (XPay). Esito: %s', $esito);
            Mage::throwException($errorMsg);
        }

        $helper->saveResponseFields($payment, $response, ['codiceAutorizzazione', 'codAut', 'brand', 'pan', 'scadenza', 'scadenzaPan']);
        $payment->setTransactionId($codTrans . '-capture');
        $payment->setIsTransactionClosed(true);

        return $this;
    }

    protected function _refundXpay(DataObject $payment, float $amount): static
    {
        $helper = Mage::helper('nexi_xpaybuild');
        $order = $payment->getOrder();
        $codTrans = (string) $payment->getAdditionalInformation('nexi_cod_trans');

        if ($codTrans === '') {
            $codTrans = (string) $payment->getLastTransId();
        }

        $currencyCode = $order->getOrderCurrencyCode();
        $importo = $helper->formatAmountToMinorUnit($amount, $currencyCode);
        $divisa = $helper->getCurrencyNumericCode($currencyCode);

        /** @var Nexi_XPayBuild_Model_Api_XpayClient $client */
        $client = Mage::getModel('nexi_xpaybuild/api_xpayClient');
        $response = $client->refund($codTrans, $importo, $divisa);

        $esito = $response['esito'] ?? '';
        if ($esito !== 'OK') {
            $errorMsg = $response['errore']['messaggio'] ?? $helper->__('Refund failed (XPay). Esito: %s', $esito);
            Mage::throwException($errorMsg);
        }

        $helper->saveResponseFields($payment, $response, ['codiceAutorizzazione', 'codAut', 'brand', 'pan', 'scadenza', 'scadenzaPan']);
        $payment->setTransactionId($codTrans . '-refund');
        $payment->setIsTransactionClosed(true);

        return $this;
    }

}
