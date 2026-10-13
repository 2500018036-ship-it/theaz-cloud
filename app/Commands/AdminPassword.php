<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AdminPassword extends BaseCommand
{
    protected $group = 'Operations';
    protected $name = 'ops:admin-password';
    protected $description = 'Sets a super administrator password using private standard input.';
    protected $options = ['--email' => 'Existing super administrator email.'];

    public function run(array $params)
    {
        $email = mb_strtolower(trim((string) CLI::getOption('email')));
        $password = rtrim((string) fgets(STDIN), "\r\n");
        if (strlen($password) < 12 || strlen($password) > 255) {
            CLI::error('Use a password between 12 and 255 characters.');
            return EXIT_ERROR;
        }
        $users = new UserModel();
        $user = $users->where('email', $email)->where('role', 'super_admin')->first();
        if ($user === null) {
            CLI::error('Super administrator was not found.');
            return EXIT_ERROR;
        }
        if (! $users->update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)])) {
            CLI::error('Password could not be updated.');
            return EXIT_ERROR;
        }
        CLI::write('Super administrator password updated.');
        return EXIT_SUCCESS;
    }
}
