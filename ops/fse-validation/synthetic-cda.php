<?php
// No framework boot, .env, database, request input or credential configuration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../rest/system/Config/BaseConfig.php';
require __DIR__ . '/../../rest/app/Config/Fse2.php';
require __DIR__ . '/../../rest/app/Services/FseCdaRsaBuilderService.php';
echo (new \App\Services\FseCdaRsaBuilderService())->build(require __DIR__ . '/../../rest/tests/_support/fse_synthetic.php');
