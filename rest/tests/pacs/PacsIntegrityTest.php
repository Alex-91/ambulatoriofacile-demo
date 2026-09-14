<?php
namespace Tests\Pacs;

use App\Services\Pacs\PacsIntegrity;
use CodeIgniter\Test\CIUnitTestCase;

final class PacsIntegrityTest extends CIUnitTestCase
{
    public function testLegacyHashesRemainIdentical(): void
    {
        $config=config(\App\Config\Crypto::class); $old=$config->keyHex;
        try {
            $key=random_bytes(32); $config->keyHex=bin2hex($key);
            $this->assertSame(hash_hmac('sha256','existing snapshot',$key),PacsIntegrity::hash('existing snapshot'));
        } finally { $config->keyHex=$old; }
    }

    public function testCloudKeyIsRequiredAndChangesInvalidateHashes(): void
    {
        $config=config(\App\Config\Crypto::class); $old=$config->keyHex;
        $encryption=config(\Config\Encryption::class); $oldEncryption=$encryption->key;
        $saved=[];
        foreach (['FSE2_SECRET_KEY','database.platform.DB_ENCRYPTION_KEY','database.default.DB_ENCRYPTION_KEY'] as $name) {
            $saved[$name]=getenv($name); putenv($name);
        }
        try {
            $config->keyHex=''; $encryption->key='';
            putenv('FSE2_SECRET_KEY='.bin2hex(random_bytes(32)));
            $first=PacsIntegrity::hash('snapshot');
            $this->assertSame($first,PacsIntegrity::hash('snapshot'));
            $this->assertNotSame($first,PacsIntegrity::hash('changed snapshot'));
            putenv('FSE2_SECRET_KEY='.bin2hex(random_bytes(32)));
            $this->assertNotSame($first,PacsIntegrity::hash('snapshot'));
            putenv('FSE2_SECRET_KEY');
            $this->expectException(\RuntimeException::class);
            PacsIntegrity::hash('snapshot');
        } finally {
            $config->keyHex=$old; $encryption->key=$oldEncryption;
            foreach ($saved as $name=>$value) putenv($value===false ? $name : $name.'='.$value);
        }
    }
}
