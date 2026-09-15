<?php

declare(strict_types=1);

/**
 * XPay API client using Symfony HttpClient.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Nexi_XPayBuild_Model_Api_XpayClient
{
    public const string XPAY_BASE_URL_PRODUCTION = 'https://ecommerce.nexi.it/';
    public const string XPAY_BASE_URL_TEST = 'https://int-ecommerce.nexi.it/';

    public const string XPAY_URI_PAGA_NONCE = 'ecomm/api/hostedPayments/pagaNonce';
    public const string XPAY_URI_PAGA_NONCE_CREA_CONTRATTO = 'ecomm/api/hostedPayments/pagaNonceCreazioneContratto';
    public const string XPAY_URI_RICORRENTE_3DS = 'ecomm/api/recurring/pagamentoRicorrente3DS';
    public const string XPAY_URI_ACCOUNT = 'ecomm/api/bo/contabilizza';
    public const string XPAY_URI_REFUND = 'ecomm/api/bo/storna';
    public const string XPAY_URI_ORDER_DETAIL = 'ecomm/api/bo/situazioneOrdine';
    public const string XPAY_URI_PROFILE_INFO = 'ecomm/api/profileInfo';

    public const string XPAY_TCONTAB_IMMEDIATE = 'C';
    public const string XPAY_TCONTAB_DEFERRED = 'D';

    public const string XPAY_CARD_FORM_STYLE_SPLIT = 'SPLIT_CARD';
    public const string XPAY_CARD_FORM_STYLE_UNIFIED = 'CARD';

    private readonly Nexi_XPayBuild_Helper_Data $_helper;
    private readonly Nexi_XPayBuild_Helper_Mac $_macHelper;
    private readonly HttpClientInterface $_httpClient;

    public function __construct()
    {
        $this->_helper = Mage::helper('nexi_xpaybuild');
        $this->_macHelper = Mage::helper('nexi_xpaybuild/mac');
        $this->_httpClient = HttpClient::create(['timeout' => 30]);
    }

    public function capture(string $codiceTransazione, int $importo, string $divisa): array
    {
        $alias = $this->_helper->getXpayAlias();
        $macKey = $this->_helper->getXpayMacKey();
        $timeStamp = (string) (time() * 1000);

        $payload = [
            'apiKey' => $alias,
            'codiceTransazione' => $codiceTransazione,
            'importo' => $importo,
            'divisa' => (int) $divisa,
            'timeStamp' => $timeStamp,
            'mac' => $this->_macHelper->calculateAccountingMac($alias, $codiceTransazione, $importo, $divisa, $timeStamp, $macKey),
        ];

        return $this->_doPost(self::XPAY_URI_ACCOUNT, $payload);
    }

    public function refund(string $codiceTransazione, int $importo, string $divisa): array
    {
        $alias = $this->_helper->getXpayAlias();
        $macKey = $this->_helper->getXpayMacKey();
        $timeStamp = (string) (time() * 1000);

        $payload = [
            'apiKey' => $alias,
            'codiceTransazione' => $codiceTransazione,
            'importo' => $importo,
            'divisa' => (int) $divisa,
            'timeStamp' => $timeStamp,
            'mac' => $this->_macHelper->calculateAccountingMac($alias, $codiceTransazione, $importo, $divisa, $timeStamp, $macKey),
        ];

        return $this->_doPost(self::XPAY_URI_REFUND, $payload);
    }

    public function orderDetail(string $codiceTransazione): array
    {
        $alias = $this->_helper->getXpayAlias();
        $macKey = $this->_helper->getXpayMacKey();
        $timeStamp = (string) (time() * 1000);

        $payload = [
            'apiKey' => $alias,
            'codiceTransazione' => $codiceTransazione,
            'timeStamp' => $timeStamp,
            'mac' => $this->_macHelper->calculateOrderDetailMac($alias, $codiceTransazione, $timeStamp, $macKey),
        ];

        return $this->_doPost(self::XPAY_URI_ORDER_DETAIL, $payload);
    }

    public function profileInfo(): array
    {
        $alias = $this->_helper->getXpayAlias();
        $macKey = $this->_helper->getXpayMacKey();
        $timeStamp = (string) (time() * 1000);

        $payload = [
            'apiKey' => $alias,
            'timeStamp' => $timeStamp,
            'mac' => $this->_macHelper->calculateProfileInfoMac($alias, $timeStamp, $macKey),
            'platform' => 'maho',
            'platformVers' => Mage::getVersion(),
            'pluginVers' => $this->_helper->getModuleVersion(),
        ];

        return $this->_doPost(self::XPAY_URI_PROFILE_INFO, $payload);
    }

    public function pagaNonce(
        string $codiceTransazione,
        int $importo,
        string $divisa,
        string $xpayNonce,
        string $accountingType,
        ?string $customerName = null,
        ?string $customerSurname = null,
        ?string $customerEmail = null,
        ?string $incrementId = null,
    ): array {
        $payload = $this->_buildNoncePayload(
            $codiceTransazione,
            $importo,
            $divisa,
            $xpayNonce,
            $accountingType,
            $customerName,
            $customerSurname,
            $customerEmail,
            $incrementId,
        );

        return $this->_doPost(self::XPAY_URI_PAGA_NONCE, $payload);
    }

    public function pagaNonceCreazioneContratto(
        string $codiceTransazione,
        int $importo,
        string $divisa,
        string $xpayNonce,
        string $numeroContratto,
        string $accountingType,
        ?string $customerName = null,
        ?string $customerSurname = null,
        ?string $customerEmail = null,
        ?string $incrementId = null,
    ): array {
        $payload = $this->_buildNoncePayload(
            $codiceTransazione,
            $importo,
            $divisa,
            $xpayNonce,
            $accountingType,
            $customerName,
            $customerSurname,
            $customerEmail,
            $incrementId,
            $numeroContratto,
        );

        return $this->_doPost(self::XPAY_URI_PAGA_NONCE_CREA_CONTRATTO, $payload);
    }

    public function pagamentoRicorrente3DS(
        string $codiceTransazione,
        int $importo,
        string $divisa,
        string $xpayNonce,
        string $accountingType,
        ?string $customerName = null,
        ?string $customerSurname = null,
        ?string $customerEmail = null,
        ?string $incrementId = null,
    ): array {
        $payload = $this->_buildNoncePayload(
            $codiceTransazione,
            $importo,
            $divisa,
            $xpayNonce,
            $accountingType,
            $customerName,
            $customerSurname,
            $customerEmail,
            $incrementId,
        );

        return $this->_doPost(self::XPAY_URI_RICORRENTE_3DS, $payload);
    }

    protected function _buildNoncePayload(
        string $codiceTransazione,
        int $importo,
        string $divisa,
        string $xpayNonce,
        string $accountingType,
        ?string $customerName = null,
        ?string $customerSurname = null,
        ?string $customerEmail = null,
        ?string $incrementId = null,
        ?string $numeroContratto = null,
    ): array {
        $alias = $this->_helper->getXpayAlias();
        $macKey = $this->_helper->getXpayMacKey();
        $timeStamp = (string) (time() * 1000);

        $payload = [
            'apiKey' => $alias,
            'codiceTransazione' => $codiceTransazione,
            'importo' => $importo,
            'divisa' => (int) $divisa,
            'xpayNonce' => $xpayNonce,
            'timeStamp' => $timeStamp,
            'mac' => $this->_macHelper->calculateNonceMac($alias, $codiceTransazione, $importo, $divisa, $xpayNonce, $timeStamp, $macKey),
            'parametriAggiuntivi' => [
                'TCONTAB' => $accountingType,
                'Note2' => $this->_helper->getPluginSignature(),
            ],
        ];

        if ($numeroContratto !== null) {
            $payload['numeroContratto'] = $numeroContratto;
        }

        if ($customerName !== null) {
            $payload['parametriAggiuntivi']['nome'] = $customerName;
        }
        if ($customerSurname !== null) {
            $payload['parametriAggiuntivi']['cognome'] = $customerSurname;
        }
        if ($customerEmail !== null) {
            $payload['parametriAggiuntivi']['mail'] = $customerEmail;
        }
        if ($incrementId !== null) {
            $payload['parametriAggiuntivi']['Note1'] = 'Ordine #' . $incrementId;
        }

        return $payload;
    }

    protected function _doPost(string $endpoint, array $payload): array
    {
        $baseUrl = $this->_helper->getXpayBaseUrl();
        $url = $baseUrl . $endpoint;

        $this->_helper->log('XpayClient POST ' . $url . ' payload: ' . json_encode($this->_sanitizeForLog($payload)));

        try {
            $response = $this->_httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($payload),
            ]);

            $httpCode = $response->getStatusCode();
            $rawResponse = $response->getContent();
        } catch (\Throwable $e) {
            $msg = 'XpayClient HTTP error for ' . $url . ': ' . $e->getMessage();
            $this->_helper->log($msg, Mage::LOG_ERROR);
            Mage::throwException($msg);
        }

        $this->_helper->log('XpayClient response [HTTP ' . $httpCode . ']: ' . $rawResponse);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = 'XpayClient received HTTP ' . $httpCode . ' from ' . $url;
            $this->_helper->log($msg, Mage::LOG_ERROR);
            Mage::throwException($msg);
        }

        if (!json_validate($rawResponse)) {
            $msg = 'XpayClient could not decode JSON response from ' . $url;
            $this->_helper->log($msg, Mage::LOG_ERROR);
            Mage::throwException($msg);
        }

        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            $msg = 'XpayClient could not decode JSON response from ' . $url;
            $this->_helper->log($msg, Mage::LOG_ERROR);
            Mage::throwException($msg);
        }

        if (($decoded['esito'] ?? '') !== 'OK') {
            $msg = 'Errore da XPay API';
            if (isset($decoded['errore']['messaggio'])) {
                $msg .= ': ' . $decoded['errore']['messaggio'];
            }
            $this->_helper->log($msg, Mage::LOG_ERROR);
        }

        $requiredMacFields = ['mac', 'esito', 'idOperazione', 'timeStamp'];
        foreach ($requiredMacFields as $field) {
            if (!isset($decoded[$field])) {
                $msg = 'XpayClient response missing required field: ' . $field;
                $this->_helper->log($msg, Mage::LOG_ERROR);
                Mage::throwException($msg);
            }
        }

        $macKey = $this->_helper->getXpayMacKey();
        $isValidMac = $this->_macHelper->verifyResponseMac(
            $decoded['mac'],
            $decoded['esito'],
            $decoded['idOperazione'],
            $decoded['timeStamp'],
            $macKey,
        );

        if (!$isValidMac) {
            $msg = 'XpayClient: MAC verification failed for ' . $url;
            $this->_helper->log($msg, Mage::LOG_ERROR);
            Mage::throwException($msg);
        }

        return $decoded;
    }

    protected function _sanitizeForLog(array $payload): array
    {
        $sanitized = $payload;
        foreach (['apiKey', 'mac'] as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '***REDACTED***';
            }
        }
        return $sanitized;
    }
}
