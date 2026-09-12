<?php
namespace Tests\Unit;

use App\Services\FseValidatorEnvironment;
use CodeIgniter\Test\CIUnitTestCase;

final class FseValidatorEnvironmentTest extends CIUnitTestCase
{
    public function testOnlySystemPathsAreInheritedWithWindowsCaseNormalization(): void
    {
        $result = FseValidatorEnvironment::build(['Path'=>'C:\\synthetic\\bin', 'systemroot'=>'C:\\Windows',
            'TMP'=>'C:\\synthetic\\tmp', 'LANG'=>'UNTRUSTED', 'TZ'=>'UNTRUSTED',
            'DB_PASSWORD'=>'SYNTHETIC_SECRET', 'FSE2_SECRET_KEY'=>'SYNTHETIC_SECRET',
            'SMTP_PASSWORD'=>'SYNTHETIC_SECRET', 'UNEXPECTED_NEW_TOKEN'=>'SYNTHETIC_SECRET']);
        $this->assertSame(['LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8','TZ'=>'UTC',
            'PATH'=>'C:\\synthetic\\bin','SystemRoot'=>'C:\\Windows','TMP'=>'C:\\synthetic\\tmp'], $result);
        $this->assertStringNotContainsString('SYNTHETIC_SECRET', json_encode($result));
    }

    public function testInterpreterInjectionAndProxyVariablesAreExcluded(): void
    {
        $parent = array_fill_keys(['JAVA_TOOL_OPTIONS','JDK_JAVA_OPTIONS','_JAVA_OPTIONS','CLASSPATH',
            'PYTHONPATH','PYTHONHOME','LD_PRELOAD','LD_LIBRARY_PATH','DYLD_INSERT_LIBRARIES',
            'HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','PHP_INI_SCAN_DIR','BASH_ENV','ENV'], 'SYNTHETIC');
        $this->assertSame(['LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8','TZ'=>'UTC'], FseValidatorEnvironment::build($parent));
    }

    public function testMalformedSystemValuesAreOmitted(): void
    {
        $this->assertSame(['LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8','TZ'=>'UTC'],
            FseValidatorEnvironment::build(['PATH'=>"bad\0path", 'TEMP'=>['not-string'],
                'TMP'=>'', 'WINDIR'=>str_repeat('a',32768)]));
    }

    public function testRealChildCannotSeeParentCanaryAndParentIsUnchanged(): void
    {
        // Invented values only; neither real environment contents nor credentials are printed.
        $name = 'FSE_TEST_PRIVATE_CANARY';
        $before = getenv($name);
        putenv($name.'=SYNTHETIC_DO_NOT_INHERIT');
        try {
            $code = 'echo json_encode(["secret_absent"=>getenv("FSE_TEST_PRIVATE_CANARY")===false,"locale"=>getenv("LANG")]);';
            $process = proc_open([PHP_BINARY,'-n','-r',$code], [1=>['pipe','w'],2=>['pipe','w']],
                $pipes, null, FseValidatorEnvironment::build(), ['bypass_shell'=>true]);
            $this->assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame(['secret_absent'=>true,'locale'=>'C.UTF-8'], json_decode($out,true));
            $this->assertSame('SYNTHETIC_DO_NOT_INHERIT', getenv($name));
        } finally { putenv($before === false ? $name : $name.'='.$before); }
    }
}
