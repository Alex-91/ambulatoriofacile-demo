<?php

namespace Tests\Unit;

use App\Config\Fse2;
use App\Services\FseGatewayClient;
use App\Services\FseGatewayResponse;
use App\Services\FseGatewayTransport;
use App\Services\FseJwtService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseGatewayPreparationTest extends CIUnitTestCase
{
    public function testToscanaHasSeparateStageAndProductionEndpoints(): void
    {
        $config = new Fse2();
        $this->assertSame('https://fse20gwstage.regione.toscana.it/gateway/v2', $config->gatewayUrlForProfile(['access_mode' => 'toscana_privati']));
        $this->assertSame('https://fse20gw.regione.toscana.it/gateway/v2', $config->gatewayUrlForProfile(['access_mode' => 'toscana_privati', 'environment' => 'production']));
        $this->assertSame($config->gatewayUrl('test'), $config->gatewayUrlForProfile([]));
        $this->assertSame('confirmed-audience', $config->jwtAudienceForProfile(['access_mode' => 'toscana_privati', 'jwt_audience' => 'confirmed-audience']));
        $this->assertSame('confirmed-metadata', $config->jwtAudienceForProfile(['access_mode' => 'toscana_privati', 'metadata_json' => '{"jwt_audience":"confirmed-metadata"}']));
    }

    public function testToscanaDoesNotGuessJwtAudienceFromTransportUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Fse2())->jwtAudienceForProfile(['access_mode' => 'toscana_privati']);
    }

    public static function invalidProfiles(): array
    {
        return [
            [['access_mode' => 'toscana_privati', 'gateway_base_url' => 'https://fse20gw.regione.toscana.it/gateway/v2']],
            [['gateway_base_url' => 'https://fse20gwstage.regione.toscana.it/gateway/v2']],
            [['gateway_base_url' => 'https://modipa.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1']],
            [['gateway_base_url' => 'http://example.test']],
            [['gateway_base_url' => 'https://user:password@example.test']],
            [['gateway_base_url' => 'https://example.test?token=secret']],
            [['access_mode' => 'regional']],
            [['access_mode' => 'unknown']],
            [['environment' => 'unknown']],
        ];
    }

    #[DataProvider('invalidProfiles')]
    public function testRejectsAmbiguousOrUnsafeEndpoint(array $profile): void
    {
        $this->expectException(\RuntimeException::class);
        (new Fse2())->gatewayUrlForProfile($profile);
    }

    public function testCartHeaderIsKeptOnRejectionWithoutCookiesOrAuthorization(): void
    {
        $response = new FseGatewayResponse();
        $response->captureHeader("HTTP/1.1 100 Continue\r\n");
        $response->captureHeader("X-CART-id: interim\r\n");
        $response->captureHeader("HTTP/2 400\r\n");
        $line = "x-CaRt-ID: transaction-test-42\r\n";
        $this->assertSame(strlen($line), $response->captureHeader($line));
        $response->captureHeader("Authorization: secret-token\r\n");
        $response->captureHeader("Set-Cookie: secret-cookie\r\n");
        $result = $response->result('{"detail":"Invalid test document"}', 400);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['outcome_uncertain']);
        $this->assertSame('transaction-test-42', $result['x_cart_id']);
        $this->assertSame(['x-cart-id' => 'transaction-test-42'], $result['response_headers']);
    }

    public static function uncertainResults(): array
    {
        return [[false, 0, 28], [false, 200, 18], ['<html>proxy error</html>', 200, 0], ['', 204, 0], ['{}', 408, 0], ['{}', 504, 0]];
    }

    #[DataProvider('uncertainResults')]
    public function testUncertainResponsesNeverBecomeSuccess(string|false $body, int $status, int $curlError): void
    {
        $response = new FseGatewayResponse();
        $response->captureHeader("X-CART-id: diagnostic-id\r\n");
        $result = $response->result($body, $status, $curlError);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['outcome_uncertain']);
        $this->assertSame('diagnostic-id', $result['x_cart_id']);
        $this->assertArrayNotHasKey('raw', $result['payload']);
    }

    public static function toscanaResponses(): array { return [[202,false],[400,false],[408,true],[502,true]]; }

    #[DataProvider('toscanaResponses')]
    public function testFourToscanaOperationsUseExpectedVerbsBodiesAndActionsWithoutNetwork(int $http, bool $uncertain): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fse-contract-');
        file_put_contents($file, '%PDF-synthetic-offline-test');
        try {
            $config = new Fse2();
            $config->allowAbsoluteCertificatePaths = true;
            $config->allowToscanaStage = true;
            $config->gatewayCaBundle = $file;
            $profile = [
                'access_mode' => 'toscana_privati', 'environment' => 'test',
                'auth_certificate_path' => $file, 'auth_private_key_path' => $file,
                'facility_type' => 'Territorio', 'organizational_setting' => 'AD_PSC001',
                'document_oid_root' => '1.2.3', 'submission_oid_root' => '1.2.4', 'repository_id' => '1.2.5',
            ];
            $document = ['service_start' => '2026-09-07 10:00:00', 'submission_id' => 'TEST.SUB.2',
                'document_unique_id' => 'TEST.2', 'administrative_request' => 'NOSSN', 'access_rules_json' => '["TEST-RULE"]'];
            $actions = [];
            $jwt = $this->createMock(FseJwtService::class);
            $jwt->expects($this->exactly(4))->method('createTokens')->willReturnCallback(
                static function ($p, $d, $f, $action) use (&$actions): array {
                    $actions[] = $action;
                    return ['authorization' => 'fake-auth', 'signature' => 'fake-sign'];
                }
            );
            $calls = [];
            $transport = $this->createMock(FseGatewayTransport::class);
            $transport->expects($this->exactly(4))->method('send')->willReturnCallback(
                static function ($url, $options) use (&$calls, $http): array {
                    $calls[] = [$url, $options];
                    $response=new FseGatewayResponse();
                    $response->captureHeader('X-CART-id: synthetic-cart-' . count($calls));
                    return $response->result('{"workflowInstanceId":"test-wif","detail":"PRIVATE"}', $http);
                }
            );
            $gateway = new FseGatewayClient($jwt, $config, $transport);
            $results=[];
            $results[]=$gateway->validateAndCreate($profile, $document, $file);
            $results[]=$gateway->replace($profile, $document, $file, '1.2.3^TEST.1');
            $results[]=$gateway->updateMetadata($profile, $document, $file);
            $results[]=$gateway->delete($profile, $document, $file);
            foreach ($results as $index=>$result) {
                $this->assertSame($http===202,$result['ok']);
                $this->assertSame($uncertain,$result['outcome_uncertain']);
                $this->assertSame('synthetic-cart-' . ($index+1),$result['x_cart_id']);
                $this->assertStringNotContainsString('PRIVATE',json_encode($result));
            }
            $this->assertSame(['CREATE', 'UPDATE', 'UPDATE', 'DELETE'], $actions);
            $this->assertSame(['POST', 'PUT', 'PUT', 'DELETE'], array_map(static fn($call) => $call[1][CURLOPT_CUSTOMREQUEST], $calls));
            $this->assertStringEndsWith('/documents/validate-and-create', $calls[0][0]);
            $this->assertStringEndsWith('/documents/1.2.3%5ETEST.1', $calls[1][0]);
            $this->assertStringEndsWith('/documents/1.2.3%5ETEST.2/metadata', $calls[2][0]);
            $this->assertStringEndsWith('/documents/1.2.3%5ETEST.2', $calls[3][0]);
            $this->assertInstanceOf(\CURLFile::class, $calls[0][1][CURLOPT_POSTFIELDS]['file']);
            $requestPart = $calls[1][1][CURLOPT_POSTFIELDS]['requestBody'];
            $body = json_decode($requestPart instanceof \CURLStringFile ? $requestPart->data : $requestPart, true);
            $this->assertSame('1.2.3^TEST.2', $body['identificativoDoc']);
            $this->assertSame(['TEST-RULE'], $body['attiCliniciRegoleAccesso']);
            $metadata = json_decode($calls[2][1][CURLOPT_POSTFIELDS], true);
            $this->assertSame('1.2.4^TEST.SUB.2', $metadata['identificativoSottomissione']);
            $this->assertArrayNotHasKey('identificativoDoc', $metadata);
            $this->assertArrayNotHasKey('mode', $metadata);
            $this->assertContains('Content-Type: application/json', $calls[2][1][CURLOPT_HTTPHEADER]);
            $this->assertTrue($calls[0][1][CURLOPT_SSL_VERIFYPEER]);
            $this->assertSame($file, $calls[0][1][CURLOPT_CAINFO]);
            $this->assertSame(2, $calls[0][1][CURLOPT_SSL_VERIFYHOST]);
        } finally {
            unlink($file);
        }
    }

    public static function regionalOperations(): array { return [['validateAndCreate'],['replace'],['updateMetadata'],['delete']]; }

    #[DataProvider('regionalOperations')]
    public function testToscanaStageIsDisabledBeforeReadingCertificates(string $method): void
    {
        $config = new Fse2(); $config->allowToscanaStage = false;
        $transport = $this->createMock(FseGatewayTransport::class);
        $transport->expects($this->never())->method('send');
        $this->expectExceptionMessage('FSE2_ALLOW_TOSCANA_STAGE');
        (new FseGatewayClient(null, $config, $transport))->$method(['access_mode' => 'toscana_privati', 'document_oid_root' => '1.2.3',
            'facility_type'=>'Territorio','organizational_setting'=>'AD_PSC001','submission_oid_root'=>'1.2.4','repository_id'=>'1.2.5'],
            ['document_unique_id' => 'TEST','service_start'=>'2026-09-09 10:00:00','submission_id'=>'TEST.SUB','administrative_request'=>'NOSSN'], __FILE__, '1.2.3^PREVIOUS');
    }

    public function testMissingServerCaBundleFailsBeforeSigningOrSending(): void
    {
        $config = new Fse2();
        $config->gatewayCaBundle = __DIR__ . '/missing-ca-bundle.pem';
        $jwt = $this->createMock(FseJwtService::class);
        $jwt->expects($this->never())->method('createTokens');
        $transport = $this->createMock(FseGatewayTransport::class);
        $transport->expects($this->never())->method('send');
        $this->expectExceptionMessage('verifica TLS obbligatoria');
        (new FseGatewayClient($jwt, $config, $transport))->validate([], [], __FILE__, 'VERIFICA');
    }

    public function testNationalValidationUsesJsonFormFieldNotUploadedJsonFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fse-national-contract-');
        file_put_contents($file, '%PDF-synthetic-contract-test');
        try {
            $config = new Fse2();
            $config->allowAbsoluteCertificatePaths = true;
            $config->gatewayCaBundle = '';
            $jwt = $this->createMock(FseJwtService::class);
            $jwt->expects($this->once())->method('createTokens')->willReturn(['authorization' => 'fake-auth', 'signature' => 'fake-sign']);
            $transport = $this->createMock(FseGatewayTransport::class);
            $transport->expects($this->once())->method('send')->willReturnCallback(function ($url, $options): array {
                $this->assertStringEndsWith('/documents/validation', $url);
                $this->assertIsString($options[CURLOPT_POSTFIELDS]['requestBody']);
                $body = json_decode($options[CURLOPT_POSTFIELDS]['requestBody'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('VERIFICA', $body['activity']);
                $this->assertSame('CDA', $body['healthDataFormat']);
                $this->assertSame('ATTACHMENT', $body['mode']);
                $this->assertInstanceOf(\CURLFile::class, $options[CURLOPT_POSTFIELDS]['file']);
                $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
                $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
                $this->assertArrayNotHasKey(CURLOPT_CAINFO, $options);
                return (new FseGatewayResponse())->result('{"workflowInstanceId":"test-only"}', 200);
            });
            (new FseGatewayClient($jwt, $config, $transport))->validate([
                'auth_certificate_path' => $file, 'auth_private_key_path' => $file,
            ], [], $file, 'VERIFICA');
        } finally { unlink($file); }
    }

    public function testUnsupportedValidationActivityFailsBeforeSigningOrSending(): void
    {
        $jwt = $this->createMock(FseJwtService::class);
        $jwt->expects($this->never())->method('createTokens');
        $transport = $this->createMock(FseGatewayTransport::class);
        $transport->expects($this->never())->method('send');
        $this->expectException(\RuntimeException::class);
        (new FseGatewayClient($jwt, new Fse2(), $transport))->validate([], [], __FILE__, 'CREATE');
    }

    public function testToscanaProductionRemainsBlockedEvenWithGenericProductionFlag(): void
    {
        $config = new Fse2(); $config->allowProduction = true; $config->allowToscanaStage = true;
        $this->expectExceptionMessage('Toscana produzione non ancora disponibile');
        (new FseGatewayClient(null, $config))->delete(['access_mode' => 'toscana_privati', 'environment' => 'production', 'document_oid_root' => '1.2.3'], ['document_unique_id' => 'TEST'], 'unused');
    }

    public function testReplacementCannotReuseOriginalIdentifier(): void
    {
        $this->expectExceptionMessage('nuovo identificativo');
        (new FseGatewayClient())->replace(['document_oid_root' => '1.2.3'], ['document_unique_id' => 'TEST'], 'unused', '1.2.3^TEST');
    }

    public function testToscanaRejectsSeparateNationalValidation(): void
    {
        $this->expectExceptionMessage('quattro metodi regionali');
        (new FseGatewayClient())->validate(['access_mode' => 'toscana_privati'], [], 'unused');
    }
}
