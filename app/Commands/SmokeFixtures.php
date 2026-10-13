<?php

namespace App\Commands;

use App\Libraries\InvitationService;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SmokeFixtures extends BaseCommand
{
    protected $group = 'Operations';
    protected $name = 'ops:smoke-fixtures';
    protected $description = 'Creates isolated HTTP test accounts, or suspends them after testing.';
    protected $options = ['--output' => 'Private /tmp fixture path.', '--finish' => 'Suspend test accounts and revoke invites.'];

    public function run(array $params)
    {
        $path = (string) CLI::getOption('output');
        if (! preg_match('#\A/tmp/cloud-smoke-[a-f0-9]{16}\.json\z#', $path)) {
            CLI::error('A dedicated private fixture path is required.');
            return EXIT_ERROR;
        }
        $users = new UserModel();
        if (CLI::getOption('finish')) {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach (array_merge([$data['admin']], $data['users']) as $fixture) {
                if (! preg_match('/\Asmoke_[a-f0-9]{16}_(admin|one|two)\z/', $fixture['username'])) {
                    throw new \RuntimeException('Fixture identity is invalid.');
                }
                $user = $users->where('username', $fixture['username'])->first();
                if ($user !== null && $user['email'] === $fixture['email']) {
                    $users->update((int) $user['id'], ['status' => 'inactive']);
                }
            }
            foreach ($data['users'] as $fixture) {
                \Config\Database::connect()->table('invitation_codes')
                    ->where('code_hash', (new InvitationService())->hashCode($fixture['invite']))
                    ->update(['status' => 'revoked']);
            }
            unlink($path);
            CLI::write('Smoke accounts suspended; fixture credentials removed.');
            return EXIT_SUCCESS;
        }
        if (file_exists($path)) {
            CLI::error('Fixture file already exists.');
            return EXIT_ERROR;
        }
        $suffix = bin2hex(random_bytes(8));
        $data = ['admin' => ['username' => 'smoke_' . $suffix . '_admin',
            'email' => 'smoke_' . $suffix . '_admin@example.test', 'password' => bin2hex(random_bytes(24))], 'users' => []];
        $id = $users->insert([
            'username' => $data['admin']['username'], 'email' => $data['admin']['email'],
            'password_hash' => password_hash($data['admin']['password'], PASSWORD_DEFAULT),
            'role' => 'super_admin', 'status' => 'active', 'storage_quota_bytes' => 0, 'storage_used_bytes' => 0,
        ], true);
        if (! is_int($id)) {
            throw new \RuntimeException('Smoke administrator could not be created.');
        }
        $invites = new InvitationService();
        foreach (['one', 'two'] as $name) {
            $invite = $invites->generatePlainCode();
            $invites->create($invite, 'HTTP deployment smoke test', 1, date('Y-m-d H:i:s', time() + 3600), $id,
                ['storage_quota_bytes' => 524288]);
            $data['users'][] = ['username' => 'smoke_' . $suffix . '_' . $name,
                'email' => 'smoke_' . $suffix . '_' . $name . '@example.test',
                'password' => bin2hex(random_bytes(24)), 'invite' => $invite];
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Private fixture file could not be created.');
        }
        chmod($path, 0600);
        fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR));
        fclose($handle);
        CLI::write('Private HTTP fixtures prepared.');
        return EXIT_SUCCESS;
    }
}
