<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};

/** Apply only the declared platform navigation migration, independently of legacy tenant migrations. */
final class NavigationMigrate extends BaseCommand
{
    protected $group='Database';
    protected $name='navigation:migrate';
    protected $description='Apply the platform navigation preferences migration.';
    public function run(array $params)
    {
        $db=\Config\Database::connect('platform');
        if (!$db->tableExists('platform_tenants')) {
            CLI::write('No platform tenant schema; navigation migration skipped.');
            return EXIT_SUCCESS;
        }
        service('migrations')->force(APPPATH.'Database/Migrations/2026-10-03-100001_CreateNavigationPreferences.php','App','platform');
        if (!$db->tableExists('platform_navigation_preferences')) {
            CLI::error('Navigation preferences migration failed.');
            return EXIT_ERROR;
        }
        CLI::write('Navigation preferences schema ready.');
        return EXIT_SUCCESS;
    }
}
