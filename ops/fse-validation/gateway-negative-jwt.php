<?php
// Fault injection is confined to the CLI test harness. Never included by the application.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
final class FseNegativeTestJwt extends \App\Services\FseJwtService
{
    public function __construct(private \App\Config\Fse2 $testConfig, private string $fault)
    {
        if (!in_array($fault, ['jwt-missing-purpose','jwt-action-invalid'],true)) throw new \RuntimeException('Fault not allowed.');
        parent::__construct($testConfig);
    }

    public function createTokens(array $profile, array $document, string $filePath, string $action = 'CREATE'): array
    {
        if (($profile['environment'] ?? '') !== 'test' || ($profile['access_mode'] ?? '') !== 'gateway'
            || ($profile['gateway_base_url'] ?? '') !== 'https://modipa-val.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1') {
            throw new \RuntimeException('Negative JWT restricted to the national test endpoint.');
        }
        $tokens=parent::createTokens($profile,$document,$filePath,$action);
        $claims=$tokens['claims']['signature'];
        if ($this->fault==='jwt-missing-purpose') unset($claims['purpose_of_use']); else $claims['action_id']='TEST';
        $header=json_decode(base64_decode(strtr(explode('.',$tokens['signature'])[0],'-_','+/'),true),true,512,JSON_THROW_ON_ERROR);
        $key=file_get_contents($this->testConfig->resolveCertificatePath($profile['signature_private_key_path']));
        $tokens['signature']=$this->encode($header,$claims,$key);
        $tokens['claims']['signature']=$claims;
        return $tokens;
    }
}
