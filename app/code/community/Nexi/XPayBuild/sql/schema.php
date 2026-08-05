<?php

declare(strict_types=1);

/**
 * Declarative schema for Nexi XPay Build saved cards table.
 *
 * SPDX-FileCopyrightText: Tony <https://github.com/empiricompany>
 * SPDX-License-Identifier: OSL-3.0
 * @package Nexi_XPayBuild
 */

use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return function (Schema $schema): void {
    $table = $schema->createTable('nexi_saved_cards');

    $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
    $table->addColumn('customer_id', Types::INTEGER, ['unsigned' => true, 'notnull' => true]);
    $table->addColumn('gateway_type', Types::STRING, ['length' => 10, 'notnull' => true]);
    $table->addColumn('gateway_token', Types::STRING, ['length' => 255, 'notnull' => true]);
    $table->addColumn('masked_pan', Types::STRING, ['length' => 30, 'notnull' => false, 'default' => null]);
    $table->addColumn('brand', Types::STRING, ['length' => 50, 'notnull' => false, 'default' => null]);
    $table->addColumn('expiry_month', Types::SMALLINT, ['unsigned' => true, 'notnull' => false, 'default' => null]);
    $table->addColumn('expiry_year', Types::SMALLINT, ['unsigned' => true, 'notnull' => false, 'default' => null]);
    $table->addColumn('is_active', Types::SMALLINT, ['unsigned' => true, 'notnull' => true, 'default' => 1]);
    $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['default' => new CurrentTimestamp()]);

    $table->addPrimaryKeyConstraint(
        PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
    );

    $table->addIndex(['customer_id', 'is_active']);
    $table->addIndex(['gateway_token']);
    $table->addUniqueIndex(['customer_id', 'gateway_token']);

    $table->addForeignKeyConstraint(
        'customer_entity',
        ['customer_id'],
        ['entity_id'],
        ['onDelete' => 'CASCADE', 'onUpdate' => 'CASCADE'],
    );

    $table->setComment('Nexi XPay Build - Saved Payment Cards (tokens only)');
};
