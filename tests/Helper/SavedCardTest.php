<?php

declare(strict_types=1);

namespace Nexi\XPayBuild\Tests\Helper;

use Nexi_XPayBuild_Helper_SavedCard;
use PHPUnit\Framework\TestCase;

/**
 * The saved-card helper builds the XPay contract number (the OneClick token)
 * and maps card brands to their icons.
 */
final class SavedCardTest extends TestCase
{
    private Nexi_XPayBuild_Helper_SavedCard $helper;

    protected function setUp(): void
    {
        $this->helper = new Nexi_XPayBuild_Helper_SavedCard();
    }

    public function testContractNumberIsPrefixedWithTheCustomerId(): void
    {
        self::assertMatchesRegularExpression('/^C22-\d{4}$/', $this->helper->generateXpayContractNumber(22));
    }

    public function testContractNumberKeepsTheCustomerIdForTypicalIds(): void
    {
        self::assertStringStartsWith('C1234567-', $this->helper->generateXpayContractNumber(1234567));
    }

    public function testContractNumberNeverExceedsThirtyCharacters(): void
    {
        self::assertLessThanOrEqual(30, strlen($this->helper->generateXpayContractNumber(PHP_INT_MAX)));
    }

    public function testCardBrandIconMapsKnownBrandsCaseInsensitively(): void
    {
        self::assertSame('nexi/xpaybuild/images/visa.png', $this->helper->getCardBrandIcon('VISA'));
        self::assertSame('nexi/xpaybuild/images/visa.png', $this->helper->getCardBrandIcon('visa'));
        self::assertSame('nexi/xpaybuild/images/mastercard.png', $this->helper->getCardBrandIcon('mastercard'));
        self::assertSame('nexi/xpaybuild/images/amex.png', $this->helper->getCardBrandIcon('AMEX'));
        self::assertSame('nexi/xpaybuild/images/maestro.png', $this->helper->getCardBrandIcon('Maestro'));
        self::assertSame('nexi/xpaybuild/images/diners.png', $this->helper->getCardBrandIcon('DINERS'));
    }

    public function testCardBrandIconFallsBackToTheGenericIconForUnknownBrands(): void
    {
        self::assertSame('nexi/xpaybuild/images/credit_card.png', $this->helper->getCardBrandIcon('DISCOVER'));
        self::assertSame('nexi/xpaybuild/images/credit_card.png', $this->helper->getCardBrandIcon(''));
    }
}
