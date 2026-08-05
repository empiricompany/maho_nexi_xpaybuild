<?php

declare(strict_types=1);

/**
 * Source model for XPay card form style selection.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_System_Config_Source_CardFormStyle
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('nexi_xpaybuild');
        return [
            [
                'value' => Nexi_XPayBuild_Model_Api_XpayClient::XPAY_CARD_FORM_STYLE_SPLIT,
                'label' => $helper->__('Split (3 separate fields: PAN, Expiry, CVV)'),
            ],
            [
                'value' => Nexi_XPayBuild_Model_Api_XpayClient::XPAY_CARD_FORM_STYLE_UNIFIED,
                'label' => $helper->__('Unified (single combined form)'),
            ],
        ];
    }
}
