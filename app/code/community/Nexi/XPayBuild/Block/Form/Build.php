<?php

declare(strict_types=1);

/**
 * Build form block for XPay embedded card payments.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Block_Form_Build extends Nexi_XPayBuild_Block_Form_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('nexi/xpaybuild/checkout/form.phtml');
    }

    #[\Override]
    public function getMethodCode(): string
    {
        return 'nexi_xpaybuild';
    }

    public function getSdkUrl(): string
    {
        return $this->_getHelper()->getXpaySdkUrl();
    }

    public function getCardFormStyle(): string
    {
        return $this->_getHelper()->getCardFormStyle();
    }

    public function getEnvironment(): string
    {
        return $this->_getHelper()->getXpayEnvironment();
    }

    public function getPaymentDataUrl(): string
    {
        return Mage::getUrl('nexixpaybuild/checkout/getPaymentData', ['_secure' => true]);
    }

    public function getPlaceOrderUrl(): string
    {
        return Mage::getUrl('nexixpaybuild/checkout/placeOrder', ['_secure' => true]);
    }
}
