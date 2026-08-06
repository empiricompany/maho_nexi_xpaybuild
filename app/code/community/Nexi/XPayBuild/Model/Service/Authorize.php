<?php

declare(strict_types=1);

/**
 * Shared service that performs the XPay nonce authorization API call and
 * persists the response fields + transaction state on the payment info.
 *
 * Used by Nexi_XPayBuild_Model_Payment_NexiPayment::_authorizeXpay()
 * to avoid duplicating the API + response handling logic.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_Service_Authorize
{
    /**
     * Perform the nonce authorization against the XPay API and persist the
     * response fields and transaction state on the given payment info.
     *
     * @param Mage_Payment_Model_Info $payment Payment info to enrich.
     * @return array{response: array, esito: string, numeroContratto: ?string, rawDetails: array}
     */
    public function authorize(
        Mage_Payment_Model_Info $payment,
        string $codTrans,
        int $importo,
        string $divisa,
        string $nonce,
        string $accountingType,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $email = null,
        ?string $incrementId = null,
        bool $createContract = false,
        int $customerId = 0,
        bool $isRecurring = false,
    ): array {
        $helper = Mage::helper('nexi_xpaybuild');

        /** @var Nexi_XPayBuild_Model_Api_XpayClient $client */
        $client = Mage::getModel('nexi_xpaybuild/api_xpayClient');

        if ($isRecurring) {
            $response = $client->pagamentoRicorrente3DS(
                $codTrans, $importo, $divisa, $nonce, $accountingType,
                $firstName, $lastName, $email, $incrementId,
            );
            $numeroContratto = null;
        } elseif ($createContract) {
            $numeroContratto = Mage::helper('nexi_xpaybuild/savedCard')
                ->generateXpayContractNumber($customerId);
            $response = $client->pagaNonceCreazioneContratto(
                $codTrans, $importo, $divisa, $nonce, $numeroContratto,
                $accountingType, $firstName, $lastName, $email, $incrementId,
            );
        } else {
            $response = $client->pagaNonce(
                $codTrans, $importo, $divisa, $nonce, $accountingType,
                $firstName, $lastName, $email, $incrementId,
            );
            $numeroContratto = null;
        }

        $esito = $response['esito'] ?? '';
        if (!in_array($esito, ['OK', 'PEN'], true)) {
            $msg = $response['errore']['messaggio'] ?? $helper->__('Payment failed. Please try again.');
            Mage::throwException($msg);
        }

        $rawDetails = $helper->saveResponseFields($payment, $response, [
            'codAut', 'codiceAutorizzazione', 'brand', 'pan', 'scadenza', 'scadenzaPan',
        ]);

        if ($esito === 'OK' && $accountingType === Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE) {
            $payment->setIsTransactionClosed(true);
            $payment->setIsTransactionPending(false);
        } elseif ($esito === 'PEN') {
            $payment->setIsTransactionPending(true);
            $payment->setIsTransactionClosed(false);
        } else {
            $payment->setIsTransactionClosed(false);
        }

        return [
            'response' => $response,
            'esito' => $esito,
            'numeroContratto' => $numeroContratto,
            'rawDetails' => $rawDetails,
        ];
    }

}
