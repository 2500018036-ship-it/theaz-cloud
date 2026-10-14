<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MigrateProduction extends BaseCommand
{
    protected $group = 'Operations';
    protected $name = 'ops:migrate';
    protected $description = 'Runs application migrations and exits unsuccessfully on any failure.';

    public function run(array $params)
    {
        try {
            $runner = service('migrations');
            $runner->setNamespace('App');
            if (! $runner->latest(config(\Config\Database::class)->defaultGroup)) {
                throw new \RuntimeException('Migrations did not complete.');
            }
            foreach (['users', 'invitation_codes', 'drive_items', 'system_settings', 'user_profiles', 'email_queue'] as $table) {
                if (! \Config\Database::connect()->tableExists($table)) {
                    throw new \RuntimeException('Required schema is missing.');
                }
            }
            CLI::write('Application migrations and schema verified.');
            return EXIT_SUCCESS;
        } catch (\Throwable $exception) {
            log_message('error', 'Production migration failed: {type}', ['type' => $exception::class]);
            CLI::error('Application migration failed. Inspect private application logs before continuing.');
            return EXIT_ERROR;
        }
    }
}
