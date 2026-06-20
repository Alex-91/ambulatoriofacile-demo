<?php

namespace App\Libraries;

use CodeIgniter\Database\MigrationRunner;
use stdClass;

class FilteredMigrationRunner extends MigrationRunner
{
    public function setCustomPath(string $path): self
    {
        $this->path = rtrim($path, '\\/') . DIRECTORY_SEPARATOR;
        return $this;
    }

    protected function migrationFromFile(string $path, string $namespace)
    {
        if (!str_ends_with($path, '.php')) {
            return false;
        }

        $filename = basename($path, '.php');
        if (preg_match($this->regex, $filename) !== 1) {
            return false;
        }

        $migration = new stdClass();
        $migration->version = $this->getMigrationNumber($filename);
        $migration->name = $this->getMigrationName($filename);
        $migration->path = $path;
        $migration->class = $namespace . '\\Database\\Migrations\\' . $migration->name;
        $migration->namespace = $namespace;
        $migration->uid = $this->getObjectUid($migration);

        return $migration;
    }
}
