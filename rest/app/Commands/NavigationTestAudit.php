<?php
namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{TenantDatabaseConnector,TenantFeatureService,UnifiedMenuService};

/** CLI-only checks and temporary test identities, never usable on a live runtime. */
final class NavigationTestAudit extends BaseCommand
{
    protected $group='Testing';
    protected $name='navigation:test-audit';
    protected $description='Audit isolated preview databases; optional fixture/cleanup for HTTP login checks.';
    public function run(array $params)
    {
        $expected=(string)env('AF_TEST_DB_HOST','');
        if ((string)env('AF_NAVIGATION_TEST')!=='1' || $expected!== 'uapmyovmgml4ov24y4d94tao' || (string)env('PLATFORM_DB_HOST')!==$expected || (string)env('DB_HOST')!==$expected) {
            CLI::error('Only the isolated navigation preview is allowed.');return EXIT_ERROR;
        }
        $db=\Config\Database::connect('platform');
        $emails=['audit-menu-master@preview.invalid','audit-menu-staff@preview.invalid'];
        if (($params[0]??'')==='cleanup') {
            foreach($emails as $email){
                $row=$db->table('platform_users')->where('email',$email)->get()->getRowArray();
                if(!$row)continue;
                $db->table('platform_user_tenants')->where('id_platform_user',$row['id_platform_user'])->delete();
                $db->table('platform_navigation_preferences')->where('id_platform_user',$row['id_platform_user'])->delete();
                $db->table('platform_users')->where('id_platform_user',$row['id_platform_user'])->delete();
            }
            CLI::write('Preview audit accounts removed.');return EXIT_SUCCESS;
        }
        $fixture=($params[0]??'')==='fixture';
        $password=(string)env('AF_TEST_AUDIT_PASSWORD','');
        if($fixture && strlen($password)<32){CLI::error('A preview-only random password is required.');return EXIT_ERROR;}
        $tenants=$db->table('platform_tenants')->where('is_active',1)->get()->getResultArray();
        $report=['platform_users'=>$db->table('platform_users')->countAllResults(),'tenants'=>[],'failures'=>0];
        $fixtureUsers=[];
        if($fixture){
            foreach($emails as $email){
                $existing=$db->table('platform_users')->where('email',$email)->get()->getRowArray();
                if($existing){$fixtureUsers[$email]=(int)$existing['id_platform_user'];continue;}
                $db->table('platform_users')->insert(['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'first_name'=>'Collaudo','last_name'=>'Menu','is_platform_admin'=>0,'status'=>'active','must_reset_password'=>0,'email_verified_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                $fixtureUsers[$email]=(int)$db->insertID();
            }
        }
        foreach($tenants as $tenant){
            $id=(int)$tenant['id_tenant'];
            try{
                $connector=new TenantDatabaseConnector();
                $config=$connector->buildConnectionConfig($tenant);
                if($config['hostname']!==$expected)throw new \RuntimeException('Isolation check failed');
                $tenantDb=$connector->connect($tenant);
                $users=$tenantDb->table('dap01_users')->countAllResults();
                (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($tenantDb);
                // Return counts only: no passwords or other decrypted values leave SQL.
                $crypto=$tenantDb->query("SELECT COUNT(*) AS stored, SUM(AES_DECRYPT(UNHEX(password), @key_str, vector_id) IS NOT NULL) AS readable FROM dap01_users WHERE password IS NOT NULL AND password<>''")->getRowArray();
                $members=$db->table('platform_user_tenants')->where('id_tenant',$id)->get()->getResultArray();
                $valid=[];$orphans=0;
                foreach($members as $member){
                    $appId=(int)($member['app_user_id']??0);
                    if($appId<=0)continue;
                    if($tenantDb->table('dap01_users')->where('id_user',$appId)->countAllResults()===0){$orphans++;continue;}
                    $valid[]=$member;
                }
                if($fixture){
                    foreach($emails as $i=>$email){
                        $candidates=array_values(array_filter($valid,static fn($m)=>$i===0?$m['tenant_role']==='tenant_master':$m['tenant_role']!=='tenant_master'));
                        if(!$candidates)continue;
                        $member=$candidates[0];$fixtureId=$fixtureUsers[$email];
                        if($db->table('platform_user_tenants')->where('id_tenant',$id)->where('id_platform_user',$fixtureId)->countAllResults())continue;
                        unset($member['id_platform_user_tenant']);
                        $member['id_platform_user']=$fixtureId;$member['is_owner']=0;$member['is_default']=0;
                        $db->table('platform_user_tenants')->insert($member);
                    }
                }
                $features=(new TenantFeatureService())->resolveEffectiveFeatureMapForTenant($id);
                $report['tenants'][]=['tenant_id'=>$id,'users'=>$users,'memberships'=>count($members),'missing_app_users'=>$orphans,'stored_credentials'=>(int)$crypto['stored'],'readable_credentials'=>(int)$crypto['readable'],'new_menu'=>!empty($features[UnifiedMenuService::FEATURE_KEY])];
                $report['failures']+=$orphans;
            }catch(\Throwable $e){$report['tenants'][]=['tenant_id'=>$id,'error'=>get_class($e),'location'=>basename($e->getFile()).':'.$e->getLine(),'detail'=>$e instanceof \Error?$e->getMessage():'Database check failed'];$report['failures']++;}
        }
        CLI::write(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return $report['failures']?EXIT_ERROR:EXIT_SUCCESS;
    }
}
