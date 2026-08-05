<?php

declare(strict_types=1);

/**
 * MAC calculation helper for XPay API calls.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Helper_Mac extends Mage_Core_Helper_Abstract
{
    public function calculateInitMac(string $codTrans, string $divisa, int $importo, string $macKey): string
    {
        return sha1('codTrans=' . $codTrans . 'divisa=' . $divisa . 'importo=' . $importo . $macKey);
    }

    public function calculateNonceMac(
        string $apiKey,
        string $codTrans,
        int $importo,
        string $divisa,
        string $xpayNonce,
        string $timeStamp,
        string $macKey,
    ): string {
        return sha1(
            'apiKey=' . $apiKey .
            'codiceTransazione=' . $codTrans .
            'importo=' . $importo .
            'divisa=' . $divisa .
            'xpayNonce=' . $xpayNonce .
            'timeStamp=' . $timeStamp .
            $macKey,
        );
    }

    public function verifyResponseMac(
        string $receivedMac,
        string $esito,
        string $idOperazione,
        string $timeStamp,
        string $macKey,
    ): bool {
        $calculatedMac = sha1(
            'esito=' . $esito .
            'idOperazione=' . $idOperazione .
            'timeStamp=' . $timeStamp .
            $macKey,
        );
        return hash_equals($calculatedMac, $receivedMac);
    }

    public function calculateAccountingMac(
        string $apiKey,
        string $codiceTransazione,
        int $importo,
        string $divisa,
        string $timeStamp,
        string $macKey,
    ): string {
        return sha1(
            'apiKey=' . $apiKey .
            'codiceTransazione=' . $codiceTransazione .
            'divisa=' . $divisa .
            'importo=' . $importo .
            'timeStamp=' . $timeStamp .
            $macKey,
        );
    }

    public function calculateOrderDetailMac(
        string $apiKey,
        string $codiceTransazione,
        string $timeStamp,
        string $macKey,
    ): string {
        return sha1(
            'apiKey=' . $apiKey .
            'codiceTransazione=' . $codiceTransazione .
            'timeStamp=' . $timeStamp .
            $macKey,
        );
    }

    public function calculateProfileInfoMac(string $apiKey, string $timeStamp, string $macKey): string
    {
        return sha1('apiKey=' . $apiKey . 'timeStamp=' . $timeStamp . $macKey);
    }
}
