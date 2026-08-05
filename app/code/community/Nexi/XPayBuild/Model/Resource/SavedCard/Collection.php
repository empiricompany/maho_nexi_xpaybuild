<?php

declare(strict_types=1);

/**
 * Collection model for saved payment cards.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Maho\Data\Collection;

class Nexi_XPayBuild_Model_Resource_SavedCard_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct(): void
    {
        $this->_init('nexi_xpaybuild/savedCard', 'nexi_xpaybuild/savedCard');
    }

    public function addCustomerFilter(int $customerId): static
    {
        $this->addFieldToFilter('customer_id', $customerId);
        $this->addFieldToFilter('is_active', 1);
        return $this;
    }

    public function addGatewayTypeFilter(string $gatewayType): static
    {
        $this->addFieldToFilter('gateway_type', $gatewayType);
        return $this;
    }

    public function setOrderByCreatedAtDesc(): static
    {
        $this->setOrder('created_at', Collection::SORT_ORDER_DESC);
        return $this;
    }
}
