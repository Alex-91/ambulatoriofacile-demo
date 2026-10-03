<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
use App\Services\NavigationLayoutService;

/** The approved presentation defaults contain no URLs, tenant data or permissions. */
final class SeedApprovedNavigationLayout extends Migration
{
    protected $DBGroup='platform';
    public function up()
    {
        if(!$this->db->tableExists('platform_navigation_layouts'))return;
        if($this->db->table('platform_navigation_layouts')->where('scope_id',0)->countAllResults()>0)return;
        $nodes=json_decode(file_get_contents(APPPATH.'Database/Seeds/Data/navigation-default.json'),true,512,JSON_THROW_ON_ERROR);
        $nodes=NavigationLayoutService::validate($nodes,$nodes);
        if(!$this->db->query('INSERT IGNORE INTO platform_navigation_layouts (scope_id,version,nodes_json,updated_at) VALUES (0,1,?,?)',[json_encode($nodes,JSON_THROW_ON_ERROR),date('Y-m-d H:i:s')]))throw new \RuntimeException('Navigation defaults could not be initialized.');
    }
    // Never remove a customer's configured layout when rolling back application code.
    public function down() {}
}
