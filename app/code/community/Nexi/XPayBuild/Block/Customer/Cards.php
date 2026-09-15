<?php

declare(strict_types=1);

/**
 * Customer saved cards list block.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Block_Customer_Cards extends Mage_Core_Block_Template
{
    /**
     * @return Nexi_XPayBuild_Model_SavedCard[]
     */
    public function getSavedCards(): array
    {
        if (!$this->hasData('saved_cards')) {
            $customerId = Mage::getSingleton('customer/session')->getCustomerId();
            $cards = $customerId
                ? Mage::helper('nexi_xpaybuild/savedCard')->getActiveCards((int) $customerId)
                : [];
            $this->setData('saved_cards', $cards);
        }
        return $this->getData('saved_cards');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('nexixpaybuild/account/delete');
    }

    #[\Override]
    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }

    public function getPageTitle(): string
    {
        return $this->__('My Payment Cards');
    }
}
