<?php
namespace Tests\Unit;
use App\Services\FseToscanaLabStore;
use CodeIgniter\Test\CIUnitTestCase;

final class FseToscanaLabStoreTest extends CIUnitTestCase
{
    private string $root;
    private FseToscanaLabStore $store;
    protected function setUp(): void
    {
        parent::setUp(); $this->root=rtrim(WRITEPATH,'/\\').'/fse-workflow-tests-'.bin2hex(random_bytes(8));
        $this->store=new FseToscanaLabStore($this->root);
    }
    protected function tearDown(): void
    {
        // Only immediate synthetic files in this test's newly-created, validated directory.
        foreach (new \DirectoryIterator($this->root) as $file) if (!$file->isDot() && $file->isFile() && !$file->isLink()) unlink($file->getPathname());
        rmdir($this->root); parent::tearDown();
    }
    private function seed(int $tenant): void
    {
        $profile=$tenant%2===0 ? 'SITE_A_PRIVATE' : 'SITE_B_SSR';
        foreach (['new','prepare','sign'] as $index=>$command) $this->store->command($tenant,9,[
            'command'=>$command,'document'=>'SIM.1','revision'=>$index===0 ? 0 : $index-1,'profile'=>$profile]);
    }
    private function worker(int $tenant,int $actor,string $mode): array
    {
        $process=proc_open([PHP_BINARY,dirname(APPPATH,2).'/ops/fse-validation/lab-concurrency-worker.php',basename($this->root),(string)$tenant,(string)$actor,$mode,WRITEPATH],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        $this->assertIsResource($process); fclose($pipes[0]);
        return [$process,$pipes];
    }
    public function testSixStudiosAndTwelveProcessesAllowOneRequestPerDocument(): void
    {
        $workers=[];
        foreach (range(42,47) as $tenant) {
            $this->seed($tenant);
            foreach ([1,2] as $operator) $workers[]=$this->worker($tenant,$tenant*10+$operator,'race');
        }
        try {
            $deadline=microtime(true)+8;
            do { clearstatcache(); $ready=glob($this->root.'/ready-*'); if (count($ready)===12) break; usleep(10000); } while (microtime(true)<$deadline);
            $this->assertCount(12,$ready); file_put_contents($this->root.'/go','go');
            $accepted=[];
            foreach ($workers as [$process,$pipes]) {
                $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
                $this->assertSame(0,$exit,$error); $this->assertSame('',$error);
                $result=json_decode($output,true); $this->assertContains($result['result'],['accepted','blocked']);
                if ($result['result']==='accepted') $accepted[]=$result['tenant'];
            }
            $workers=[]; sort($accepted); $this->assertSame(range(42,47),$accepted);
            foreach (range(42,47) as $tenant) {
                $state=(new FseToscanaLabStore($this->root))->read($tenant);
                $this->assertSame($tenant,$state['tenant_id']); $this->assertCount(1,$state['operations']);
                $this->assertSame('pending',$state['documents']['SIM.1']['state']);
                $this->assertSame($tenant%2===0 ? 'SITE_A_PRIVATE' : 'SITE_B_SSR',$state['documents']['SIM.1']['profile']);
                $this->assertSame(0,$state['network_calls']);
            }
        } finally {
            foreach ($workers as [$process,$pipes]) if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
        }
    }
    public function testInterruptedWorkerLeavesCommittedPendingStateAndNoDuplicate(): void
    {
        $this->seed(42); [$process,$pipes]=$this->worker(42,91,'interrupted');
        foreach ([1,2] as $i) { stream_get_contents($pipes[$i]); fclose($pipes[$i]); }
        $this->assertSame(77,proc_close($process));
        $state=(new FseToscanaLabStore($this->root))->read(42);
        $this->assertSame('pending',$state['documents']['SIM.1']['state']);
        $this->expectExceptionMessage('LAB_STATE');
        $this->store->command(42,92,['command'=>'begin_create','document'=>'SIM.1','revision'=>3,'profile'=>'SITE_A_PRIVATE']);
    }
    public function testCrossTenantOperationAndProfileCannotChangeOtherStudio(): void
    {
        $this->seed(42); $this->seed(43); $before=$this->store->read(43);
        $state=$this->store->command(42,9,['command'=>'begin_create','document'=>'SIM.1','revision'=>2,'profile'=>'SITE_A_PRIVATE']);
        try { $this->store->command(43,9,['command'=>'accepted','document'=>'SIM.1','revision'=>2,'profile'=>'SITE_B_SSR','operation'=>$state['documents']['SIM.1']['pending']]); $this->fail('Cross tenant operation'); }
        catch (\RuntimeException $e) { $this->assertSame('LAB_CORRELATION',$e->getMessage()); }
        $this->assertSame($before,$this->store->read(43));
    }
    public function testInvalidStorageCannotBeSilentlyReset(): void
    {
        file_put_contents($this->root.'/tenant-42.json','{"partial":');
        $this->expectExceptionMessage('LAB_STORAGE'); $this->store->read(42);
    }
    public function testDirectoryEscapeIsRejected(): void
    {
        $this->expectExceptionMessage('LAB_STORAGE'); new FseToscanaLabStore(WRITEPATH.'../outside');
    }
}
