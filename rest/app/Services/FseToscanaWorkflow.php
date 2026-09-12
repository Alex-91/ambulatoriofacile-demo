<?php
namespace App\Services;

/** Persistable synthetic workflow. No HTTP client or clinical writes; imports contain hashes and local IDs only. */
final class FseToscanaWorkflow
{
    public const MODE = 'TOSCANA_SYNTHETIC_WORKFLOW_V1';
    public static function initial(int $tenant): array
    {
        if ($tenant <= 0) throw new \RuntimeException('LAB_SCOPE');
        return ['mode'=>self::MODE,'tenant_id'=>$tenant,'revision'=>0,'network_calls'=>0,
            'official_accreditation_evidence'=>false,'documents'=>[],'operations'=>[],'events'=>[]];
    }

    /** Not accepted by apply(): only the read-only, isolated document bridge supplies these proofs. */
    public function importSignedSnapshot(array $state, int $tenant, int $actor, array $proof): array
    {
        if (($state['mode'] ?? '') !== self::MODE || ($state['tenant_id'] ?? 0) !== $tenant || $actor <= 0) throw new \RuntimeException('LAB_SCOPE');
        $keys = ['document_id', 'previous_id', 'version', 'profile_sha256', 'set_sha256', 'cda_sha256', 'signed_pdf_sha256'];
        if (count($proof) !== count($keys) || array_diff($keys, array_keys($proof))) throw new \RuntimeException('LAB_SOURCE');
        foreach (['document_id', 'previous_id', 'version'] as $key) if (!is_int($proof[$key])) throw new \RuntimeException('LAB_SOURCE');
        if ($proof['document_id'] <= 0 || $proof['previous_id'] < 0 || $proof['version'] < 1) throw new \RuntimeException('LAB_SOURCE');
        foreach (array_slice($keys, 3) as $key) if (!is_string($proof[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $proof[$key])) throw new \RuntimeException('LAB_SOURCE');
        $previous = null;
        foreach ($state['documents'] as $id => $doc) {
            $source = $doc['source_snapshot'] ?? [];
            if (($source['document_id'] ?? 0) === $proof['document_id']) {
                if ($source !== $proof) throw new \RuntimeException('LAB_SOURCE_CHANGED');
                return $state; // Idempotent: never resets a pending/uncertain/confirmed rehearsal.
            }
            if ($proof['previous_id'] > 0 && ($source['document_id'] ?? 0) === $proof['previous_id']) $previous = $id;
        }
        if (count($state['events']) >= 500) throw new \RuntimeException('LAB_LIMIT');
        if ($proof['previous_id']) {
            $parent = $state['documents'][$previous ?? ''] ?? [];
            if (!$parent || $parent['state'] !== 'published' || $parent['pending'] || $parent['open_revision']
                || $parent['source_snapshot']['set_sha256'] !== $proof['set_sha256']
                || $parent['source_snapshot']['profile_sha256'] !== $proof['profile_sha256']
                || $parent['version'] + 1 !== $proof['version']) throw new \RuntimeException('LAB_PARENT');
        } elseif ($proof['version'] !== 1) throw new \RuntimeException('LAB_PARENT');
        $id = $this->newDocument($state, 'SNAPSHOT.' . $proof['profile_sha256'], $previous, $proof['version']);
        $state['documents'][$id]['state'] = 'signed';
        $state['documents'][$id]['sealed_hash'] = $proof['signed_pdf_sha256'];
        $state['documents'][$id]['source_snapshot'] = $proof;
        if ($previous) {
            $state['documents'][$previous]['open_revision'] = $id;
            $state['documents'][$previous]['revision']++;
        }
        $state['revision']++;
        $state['events'][] = ['sequence' => $state['revision'], 'actor' => $actor, 'document' => $id,
            'command' => 'import_signed_snapshot', 'at' => gmdate('c')];
        return $state;
    }

    public function apply(array $state, int $tenant, int $actor, string $command, string $id, int $expected,
        string $profile = 'SITE_A_PRIVATE', string $operationId = '', string $workflow = ''): array
    {
        if (($state['mode'] ?? '') !== self::MODE || ($state['tenant_id'] ?? 0) !== $tenant || $actor <= 0) throw new \RuntimeException('LAB_SCOPE');
        if (count($state['events']) >= 500) throw new \RuntimeException('LAB_LIMIT');
        if ($command === 'new') {
            if ($state['revision'] !== $expected) throw new \RuntimeException('LAB_STALE');
            if (!in_array($profile,['SITE_A_PRIVATE','SITE_B_SSR'],true)) throw new \RuntimeException('LAB_PROFILE');
            $id=$this->newDocument($state,$profile);
        } else {
            if (!isset($state['documents'][$id])) throw new \RuntimeException('LAB_DOCUMENT');
            $doc=&$state['documents'][$id];
            if ($doc['revision'] !== $expected) throw new \RuntimeException('LAB_STALE');
            if ($profile !== $doc['profile']) throw new \RuntimeException('LAB_PROFILE');
            if ($command === 'prepare' || $command === 'sign') {
                $this->requireState($doc,$command==='prepare' ? ['draft'] : ['prepared']);
                $doc['state']=$command==='prepare' ? 'prepared' : 'signed';
                if ($command==='sign') $doc['sealed_hash']=hash('sha256','SYNTHETIC|'.$id.'|'.$doc['version'].'|'.$profile);
            } elseif ($command==='revise') {
                if (!empty($doc['source_snapshot'])) throw new \RuntimeException('LAB_SOURCE_REVISION');
                $this->requireState($doc,['published']);
                if ($doc['pending'] || $doc['open_revision']) throw new \RuntimeException('LAB_PENDING');
                $child=$this->newDocument($state,$profile,$id,$doc['version']+1);
                $doc['open_revision']=$child;
            } elseif (str_starts_with($command,'begin_')) {
                $operation=substr($command,6);
                if (!in_array($operation,['create','replace','metadata','delete'],true)) throw new \RuntimeException('LAB_COMMAND');
                $this->requireState($doc,in_array($operation,['create','replace'],true) ? ['signed'] : ['published']);
                if ($doc['pending'] || $doc['open_revision']) throw new \RuntimeException('LAB_PENDING');
                if (!$doc['sealed_hash']) throw new \RuntimeException('LAB_SIGNATURE');
                if ($operation==='create' && $doc['previous']) throw new \RuntimeException('LAB_REPLACE_REQUIRED');
                if ($operation==='replace') {
                    $parent=$state['documents'][$doc['previous']] ?? null;
                    if (!$parent || $parent['state']!=='published' || $parent['pending'] || $parent['profile']!==$profile
                        || $parent['open_revision']!==$id || $parent['version']+1!==$doc['version']) throw new \RuntimeException('LAB_PARENT');
                }
                $op='SIM.OP.'.bin2hex(random_bytes(12));
                $state['operations'][$op]=['id'=>$op,'document'=>$id,'profile'=>$profile,'kind'=>$operation,
                    'state'=>'sent','workflow'=>null,'x_cart_id'=>null,'sealed_hash'=>$doc['sealed_hash'],
                    'metadata_before'=>$doc['metadata_version'],'proposed_metadata_version'=>$doc['metadata_version']+($operation==='metadata' ? 1 : 0)];
                $doc['pending']=$op; $doc['state']='pending';
            } elseif (in_array($command,['accepted','rejected','timeout','confirm_ok','confirm_ko'],true)) {
                if (!$operationId || !isset($state['operations'][$operationId]) || !is_array($state['operations'][$operationId])) throw new \RuntimeException('LAB_CORRELATION');
                $op=&$state['operations'][$operationId];
                if ($doc['pending']!==$operationId || $op['document']!==$id || $op['profile']!==$profile) throw new \RuntimeException('LAB_CORRELATION');
                if ($doc['state']!=='pending' || $op['sealed_hash']!==$doc['sealed_hash']) throw new \RuntimeException('LAB_STATE');
                if (str_starts_with($command,'confirm_')) {
                    if ($op['state']!=='accepted' || !$workflow || $workflow!==$op['workflow']) throw new \RuntimeException('LAB_CORRELATION');
                    $this->finish($state,$doc,$op,$command==='confirm_ok');
                } else {
                    if ($op['state']!=='sent') throw new \RuntimeException('LAB_REPLY_ORDER');
                    $op['x_cart_id']='SIM.CART.'.substr($operationId,7);
                    if ($command==='timeout') $op['state']='uncertain';
                    elseif ($command==='rejected') $this->finish($state,$doc,$op,false);
                    else { $op['state']='accepted'; $op['workflow']='SIM.WF.'.substr($operationId,7); }
                }
                unset($op);
            } else throw new \RuntimeException('LAB_COMMAND');
            $doc['revision']++;
            unset($doc);
        }
        $state['revision']++;
        $state['events'][]=['sequence'=>$state['revision'],'actor'=>$actor,'document'=>$id,'command'=>$command,'at'=>gmdate('c')];
        return $state;
    }

    private function newDocument(array &$state,string $profile,?string $previous=null,int $version=1): string
    {
        if (count($state['documents'])>=30) throw new \RuntimeException('LAB_LIMIT');
        $id='SIM.'.(count($state['documents'])+1);
        $state['documents'][$id]=['id'=>$id,'profile'=>$profile,'state'=>'draft','revision'=>0,'version'=>$version,
            'previous'=>$previous,'open_revision'=>null,'superseded_by'=>null,'pending'=>null,'sealed_hash'=>null,'metadata_version'=>1];
        return $id;
    }

    private function finish(array &$state,array &$doc,array &$op,bool $success): void
    {
        $op['state']=$success ? 'confirmed' : 'rejected';
        $doc['pending']=null;
        $doc['state']=$success ? ($op['kind']==='delete' ? 'deleted' : 'published')
            : (in_array($op['kind'],['metadata','delete'],true) ? 'published' : 'rejected');
        if ($success && $op['kind']==='metadata') $doc['metadata_version']=$op['proposed_metadata_version'];
        if ($op['kind']==='replace') {
            $parent=&$state['documents'][$doc['previous']];
            if ($parent['open_revision']!==$doc['id'] || $parent['state']!=='published') throw new \RuntimeException('LAB_PARENT');
            $parent['open_revision']=null;
            if ($success) { $parent['state']='superseded'; $parent['superseded_by']=$doc['id']; }
            $parent['revision']++;
        }
    }

    private function requireState(array $doc,array $allowed): void
    {
        if (!in_array($doc['state'],$allowed,true)) throw new \RuntimeException('LAB_STATE');
    }
}
