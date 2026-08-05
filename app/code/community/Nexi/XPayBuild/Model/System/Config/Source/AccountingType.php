<?php

declare(strict_types=1);

/**
 * Source model for XPay accounting type selection.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_System_Config_Source_AccountingType
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('nexi_xpaybuild');
        return [
            ['value' => Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_IMMEDIATE, 'label' => $helper->__('Immediate')],
            ['value' => Nexi_XPayBuild_Model_Api_XpayClient::XPAY_TCONTAB_DEFERRED, 'label' => $helper->__('Deferred')],
        ];
    }
}
