<?php
namespace Tests\Unit;

use App\Services\FseValidationJobs;
use CodeIgniter\Test\CIUnitTestCase;

final class FseValidationJobsTest extends CIUnitTestCase
{
    private string $root;
    private FseValidationJobs $jobs;
    public function testMemoryAdmissionUsesBothHostAndContainerHeadroom(): void
    {
        $this->assertSame(1024.0, FseValidationJobs::availableMemoryMiB("MemAvailable: 1048576 kB\n", 'max', '100'));
        $this->assertSame(512.0, FseValidationJobs::availableMemoryMiB("MemAvailable: 1048576 kB\n", '1073741824', '536870912'));
        $this->assertSame(0.0, FseValidationJobs::availableMemoryMiB("MemAvailable: 1048576 kB\n", '10', '20'));
        $this->assertNull(FseValidationJobs::availableMemoryMiB(false, 'max', '0'));
        $this->assertNull(FseValidationJobs::availableMemoryMiB("MemAvailable: 1 kB\n", false, '0'));
        $this->assertNull(FseValidationJobs::availableMemoryMiB("MemAvailable: 1 kB\n", 'max', 'unknown'));
        $this->assertNull(FseValidationJobs::availableMemoryMiB("MemAvailable: 1 kB\n", 'unknown', '0'));
        $this->assertNull(FseValidationJobs::availableMemoryMiB("MemAvailable: -1 kB\n", 'max', '0'));
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(WRITEPATH) . '/fse-jobs-test-' . bin2hex(random_bytes(8));
        $this->jobs = new FseValidationJobs($this->root);
    }
    protected function tearDown(): void
    {
        // Only this test's known files; never recursively clean writable or patient storage.
        foreach (new \DirectoryIterator($this->root) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isDir() && preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename())) {
                foreach (['input.json', 'output.json', 'stderr.txt', '.active.lock', 'unknown.txt'] as $name) {
                    if (is_file($entry->getPathname().'/'.$name)) unlink($entry->getPathname().'/'.$name);
                }
                rmdir($entry->getPathname());
            } elseif ($entry->isFile() && preg_match('/^(slot-\d+|maintenance)\.lock$/D', $entry->getFilename())) unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }
    public function testSlotsAreBoundedAndReusableWithoutUnlinking(): void
    {
        $one = $this->jobs->acquire(2); $two = $this->jobs->acquire(2);
        try {
            try { $this->jobs->acquire(2); $this->fail('Third worker was allowed'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('occupato', $e->getMessage()); }
        } finally { FseValidationJobs::release($one); FseValidationJobs::release($two); }
        $next = $this->jobs->acquire(2); FseValidationJobs::release($next);
        $this->assertFileExists($this->root . '/slot-0.lock');
    }
    private function expired(bool $keepLock = false): array
    {
        $job = $this->jobs->create();
        file_put_contents($job['directory'].'/input.json', 'SYNTHETIC ONLY');
        foreach (['input.json', '.active.lock'] as $name) touch($job['directory'].'/'.$name, time()-90000);
        touch($job['directory'], time()-90000);
        if (!$keepLock) FseValidationJobs::release($job['lease']);
        return $job;
    }
    public function testLeaseIsEnforcedAcrossPhpProcessesAndReleasedOnExit(): void
    {
        $code = 'define("WRITEPATH", ' . var_export(WRITEPATH, true) . '); require ' . var_export(APPPATH.'Services/FseValidationJobs.php', true)
            . '; try { $lease = (new App\\Services\\FseValidationJobs(' . var_export($this->root, true) . '))->acquire(1); echo "ACQUIRED"; } catch (RuntimeException $e) { echo "BUSY"; }';
        $child = static function () use ($code): string {
            $process = proc_open([PHP_BINARY, '-r', $code], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new \RuntimeException('Synthetic lock child failed: ' . $err);
            return $out;
        };
        $lease = $this->jobs->acquire(1);
        try { $this->assertSame('BUSY', $child()); } finally { FseValidationJobs::release($lease); }
        $this->assertSame('ACQUIRED', $child());
        $this->assertSame('ACQUIRED', $child());
    }
    public function testCleanupDefaultsToDryRunThenDeletesOnlyExpiredKnownFiles(): void
    {
        $job = $this->expired();
        $result = $this->jobs->cleanup();
        $this->assertSame(1, $result['eligible']); $this->assertTrue($result['dry_run']);
        $this->assertFileExists($job['directory'].'/input.json');
        $this->assertSame(1, $this->jobs->cleanup(true)['removed']);
        $this->assertDirectoryDoesNotExist($job['directory']);
    }
    public function testActiveWorkerIsNeverCleanedEvenIfExpired(): void
    {
        $job = $this->expired(true);
        try { $this->assertSame(0, $this->jobs->cleanup(true)['removed']); $this->assertFileExists($job['directory'].'/input.json'); }
        finally { FseValidationJobs::release($job['lease']); }
    }
    public function testFreshAndUnrecognizedContentArePreserved(): void
    {
        $old = $this->expired(); file_put_contents($old['directory'].'/unknown.txt', 'SYNTHETIC ONLY'); touch($old['directory'], time()-90000);
        $fresh = $this->jobs->create(); FseValidationJobs::release($fresh['lease']);
        $this->assertSame(0, $this->jobs->cleanup(true)['removed']);
        $this->assertFileExists($old['directory'].'/unknown.txt'); $this->assertDirectoryExists($fresh['directory']);
    }
    public function testStorageRootCannotEscapeWritable(): void
    {
        $this->expectException(\RuntimeException::class);
        new FseValidationJobs(realpath(WRITEPATH) . '/../other');
    }
    public function testUnsafeRetentionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class); $this->jobs->cleanup(true, 1);
    }
}
