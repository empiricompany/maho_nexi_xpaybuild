<?php

declare(strict_types=1);

/**
 * Abstract form block for XPay payment methods.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

abstract class Nexi_XPayBuild_Block_Form_Abstract extends Mage_Payment_Block_Form
{
    protected function _getHelper(): Nexi_XPayBuild_Helper_Data
    {
        return Mage::helper('nexi_xpaybuild');
    }

    public function isCustomerLoggedIn(): bool
    {
        return Mage::getSingleton('customer/session')->isLoggedIn();
    }

    public function getNexiLogoUrl(): string
    {
        return Mage::getDesign()->getSkinUrl('nexi/xpaybuild/images/nexi-logo.png');
    }

    public function isOneclickEnabled(): bool
    {
        if (!$this->isCustomerLoggedIn()) {
            return false;
        }
        return $this->_getHelper()->getOneclickEnabled();
    }

    /**
     * @return Nexi_XPayBuild_Model_SavedCard[]
     */
    public function getSavedCards(): array
    {
        if (!$this->isOneclickEnabled()) {
            return [];
        }
        $customerId = (int) Mage::getSingleton('customer/session')->getCustomerId();
        return Mage::helper('nexi_xpaybuild/savedCard')->getActiveCards($customerId);
    }

    /**
     * @return array<int, array{code: string, image: string, alt: string}>
     */
    public function getCardBrandLogos(): array
    {
        $json = Mage::getStoreConfig('payment/nexi_xpaybuild/available_methods');
        if ($json) {
            $methods = json_decode($json, true);
            if (is_array($methods)) {
                $logos = [];
                foreach ($methods as $method) {
                    if (($method['type'] ?? '') !== 'CC') {
                        continue;
                    }
                    $logos[] = [
                        'code' => $method['code'],
                        'image' => $method['image'] ?? ($method['pngImage'] ?? ''),
                        'alt' => $method['description'] ?? $method['code'],
                    ];
                }
                if (!empty($logos)) {
                    return $logos;
                }
            }
        }

        return [
            ['code' => 'VISA', 'image' => Mage::getDesign()->getSkinUrl('nexi/xpaybuild/images/visa.png'), 'alt' => 'Visa'],
            ['code' => 'MASTERCARD', 'image' => Mage::getDesign()->getSkinUrl('nexi/xpaybuild/images/mastercard.png'), 'alt' => 'Mastercard'],
            ['code' => 'MAESTRO', 'image' => Mage::getDesign()->getSkinUrl('nexi/xpaybuild/images/maestro.png'), 'alt' => 'Maestro'],
        ];
    }
}
