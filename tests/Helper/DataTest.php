<?php

declare(strict_types=1);

namespace Nexi\XPayBuild\Tests\Helper;

use Nexi_XPayBuild_Helper_Data;
use PHPUnit\Framework\TestCase;

/**
 * Amounts sent to XPay are in the currency's minor unit: a wrong conversion
 * charges the customer the wrong amount, so the mapping is pinned here.
 */
final class DataTest extends TestCase
{
    private Nexi_XPayBuild_Helper_Data $helper;

    protected function setUp(): void
    {
        $this->helper = new Nexi_XPayBuild_Helper_Data();
    }

    public function testGatewayTypeIsXpay(): void
    {
        self::assertSame('XPAY', $this->helper->getGatewayType());
    }

    public function testFormatAmountToMinorUnitUsesTwoDecimalsForEuro(): void
    {
        self::assertSame(1234, $this->helper->formatAmountToMinorUnit(12.34, 'EUR'));
        self::assertSame(1000, $this->helper->formatAmountToMinorUnit('10.00', 'EUR'));
        self::assertSame(1, $this->helper->formatAmountToMinorUnit(0.01, 'EUR'));
        self::assertSame(0, $this->helper->formatAmountToMinorUnit(0, 'EUR'));
    }

    public function testFormatAmountToMinorUnitUsesNoDecimalsForYen(): void
    {
        self::assertSame(1500, $this->helper->formatAmountToMinorUnit(1500, 'JPY'));
        self::assertSame(1500, $this->helper->formatAmountToMinorUnit('1500', 'JPY'));
    }

    public function testFormatAmountToMinorUnitFallsBackToTwoDecimalsForUnknownCurrency(): void
    {
        self::assertSame(150, $this->helper->formatAmountToMinorUnit(1.5, 'XYZ'));
    }

    public function testFormatAmountToMinorUnitRoundsToTheNearestMinorUnit(): void
    {
        self::assertSame(124, $this->helper->formatAmountToMinorUnit(1.239, 'EUR'));
        self::assertSame(123, $this->helper->formatAmountToMinorUnit(1.231, 'EUR'));
    }

    public function testFormatAmountToMinorUnitDefaultsToEuro(): void
    {
        self::assertSame(1000, $this->helper->formatAmountToMinorUnit(10.0));
    }

    public function testGetCurrencyNumericCodeReturnsIsoNumericCodes(): void
    {
        self::assertSame('978', $this->helper->getCurrencyNumericCode('EUR'));
        self::assertSame('840', $this->helper->getCurrencyNumericCode('USD'));
        self::assertSame('826', $this->helper->getCurrencyNumericCode('GBP'));
        self::assertSame('756', $this->helper->getCurrencyNumericCode('CHF'));
        self::assertSame('392', $this->helper->getCurrencyNumericCode('JPY'));
    }

    public function testGetCurrencyNumericCodeFallsBackToEuroForUnknownCurrency(): void
    {
        self::assertSame('978', $this->helper->getCurrencyNumericCode('XYZ'));
    }
}
