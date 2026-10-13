<?php

namespace App\Commands;

use App\Libraries\DriveService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PruneDriveVersions extends BaseCommand
{
    protected $group = 'Drive';

    protected $name = 'drive:versions:prune';

    protected $description = 'Prunes stored file versions according to CloudDrive retention settings.';

    public function run(array $params)
    {
        $deleted = (new DriveService())->pruneStoredVersions();

        CLI::write('Pruned ' . $deleted . ' stored file version(s).', 'green');

        return EXIT_SUCCESS;
    }
}
