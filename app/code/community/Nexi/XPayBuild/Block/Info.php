<?php

declare(strict_types=1);

/**
 * Payment info block for XPay transactions.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Maho\DataObject;

class Nexi_XPayBuild_Block_Info extends Mage_Payment_Block_Info
{
    public function getCardBrand(): string
    {
        return (string) $this->getInfo()->getAdditionalInformation('nexi_brand');
    }

    public function getCardLast4(): string
    {
        $pan = (string) $this->getInfo()->getAdditionalInformation('nexi_pan');
        return strlen($pan) >= 4 ? substr($pan, -4) : '';
    }

    public function getCardExpiry(): string
    {
        $scadenza = (string) $this->getInfo()->getAdditionalInformation('nexi_scadenza');
        if ($scadenza === '') {
            $scadenza = (string) $this->getInfo()->getAdditionalInformation('nexi_scadenzaPan');
        }
        return $scadenza;
    }

    public function getAuthCode(): string
    {
        return (string) $this->getInfo()->getAdditionalInformation('nexi_codAut');
    }

    public function getTransactionId(): string
    {
        return (string) $this->getInfo()->getAdditionalInformation('nexi_cod_trans');
    }

    #[\Override]
    protected function _prepareSpecificInformation($transport = null): DataObject
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();
        $data = [];

        $brand = $this->getCardBrand();
        $last4 = $this->getCardLast4();
        $expiry = $this->getCardExpiry();

        $formattedExpiry = '';
        if ($expiry) {
            $formattedExpiry = Mage::helper('nexi_xpaybuild')->formatExpiry($expiry);
        }

        $isSavedCard = (int) $info->getAdditionalInformation('nexi_saved_card_id') > 0;

        if ($brand || $last4) {
            $cardLine = trim($brand . ($last4 ? ' •••• ' . $last4 : ''));
            if ($formattedExpiry) {
                $cardLine .= '  ' . $formattedExpiry;
            }
            if ($isSavedCard) {
                $cardLine .= ' (' . $this->__('Saved card') . ')';
            }
            $data[(string) $this->__('Card')] = $cardLine;
        }

        if ($txId = $this->getTransactionId()) {
            $data[(string) $this->__('Transaction ID')] = $txId;
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}
