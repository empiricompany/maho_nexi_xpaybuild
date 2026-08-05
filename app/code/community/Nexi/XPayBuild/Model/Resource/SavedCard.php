<?php

declare(strict_types=1);

/**
 * Resource model for saved payment cards.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

class Nexi_XPayBuild_Model_Resource_SavedCard extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('nexi_xpaybuild/saved_card', 'id');
    }
}
