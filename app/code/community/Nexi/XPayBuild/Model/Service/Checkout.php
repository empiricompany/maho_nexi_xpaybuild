<?php

declare(strict_types=1);

/**
 * Order placement orchestration for the XPay Build checkout flow.
 *
 * Authorizes the nonce via the gateway API, then creates the order,
 * transaction and (for immediate accounting) the invoice inside a DB
 * transaction.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_Service_Checkout
{
    public function placeOrder(
        Mage_Sales_Model_Quote $quote,
        string $nonce,
        string $codTrans,
        int $savedCardId = 0,
        bool $saveCard = false,
    ): void {
        $helper = Mage::helper('nexi_xpaybuild');
        $billing = $quote->getBillingAddress();
        $currencyCode = $quote->getQuoteCurrencyCode();
        $importo = $helper->formatAmountToMinorUnit($quote->getGrandTotal(), $currencyCode);
        $divisa = $helper->getCurrencyNumericCode($currencyCode);
        $accountingType = $helper->getAccountingType();
        $customerId = (int) $quote->getCustomerId();

        // Resolve the saved card server-side (with ownership check): the
        // client only sends the card id, never the gateway token.
        $savedCard = null;
        if ($savedCardId > 0 && $customerId > 0) {
            $savedCard = Mage::helper('nexi_xpaybuild/savedCard')->loadCard($savedCardId, $customerId);
            if ($savedCard === null) {
                Mage::throwException(
                    $helper->__('The selected saved card is not valid. Please use a new card.'),
                );
            }
        }

        $payment = $quote->getPayment();
        $payment->setMethod('nexi_xpaybuild');
        $payment->setAdditionalInformation('nexi_cod_trans', $codTrans);
        $payment->setAdditionalInformation('nexi_gateway', 'XPAY');
        $payment->setAdditionalInformation('nexi_save_card', $saveCard);

        $result = Mage::getModel('nexi_xpaybuild/service_authorize')->authorize(
            $payment,
            $codTrans,
            $importo,
            $divisa,
            $nonce,
            $accountingType,
            $billing?->getFirstname(),
            $billing?->getLastname(),
            $quote->getCustomerEmail(),
            $quote->getReservedOrderId(),
            $saveCard && $customerId > 0 && $savedCard === null,
            $customerId,
            $savedCard !== null,
        );
        $response = $result['response'];
        $esito = $result['esito'];
        $numeroContratto = $result['numeroContratto'];
        $rawDetails = $result['rawDetails'];

        if ($esito === 'OK' && $saveCard && $customerId > 0) {
            Mage::helper('nexi_xpaybuild/savedCard')->saveCardFromResponse($response, $numeroContratto, $quote);
        }

        if ($savedCard !== null) {
            $payment->setAdditionalInformation('nexi_saved_card_id', $savedCardId);
            Mage::helper('nexi_xpaybuild/savedCard')->enrichFromSavedCard(
                $payment,
                $rawDetails,
                $savedCardId,
                $customerId,
            );
        }

        $payment->save();

        if ($customerId > 0 && !$quote->getData('checkout_method')) {
            $quote->setData('checkout_method', Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER);
        }

        $quote->collectTotals();

        $onepage = Mage::getSingleton('checkout/type_onepage');
        $onepage->setQuote($quote);
        $onepage->saveOrder();

        $quote->setIsActive(0);
        $quote->save();

        $order = Mage::getModel('sales/order')->load($quote->getId(), 'quote_id');
        if (!$order->getId()) {
            $helper->log('placeOrder: order not found for quote ' . $quote->getId(), Mage::LOG_ERROR);
            Mage::throwException($helper->__('Order could not be created. Please try again.'));
        }

        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $connection->beginTransaction();
        try {
            $this->_finalizeOrder($order, $esito, $rawDetails, $codTrans, $accountingType);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $helper->log(
                'placeOrder: failed to finalize order ' . $order->getId() . ': ' . $e->getMessage(),
                Mage::LOG_ERROR,
            );
            throw $e;
        }
    }

    /**
     * Record the authorization transaction, create the invoice (immediate
     * accounting) and set the order state. Runs inside the caller's DB
     * transaction.
     *
     * @param array<string, mixed> $rawDetails
     */
    protected function _finalizeOrder(
        Mage_Sales_Model_Order $order,
        string $esito,
        array $rawDetails,
        string $codTrans,
        string $accountingType,
    ): void {
        $helper = Mage::helper('nexi_xpaybuild');
        $orderPayment = $order->getPayment();
        $orderPayment->setTransactionId($codTrans);
        $orderPayment->setTransactionAdditionalInfo(
            Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
            $rawDetails,
        );

        if ($esito === 'OK' && $accountingType === Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE) {
            $orderPayment->setIsTransactionClosed(true);
            $orderPayment->setIsTransactionPending(false);
        } elseif ($esito === 'PEN') {
            $orderPayment->setIsTransactionPending(true);
            $orderPayment->setIsTransactionClosed(false);
        } else {
            $orderPayment->setIsTransactionClosed(false);
        }

        $orderPayment->addTransaction(
            Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,
            null,
            false,
            $helper->__('Authorized amount of %s', $order->getBaseCurrency()->formatTxt($order->getBaseTotalDue())),
        );

        $helper->createInvoiceIfImmediate($order, $esito, $accountingType, $codTrans);

        if ($esito === 'OK') {
            $orderStatus = $helper->getConfig('order_status');
            if (!$orderStatus) {
                $orderStatus = Mage_Sales_Model_Order::STATE_PROCESSING;
            }
            $order->setState(
                Mage_Sales_Model_Order::STATE_PROCESSING,
                $orderStatus,
                $helper->__('Payment authorized via Nexi XPay Build.'),
            );
            $order->save();
        }
    }
}
