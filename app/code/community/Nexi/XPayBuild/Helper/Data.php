<?php

declare(strict_types=1);

/**
 * Nexi XPay Build configuration helper.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH = 'payment/nexi_xpaybuild/';

    /** @var array<string, int> Number of minor-unit decimals per ISO 4217 currency code. */
    private const CURRENCY_DECIMALS = [
        'EUR' => 2,
        'USD' => 2,
        'GBP' => 2,
        'CHF' => 2,
        'JPY' => 0,
    ];

    /** @var array<string, string> ISO 4217 numeric code per currency code. */
    private const CURRENCY_NUMERIC_CODES = [
        'EUR' => '978',
        'USD' => '840',
        'GBP' => '826',
        'CHF' => '756',
        'JPY' => '392',
    ];

    public function getConfig(string $field, ?int $storeId = null): mixed
    {
        return Mage::getStoreConfig(self::XML_PATH . $field, $storeId);
    }

    public function getGatewayType(): string
    {
        return 'XPAY';
    }

    public function getXpayEnvironment(?int $storeId = null): string
    {
        return (string) $this->getConfig('environment', $storeId);
    }

    public function getXpayAlias(?int $storeId = null): string
    {
        return (string) $this->getConfig('alias', $storeId);
    }

    public function getXpayMacKey(?int $storeId = null): string
    {
        $macKey = (string) $this->getConfig('mac_key', $storeId);
        return Mage::helper('core')->decrypt($macKey);
    }

    public function getAccountingType(?int $storeId = null): string
    {
        return (string) $this->getConfig('accounting_type', $storeId);
    }

    public function getCardFormStyle(?int $storeId = null): string
    {
        return (string) $this->getConfig('card_form_style', $storeId);
    }

    public function getOneclickEnabled(?int $storeId = null): bool
    {
        return (bool) $this->getConfig('oneclick_enabled', $storeId);
    }

    public function getXpayBaseUrl(?int $storeId = null): string
    {
        return $this->getXpayEnvironment($storeId) === 'test'
            ? Nexi_XPayBuild_Model_Api_XpayClient::XPAY_BASE_URL_TEST
            : Nexi_XPayBuild_Model_Api_XpayClient::XPAY_BASE_URL_PRODUCTION;
    }

    public function getXpaySdkUrl(?int $storeId = null): string
    {
        $baseUrl = $this->getXpayBaseUrl($storeId);
        $alias = $this->getXpayAlias($storeId);
        return $baseUrl . 'ecomm/XPayBuild/js?alias=' . urlencode($alias);
    }

    public function formatAmountToMinorUnit(float|string $amount, string $currencyCode = 'EUR'): int
    {
        $decimals = self::CURRENCY_DECIMALS[$currencyCode] ?? 2;
        return (int) round((float) $amount * (10 ** $decimals));
    }

    public function getCurrencyNumericCode(?string $currencyCode = null): string
    {
        if ($currencyCode === null) {
            $currencyCode = $this->getStoreCurrencyCode();
        }

        return self::CURRENCY_NUMERIC_CODES[$currencyCode] ?? '978';
    }

    public function getStoreCurrencyCode(): string
    {
        return Mage::app()->getStore()->getCurrentCurrencyCode();
    }

    public function generateTransactionId(Mage_Sales_Model_Quote|Mage_Sales_Model_Order $entity): string
    {
        return substr((string) $entity->getId() . '-' . time(), 0, 30);
    }

    public function log(string $message, \Monolog\Level|int|null $level = null): void
    {
        Mage::log($message, $level ?? Mage::LOG_DEBUG, 'nexi_xpaybuild.log', true);
    }

    public function getModuleVersion(): string
    {
        return (string) Mage::getConfig()->getNode('modules/Nexi_XPayBuild/version');
    }

    /**
     * Create the invoice when the gateway already captured the payment in the
     * same authorize call (immediate accounting, TCONTAB=C). Shared by the
     * AJAX checkout flow (Model/Service/Checkout) and the legacy payment
     * method
     * (NexiPayment) to avoid duplicating the conditional + call.
     */
    public function createInvoiceIfImmediate(
        Mage_Sales_Model_Order $order,
        string $esito,
        string $accountingType,
        string $transactionId = '',
    ): ?Mage_Sales_Model_Order_Invoice {
        if ($esito !== 'OK' || $accountingType !== Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE) {
            return null;
        }

        return $this->createInvoiceForOrder($order, $transactionId);
    }

    public function createInvoiceForOrder(Mage_Sales_Model_Order $order, string $transactionId = ''): ?Mage_Sales_Model_Order_Invoice
    {
        if (!$order->canInvoice()) {
            $this->log('createInvoiceForOrder: cannot invoice order ' . $order->getId(), Mage::LOG_WARNING);
            return null;
        }

        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
        $invoice->register();

        if ($transactionId) {
            $invoice->setTransactionId($transactionId);
        }

        Mage::getModel('core/resource_transaction')
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();

        if (!$order->getEmailSent()) {
            try {
                $order->sendNewOrderEmail();
                $order->setEmailSent(true);
                $order->save();
            } catch (\Throwable $e) {
                $this->log('createInvoiceForOrder: sendNewOrderEmail error: ' . $e->getMessage(), Mage::LOG_WARNING);
            }
        }

        return $invoice;
    }

    public function formatExpiry(string $expiry): string
    {
        if ($expiry === '' || !preg_match('/^(\d{4})(\d{2})$/', $expiry, $matches)) {
            return $expiry;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month));
        if ($date === false) {
            return $expiry;
        }

        $locale = Mage::app()->getLocale()->getLocaleCode();
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, null, null, 'MM/yyyy');

        return $formatter->format($date) ?: $date->format('m/Y');
    }

    public function getPluginSignature(): string
    {
        return 'Platform: maho ' . Mage::getVersion() . ' - PluginVersion: ' . $this->getModuleVersion();
    }

    /**
     * Persist the relevant response fields on the payment info and build the
     * RAW_DETAILS array used for the sales transaction.
     *
     * Shared by the AJAX checkout flow (Service/Authorize) and the legacy
     * payment method model (NexiPayment) to avoid duplicating this logic.
     *
     * @param string[] $specialFields Fields stored both as additional info and in rawDetails.
     * @return array<string, mixed>
     */
    public function saveResponseFields(
        Mage_Payment_Model_Info $payment,
        array $response,
        array $specialFields,
    ): array {
        $rawDetails = [];

        foreach ($specialFields as $field) {
            if (isset($response[$field])) {
                $payment->setAdditionalInformation('nexi_' . $field, $response[$field]);
                $rawDetails[$field] = $response[$field];
            }
        }

        foreach ($response as $key => $value) {
            if (!isset($rawDetails[$key]) && $key !== 'esito') {
                $rawDetails[$key] = is_scalar($value) ? $value : json_encode($value);
            }
        }

        $payment->setTransactionAdditionalInfo(
            Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
            $rawDetails,
        );

        return $rawDetails;
    }
}
