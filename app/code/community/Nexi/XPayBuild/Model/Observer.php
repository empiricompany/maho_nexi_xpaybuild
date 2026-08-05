<?php

declare(strict_types=1);

/**
 * Observer for admin payment configuration changes.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Maho\Event\Observer;

class Nexi_XPayBuild_Model_Observer
{
    #[Maho\Config\Observer('admin_system_config_changed_section_payment', id: 'nexi_xpaybuild_fetch_methods')]
    public function sectionPaymentChanged(Observer $observer): void
    {
        $helper = Mage::helper('nexi_xpaybuild');

        try {
            if ((bool) $helper->getConfig('active')) {
                $this->_fetchXpayAvailableMethods($helper);
            }
        } catch (\Throwable $e) {
            $helper->log('Observer::sectionPaymentChanged error: ' . $e->getMessage(), Mage::LOG_ERROR);
            Mage::getSingleton('adminhtml/session')->addError(
                $helper->__('Nexi XPay Build: %s', $e->getMessage()),
            );
        }

        Mage::app()->getConfig()->reinit();
    }

    protected function _fetchXpayAvailableMethods(Nexi_XPayBuild_Helper_Data $helper): void
    {
        $alias = $helper->getXpayAlias();
        $macKey = $helper->getXpayMacKey();

        if ($alias === '' || $macKey === '') {
            return;
        }

        /** @var Nexi_XPayBuild_Model_Api_XpayClient $client */
        $client = Mage::getModel('nexi_xpaybuild/api_xpayClient');
        $response = $client->profileInfo();

        $urlLogo = $response['urlLogoNexiLarge'] ?? '';
        $availableMethods = $response['availableMethods'] ?? [];

        Mage::getModel('core/config')->saveConfig('payment/nexi_xpaybuild/url_logo', $urlLogo);
        Mage::getModel('core/config')->saveConfig('payment/nexi_xpaybuild/available_methods', json_encode($availableMethods));
    }
}
