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
    protected $_isInitializeNeeded = true;
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

        $savedCardId = $data->getData('saved_card_id');
        $info->setAdditionalInformation('nexi_saved_card_id', $savedCardId !== null ? (int) $savedCardId : 0);

        $saveCard = $data->getData('save_card');
        $info->setAdditionalInformation('nexi_save_card', $saveCard !== null ? (bool) $saveCard : false);

        return $this;
    }

    /**
     * The order is placed through the module's placeOrder endpoint, which
     * authorizes via Service/Authorize before saveOrder(). initialize()
     * therefore only marks the order as pending payment and — via
     * $_isInitializeNeeded — prevents submitOrder() from invoking
     * authorize()/capture() a second time.
     */
    #[\Override]
    public function initialize($paymentAction, $stateObject): void
    {
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(true);
        $stateObject->setIsNotified(false);
    }

    #[\Override]
    public function authorize(DataObject $payment, $amount): static
    {
        return $this->_authorizeXpay($payment, (float) $amount);
    }

    #[\Override]
    public function capture(DataObject $payment, $amount): static
    {
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
        );

        $payment->setTransactionId($codTrans);

        $helper->createInvoiceIfImmediate($order, $result['esito'], $accountingType, $codTrans);

        return $this;
    }

    protected function _captureXpay(DataObject $payment, float $amount): static
    {
        $helper = Mage::helper('nexi_xpaybuild');

        if ($helper->getAccountingType() === Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE) {
            $helper->log(
                'NexiPayment::_captureXpay() no-op: immediate accounting (TCONTAB=C), already captured at order time.',
                Mage::LOG_DEBUG,
            );
            $payment->setIsTransactionClosed(true);
            $payment->setIsTransactionPending(false);
            return $this;
        }

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
