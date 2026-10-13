<?php

namespace App\Commands;

use App\Libraries\DriveService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CleanupDriveTempFiles extends BaseCommand
{
    protected $group = 'Drive';

    protected $name = 'drive:cleanup-temp';

    protected $description = 'Deletes expired temporary files from private drive storage.';

    protected $usage = 'drive:cleanup-temp --max-age=86400';

    protected $options = [
        '--max-age' => 'Maximum temp file age in seconds. Defaults to CloudDrive config.',
    ];

    public function run(array $params)
    {
        $maxAge = CLI::getOption('max-age');
        $maxAgeSeconds = $maxAge === null ? null : (int) $maxAge;

        if ($maxAgeSeconds !== null && $maxAgeSeconds < 1) {
            CLI::error('--max-age must be greater than zero.');

            return EXIT_ERROR;
        }

        $deleted = (new DriveService())->cleanupExpiredTempFiles($maxAgeSeconds);
        CLI::write('Expired temporary files deleted: ' . $deleted, 'green');

        return EXIT_SUCCESS;
    }
}
