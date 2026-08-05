<?php

declare(strict_types=1);

/**
 * Saved payment card model (one-click / stored credential).
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_SavedCard extends Mage_Core_Model_Abstract
{
    public const GATEWAY_TYPE_XPAY = 'XPAY';

    protected function _construct(): void
    {
        $this->_init('nexi_xpaybuild/savedCard');
    }

    public function getCustomerId(): ?int
    {
        return $this->getData('customer_id') !== null ? (int) $this->getData('customer_id') : null;
    }

    public function getGatewayType(): ?string
    {
        return $this->getData('gateway_type');
    }

    public function getGatewayToken(): ?string
    {
        return $this->getData('gateway_token');
    }

    public function getMaskedPan(): ?string
    {
        return $this->getData('masked_pan');
    }

    public function getBrand(): ?string
    {
        return $this->getData('brand');
    }

    public function getExpiryMonth(): ?int
    {
        return $this->getData('expiry_month') !== null ? (int) $this->getData('expiry_month') : null;
    }

    public function getExpiryYear(): ?int
    {
        return $this->getData('expiry_year') !== null ? (int) $this->getData('expiry_year') : null;
    }

    public function getIsActive(): int
    {
        return (int) $this->getData('is_active');
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData('created_at');
    }

    public function setCustomerId(int $v): static
    {
        return $this->setData('customer_id', $v);
    }

    public function setGatewayType(string $v): static
    {
        return $this->setData('gateway_type', $v);
    }

    public function setGatewayToken(string $v): static
    {
        return $this->setData('gateway_token', $v);
    }

    public function setMaskedPan(?string $v): static
    {
        return $this->setData('masked_pan', $v);
    }

    public function setBrand(?string $v): static
    {
        return $this->setData('brand', $v);
    }

    public function setExpiryMonth(?int $v): static
    {
        return $this->setData('expiry_month', $v);
    }

    public function setExpiryYear(?int $v): static
    {
        return $this->setData('expiry_year', $v);
    }

    public function setIsActive(int $v): static
    {
        return $this->setData('is_active', $v);
    }

    public function deactivate(): static
    {
        $this->setIsActive(0);
        $this->save();
        return $this;
    }

    #[\Override]
    protected function _beforeSave(): static
    {
        parent::_beforeSave();

        if ($this->getCustomerId() <= 0) {
            Mage::throwException(
                Mage::helper('nexi_xpaybuild')->__('SavedCard: customer_id must be a positive integer.'),
            );
        }

        if ($this->getGatewayType() !== self::GATEWAY_TYPE_XPAY) {
            Mage::throwException(
                Mage::helper('nexi_xpaybuild')->__('SavedCard: gateway_type must be XPAY.'),
            );
        }

        if (empty($this->getGatewayToken())) {
            Mage::throwException(
                Mage::helper('nexi_xpaybuild')->__('SavedCard: gateway_token must not be empty.'),
            );
        }

        return $this;
    }
}
