<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Auth;

class BootstrapAdmin extends BaseCommand
{
    protected $group = 'Auth';

    protected $name = 'auth:bootstrap-admin';

    protected $description = 'Creates the initial super administrator account from controlled input.';

    protected $usage = 'auth:bootstrap-admin --email user@example.test --username owner';

    protected $options = [
        '--email'        => 'Admin email. Falls back to AUTH_BOOTSTRAP_ADMIN_EMAIL.',
        '--username'     => 'Admin username. Falls back to AUTH_BOOTSTRAP_ADMIN_USERNAME.',
        '--password-env' => 'Environment variable that contains the password. Defaults to AUTH_BOOTSTRAP_ADMIN_PASSWORD.',
    ];

    public function run(array $params)
    {
        $email = mb_strtolower(trim((string) (CLI::getOption('email') ?: env('AUTH_BOOTSTRAP_ADMIN_EMAIL'))));
        $username = mb_strtolower(trim((string) (CLI::getOption('username') ?: env('AUTH_BOOTSTRAP_ADMIN_USERNAME'))));
        $passwordEnv = (string) (CLI::getOption('password-env') ?: 'AUTH_BOOTSTRAP_ADMIN_PASSWORD');
        $password = (string) env($passwordEnv, '');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('A valid super admin email is required.');

            return EXIT_ERROR;
        }

        if (! preg_match('/\A[a-zA-Z0-9._-]{3,80}\z/', $username)) {
            CLI::error('A valid super admin username is required.');

            return EXIT_ERROR;
        }

        if (strlen($password) < 10) {
            CLI::error('Super admin password must be provided through the configured environment variable and must be at least 10 characters.');

            return EXIT_ERROR;
        }

        $users = new UserModel();
        if ($users->withDeleted()->where('role', 'super_admin')->countAllResults() > 0) {
            CLI::write('The permanent administrator already exists. No changes were made.', 'yellow');
            return EXIT_SUCCESS;
        }
        $existingByEmail = $users->withDeleted()->where('email', $email)->first();
        $existingByUsername = $users->withDeleted()->where('username', $username)->first();

        if ($existingByEmail !== null) {
            if ($existingByEmail['role'] === 'super_admin') {
                CLI::write('Super admin account already exists. No changes were made.', 'yellow');

                return EXIT_SUCCESS;
            }

            CLI::error('An account with that email already exists and is not a super admin. No changes were made.');

            return EXIT_ERROR;
        }

        if ($existingByUsername !== null) {
            CLI::error('An account with that username already exists. No changes were made.');

            return EXIT_ERROR;
        }

        $id = $users->insert([
            'username'            => $username,
            'email'               => $email,
            'password_hash'       => password_hash($password, PASSWORD_DEFAULT),
            'role'                => 'super_admin',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true);

        if (! is_int($id)) {
            CLI::error('Super admin account could not be created: ' . implode(' ', $users->errors()));

            return EXIT_ERROR;
        }

        CLI::write('Super admin account created. Store the password securely; it was not written to the database in plaintext.', 'green');

        return EXIT_SUCCESS;
    }
}
