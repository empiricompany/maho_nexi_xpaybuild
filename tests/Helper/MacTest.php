<?php

declare(strict_types=1);

namespace Nexi\XPayBuild\Tests\Helper;

use Nexi_XPayBuild_Helper_Mac;
use PHPUnit\Framework\TestCase;

/**
 * The MAC is what proves a request/response actually comes from XPay and was
 * not tampered with. These tests pin the exact signed string of every endpoint
 * and the outcome of the verification.
 */
final class MacTest extends TestCase
{
    private const string MAC_KEY = 'F8F6MQPMFP1SWF6V6IO9QJD1FPRB0KVW';

    private Nexi_XPayBuild_Helper_Mac $mac;

    protected function setUp(): void
    {
        $this->mac = new Nexi_XPayBuild_Helper_Mac();
    }

    public function testCalculateInitMacSignsCodTransDivisaImportoAndKey(): void
    {
        self::assertSame(
            sha1('codTrans=T123divisa=978importo=1500' . self::MAC_KEY),
            $this->mac->calculateInitMac('T123', '978', 1500, self::MAC_KEY),
        );
    }

    public function testCalculateNonceMacSignsEveryFieldInOrder(): void
    {
        self::assertSame(
            sha1('apiKey=ALIAScodiceTransazione=T1importo=1500divisa=978xpayNonce=NONCEtimeStamp=1700000000000' . self::MAC_KEY),
            $this->mac->calculateNonceMac('ALIAS', 'T1', 1500, '978', 'NONCE', '1700000000000', self::MAC_KEY),
        );
    }

    public function testCalculateAccountingMacSignsEveryFieldInOrder(): void
    {
        self::assertSame(
            sha1('apiKey=ALIAScodiceTransazione=T1divisa=978importo=1500timeStamp=1700000000000' . self::MAC_KEY),
            $this->mac->calculateAccountingMac('ALIAS', 'T1', 1500, '978', '1700000000000', self::MAC_KEY),
        );
    }

    public function testCalculateOrderDetailMacSignsEveryFieldInOrder(): void
    {
        self::assertSame(
            sha1('apiKey=ALIAScodiceTransazione=T1timeStamp=1700000000000' . self::MAC_KEY),
            $this->mac->calculateOrderDetailMac('ALIAS', 'T1', '1700000000000', self::MAC_KEY),
        );
    }

    public function testCalculateProfileInfoMacSignsApiKeyAndTimeStamp(): void
    {
        self::assertSame(
            sha1('apiKey=ALIAStimeStamp=1700000000000' . self::MAC_KEY),
            $this->mac->calculateProfileInfoMac('ALIAS', '1700000000000', self::MAC_KEY),
        );
    }

    public function testMacsAreFortyCharacterHexDigests(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->mac->calculateInitMac('T1', '978', 1, self::MAC_KEY));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->mac->calculateProfileInfoMac('ALIAS', '1', self::MAC_KEY));
    }

    public function testNonceMacChangesWhenAnySignedFieldChanges(): void
    {
        $base = $this->mac->calculateNonceMac('ALIAS', 'T1', 1500, '978', 'NONCE', '1700000000000', self::MAC_KEY);

        self::assertNotSame($base, $this->mac->calculateNonceMac('OTHER', 'T1', 1500, '978', 'NONCE', '1700000000000', self::MAC_KEY));
        self::assertNotSame($base, $this->mac->calculateNonceMac('ALIAS', 'T2', 1500, '978', 'NONCE', '1700000000000', self::MAC_KEY));
        self::assertNotSame($base, $this->mac->calculateNonceMac('ALIAS', 'T1', 1501, '978', 'NONCE', '1700000000000', self::MAC_KEY));
        self::assertNotSame($base, $this->mac->calculateNonceMac('ALIAS', 'T1', 1500, '840', 'NONCE', '1700000000000', self::MAC_KEY));
        self::assertNotSame($base, $this->mac->calculateNonceMac('ALIAS', 'T1', 1500, '978', 'OTHER', '1700000000000', self::MAC_KEY));
        self::assertNotSame($base, $this->mac->calculateNonceMac('ALIAS', 'T1', 1500, '978', 'NONCE', '1700000000001', self::MAC_KEY));
        self::assertNotSame($base, $this->mac->calculateNonceMac('ALIAS', 'T1', 1500, '978', 'NONCE', '1700000000000', 'OTHER_KEY'));
    }

    public function testVerifyResponseMacAcceptsTheMatchingDigest(): void
    {
        $mac = sha1('esito=OKidOperazione=12345timeStamp=1700000000000' . self::MAC_KEY);

        self::assertTrue($this->mac->verifyResponseMac($mac, 'OK', '12345', '1700000000000', self::MAC_KEY));
    }

    public function testVerifyResponseMacRejectsATamperedEsito(): void
    {
        $mac = sha1('esito=OKidOperazione=12345timeStamp=1700000000000' . self::MAC_KEY);

        self::assertFalse($this->mac->verifyResponseMac($mac, 'KO', '12345', '1700000000000', self::MAC_KEY));
    }

    public function testVerifyResponseMacRejectsATamperedIdOperazione(): void
    {
        $mac = sha1('esito=OKidOperazione=12345timeStamp=1700000000000' . self::MAC_KEY);

        self::assertFalse($this->mac->verifyResponseMac($mac, 'OK', '99999', '1700000000000', self::MAC_KEY));
    }

    public function testVerifyResponseMacRejectsADigestSignedWithAnotherKey(): void
    {
        $mac = sha1('esito=OKidOperazione=12345timeStamp=1700000000000' . 'OTHER_KEY');

        self::assertFalse($this->mac->verifyResponseMac($mac, 'OK', '12345', '1700000000000', self::MAC_KEY));
    }

    public function testVerifyResponseMacRejectsAWrongDigestOfTheSameLength(): void
    {
        self::assertFalse(
            $this->mac->verifyResponseMac(str_repeat('a', 40), 'OK', '12345', '1700000000000', self::MAC_KEY),
        );
    }
}
