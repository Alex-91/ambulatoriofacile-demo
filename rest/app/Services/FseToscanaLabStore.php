<?php
namespace App\Services;

/** Only invented lab state. Stable lock + atomic replacement; never opens tenant clinical DBs. */
final class FseToscanaLabStore
{
    private string $root;
    public function __construct(?string $root=null)
    {
        $base=realpath(WRITEPATH);
        if (!$base) throw new \RuntimeException('LAB_STORAGE');
        $root=str_replace('\\','/',$root ?? $base.'/fse2/toscana-lab');
        $prefix=rtrim(str_replace('\\','/',$base),'/').'/';
        if (!str_starts_with($root,$prefix)) throw new \RuntimeException('LAB_STORAGE');
        $current=$base;
        foreach (explode('/',substr($root,strlen($prefix))) as $part) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/D',$part)) throw new \RuntimeException('LAB_STORAGE');
            $current.='/'.$part;
            if (is_link($current) || (!is_dir($current) && !@mkdir($current,0700) && !is_dir($current))) throw new \RuntimeException('LAB_STORAGE');
        }
        $this->root=(string)realpath($current);
    }

    public function read(int $tenant): array { return $this->locked($tenant,null); }

    /** Caller must validate artifacts and the isolated app boundary; never exposed as a generic command. */
    public function importSignedSnapshot(int $tenant, int $actor, array $proof): array
    {
        return $this->locked($tenant, static fn($state) => (new FseToscanaWorkflow())->importSignedSnapshot($state, $tenant, $actor, $proof));
    }

    public function command(int $tenant,int $actor,array $input): array
    {
        return $this->locked($tenant,static fn($state)=>(new FseToscanaWorkflow())->apply($state,$tenant,$actor,
            (string)($input['command'] ?? ''),(string)($input['document'] ?? ''),(int)($input['revision'] ?? -1),
            (string)($input['profile'] ?? ''),(string)($input['operation'] ?? ''),(string)($input['workflow'] ?? '')));
    }

    private function locked(int $tenant,?callable $change): array
    {
        if ($tenant<=0) throw new \RuntimeException('LAB_SCOPE');
        $path=$this->root.'/tenant-'.$tenant.'.json'; $lockPath=$path.'.lock';
        if (is_link($path) || is_link($lockPath)) throw new \RuntimeException('LAB_STORAGE');
        $lock=@fopen($lockPath,'c+b');
        if (!$lock) throw new \RuntimeException('LAB_STORAGE');
        @chmod($lockPath,0600);
        try {
            if (!flock($lock,($change ? LOCK_EX : LOCK_SH)|LOCK_NB)) throw new \RuntimeException('LAB_BUSY');
            if (is_file($path)) {
                $json=file_get_contents($path,false,null,0,1048577);
                if (!is_string($json) || strlen($json)>1048576) throw new \RuntimeException('LAB_STORAGE');
                try { $state=json_decode($json,true,32,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new \RuntimeException('LAB_STORAGE'); }
                if (!is_array($state) || ($state['mode'] ?? '')!==FseToscanaWorkflow::MODE || ($state['tenant_id'] ?? 0)!==$tenant) throw new \RuntimeException('LAB_SCOPE');
            } else $state=FseToscanaWorkflow::initial($tenant);
            if (!$change) return $state;
            $next=$change($state);
            $json=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if (strlen($json)>1048576) throw new \RuntimeException('LAB_LIMIT');
            $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
            $handle=@fopen($temp,'x+b');
            if (!$handle) throw new \RuntimeException('LAB_STORAGE');
            @chmod($temp,0600);
            try {
                if (fwrite($handle,$json)!==strlen($json) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new \RuntimeException('LAB_STORAGE');
            } finally { fclose($handle); }
            // The last committed snapshot survives interruptions before rename.
            if (!@rename($temp,$path)) { @unlink($temp); throw new \RuntimeException('LAB_STORAGE'); }
            return $next;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
