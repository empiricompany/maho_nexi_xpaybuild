<?php

declare(strict_types=1);

namespace Nexi\XPayBuild\Tests\Model\Api;

use Mage_Core_Exception;
use Nexi_XPayBuild_Helper_Data;
use Nexi_XPayBuild_Helper_Mac;
use Nexi_XPayBuild_Model_Api_XpayClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * XpayClient builds the signed request payload and refuses any response whose
 * MAC does not verify: that verification is the security boundary of the
 * gateway, so it is exercised with a stubbed HTTP client (no network, no Maho
 * bootstrap needed).
 */
final class XpayClientTest extends TestCase
{
    private const string ALIAS = 'ALIAS_WEB_00099262';
    private const string MAC_KEY = 'F8F6MQPMFP1SWF6V6IO9QJD1FPRB0KVW';
    private const string BASE_URL = 'https://int-ecommerce.nexi.it/';

    private Nexi_XPayBuild_Helper_Mac $mac;

    protected function setUp(): void
    {
        $this->mac = new Nexi_XPayBuild_Helper_Mac();
    }

    public function testNoncePayloadContainsTheSignedRequestFields(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient());

        $payload = $this->invoke($client, '_buildNoncePayload', ['T1700000000-123', 1500, '978', 'NONCE-ABC', 'C']);

        self::assertSame(self::ALIAS, $payload['apiKey']);
        self::assertSame('T1700000000-123', $payload['codiceTransazione']);
        self::assertSame(1500, $payload['importo']);
        self::assertSame(978, $payload['divisa']);
        self::assertSame('NONCE-ABC', $payload['xpayNonce']);
        self::assertSame('C', $payload['parametriAggiuntivi']['TCONTAB']);
        self::assertSame('Platform: maho test', $payload['parametriAggiuntivi']['Note2']);
    }

    public function testNoncePayloadMacCoversTheSignedFields(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient());

        $payload = $this->invoke($client, '_buildNoncePayload', ['T1', 1500, '978', 'NONCE', 'C']);

        self::assertSame(
            $this->mac->calculateNonceMac(self::ALIAS, 'T1', 1500, '978', 'NONCE', $payload['timeStamp'], self::MAC_KEY),
            $payload['mac'],
        );
    }

    public function testNoncePayloadOmitsOptionalFieldsWhenNotProvided(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient());

        $payload = $this->invoke($client, '_buildNoncePayload', ['T1', 1500, '978', 'NONCE', 'C']);

        self::assertArrayNotHasKey('numeroContratto', $payload);
        self::assertArrayNotHasKey('nome', $payload['parametriAggiuntivi']);
        self::assertArrayNotHasKey('cognome', $payload['parametriAggiuntivi']);
        self::assertArrayNotHasKey('mail', $payload['parametriAggiuntivi']);
        self::assertArrayNotHasKey('Note1', $payload['parametriAggiuntivi']);
    }

    public function testNoncePayloadAddsCustomerAndOrderDetailsWhenProvided(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient());

        $payload = $this->invoke($client, '_buildNoncePayload', [
            'T1',
            1500,
            '978',
            'NONCE',
            'C',
            'Mario',
            'Rossi',
            'mario@example.com',
            '100000001',
        ]);

        self::assertSame('Mario', $payload['parametriAggiuntivi']['nome']);
        self::assertSame('Rossi', $payload['parametriAggiuntivi']['cognome']);
        self::assertSame('mario@example.com', $payload['parametriAggiuntivi']['mail']);
        self::assertSame('Ordine #100000001', $payload['parametriAggiuntivi']['Note1']);
    }

    public function testNoncePayloadCarriesTheContractNumberForOneClick(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient());

        $payload = $this->invoke($client, '_buildNoncePayload', [
            'T1',
            1500,
            '978',
            'NONCE',
            'C',
            null,
            null,
            null,
            null,
            'C22-1234',
        ]);

        self::assertSame('C22-1234', $payload['numeroContratto']);
    }

    public function testSanitizeForLogRedactsCredentialsButKeepsTheRest(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient());

        $sanitized = $this->invoke($client, '_sanitizeForLog', [[
            'apiKey' => self::ALIAS,
            'mac' => 'abcdef',
            'importo' => 1500,
            'parametriAggiuntivi' => ['nome' => 'Mario'],
        ]]);

        self::assertSame('***REDACTED***', $sanitized['apiKey']);
        self::assertSame('***REDACTED***', $sanitized['mac']);
        self::assertSame(1500, $sanitized['importo']);
        self::assertSame(['nome' => 'Mario'], $sanitized['parametriAggiuntivi']);
    }

    public function testDoPostReturnsTheDecodedResponseWhenTheMacIsValid(): void
    {
        $body = $this->signedResponse('OK', '12345', ['codAut' => 'ABC123']);
        $client = $this->client($this->helper(), new MockHttpClient(new MockResponse($body)));

        $decoded = $this->invoke($client, '_doPost', [Nexi_XPayBuild_Model_Api_XpayClient::XPAY_URI_PAGA_NONCE, ['apiKey' => self::ALIAS]]);

        self::assertSame('OK', $decoded['esito']);
        self::assertSame('ABC123', $decoded['codAut']);
    }

    public function testDoPostRejectsAResponseWhoseMacDoesNotMatch(): void
    {
        $body = $this->rawResponse(['esito' => 'OK', 'idOperazione' => '12345', 'timeStamp' => '1700000000000', 'mac' => str_repeat('a', 40)]);
        $client = $this->client($this->helper(), new MockHttpClient(new MockResponse($body)));

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('MAC verification failed');

        $this->invoke($client, '_doPost', [Nexi_XPayBuild_Model_Api_XpayClient::XPAY_URI_PAGA_NONCE, ['apiKey' => self::ALIAS]]);
    }

    public function testDoPostRejectsAResponseSignedWithAnotherKey(): void
    {
        $mac = sha1('esito=OKidOperazione=12345timeStamp=1700000000000' . 'OTHER_KEY');
        $body = $this->rawResponse(['esito' => 'OK', 'idOperazione' => '12345', 'timeStamp' => '1700000000000', 'mac' => $mac]);
        $client = $this->client($this->helper(), new MockHttpClient(new MockResponse($body)));

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('MAC verification failed');

        $this->invoke($client, '_doPost', [Nexi_XPayBuild_Model_Api_XpayClient::XPAY_URI_PAGA_NONCE, ['apiKey' => self::ALIAS]]);
    }

    public function testDoPostRejectsAResponseMissingTheMac(): void
    {
        $body = $this->rawResponse(['esito' => 'OK', 'idOperazione' => '12345', 'timeStamp' => '1700000000000']);
        $client = $this->client($this->helper(), new MockHttpClient(new MockResponse($body)));

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('missing required field: mac');

        $this->invoke($client, '_doPost', [Nexi_XPayBuild_Model_Api_XpayClient::XPAY_URI_PAGA_NONCE, ['apiKey' => self::ALIAS]]);
    }

    public function testDoPostRejectsAnHttpError(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient(new MockResponse('gateway down', ['http_code' => 500])));

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('HTTP');

        $this->invoke($client, '_doPost', [Nexi_XPayBuild_Model_Api_XpayClient::XPAY_URI_PAGA_NONCE, ['apiKey' => self::ALIAS]]);
    }

    public function testDoPostRejectsANonJsonBody(): void
    {
        $client = $this->client($this->helper(), new MockHttpClient(new MockResponse('<html>not json</html>')));

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('could not decode JSON');

        $this->invoke($client, '_doPost', [Nexi_XPayBuild_Model_Api_XpayClient::XPAY_URI_PAGA_NONCE, ['apiKey' => self::ALIAS]]);
    }

    /**
     * Builds the client without running its constructor (which needs a
     * bootstrapped Maho app) and injects stubbed collaborators instead.
     */
    private function client(Nexi_XPayBuild_Helper_Data $helper, HttpClientInterface $httpClient): Nexi_XPayBuild_Model_Api_XpayClient
    {
        $reflection = new ReflectionClass(Nexi_XPayBuild_Model_Api_XpayClient::class);
        $client = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('_helper')->setValue($client, $helper);
        $reflection->getProperty('_macHelper')->setValue($client, $this->mac);
        $reflection->getProperty('_httpClient')->setValue($client, $httpClient);

        /** @var Nexi_XPayBuild_Model_Api_XpayClient $client */
        return $client;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(object $client, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($client, $method))->invokeArgs($client, $arguments);
    }

    private function helper(): Nexi_XPayBuild_Helper_Data
    {
        return new class extends Nexi_XPayBuild_Helper_Data {
            public function getXpayAlias(?int $storeId = null): string
            {
                return 'ALIAS_WEB_00099262';
            }

            public function getXpayMacKey(?int $storeId = null): string
            {
                return 'F8F6MQPMFP1SWF6V6IO9QJD1FPRB0KVW';
            }

            public function getXpayBaseUrl(?int $storeId = null): string
            {
                return 'https://int-ecommerce.nexi.it/';
            }

            public function getPluginSignature(): string
            {
                return 'Platform: maho test';
            }

            public function log(string $message, \Monolog\Level|int|null $level = null): void
            {
                // Swallow logs: the client must stay silent in tests.
            }
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function signedResponse(string $esito, string $idOperazione, array $extra = []): string
    {
        return $this->rawResponse([
            'esito' => $esito,
            'idOperazione' => $idOperazione,
            'timeStamp' => '1700000000000',
            'mac' => sha1('esito=' . $esito . 'idOperazione=' . $idOperazione . 'timeStamp=1700000000000' . self::MAC_KEY),
            ...$extra,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rawResponse(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
