<?php

declare(strict_types=1);

/**
 * Source model for XPay environment selection.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_System_Config_Source_Environment
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('nexi_xpaybuild');
        return [
            ['value' => 'test', 'label' => $helper->__('Test')],
            ['value' => 'production', 'label' => $helper->__('Production')],
        ];
    }
}
