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
    protected $description = 'Prepare private HTTP fixtures without changing the permanent owner password.';
    protected $options = ['--output' => 'Private /tmp fixture path.', '--finish' => 'Disable only test users.', '--tokens' => 'Collect only fixture verification links.'];
    public function run(array $params)
    {
        $path = (string) CLI::getOption('output');
        if (! preg_match('#\A/tmp/cloud-smoke-[a-f0-9]{16}\.json\z#', $path)) return EXIT_ERROR;
        $users = new UserModel(); $db = \Config\Database::connect();
        if (CLI::getOption('finish') || CLI::getOption('tokens')) {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['users'] as &$fixture) {
                if (! preg_match('/\Asmoke_[a-f0-9]{16}_(one|two)\z/', $fixture['username'])) throw new \RuntimeException('Invalid fixture identity.');
                $user = $users->where('username', $fixture['username'])->where('email', $fixture['email'])->first();
                if (CLI::getOption('finish')) {
                    if ($user !== null) $users->update((int) $user['id'], ['status' => 'inactive']);
                    $db->table('invitation_codes')->where('code_hash', (new InvitationService())->hashCode($fixture['invite']))->update(['status' => 'revoked']);
                } elseif ($user !== null) {
                    $mail = $db->table('email_queue')->select('body_text')->where('user_id', $user['id'])->where('event_type', 'email_verification')->orderBy('id', 'DESC')->get()->getRowArray();
                    if ($mail && preg_match('~email/verify/([a-f0-9]{64})~', $mail['body_text'], $match)) $fixture['verification_token'] = $match[1];
                }
            }
            unset($fixture);
            if (CLI::getOption('finish')) {
                $sessionFile = WRITEPATH . 'session/ci_session' . $data['admin']['session'];
                if (is_file($sessionFile)) unlink($sessionFile);
                unlink($path);
            } else file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
            CLI::write('Private smoke fixture updated.'); return EXIT_SUCCESS;
        }
        if (file_exists($path)) return EXIT_ERROR;
        $suffix = bin2hex(random_bytes(8)); $owner = $users->where('role', 'super_admin')->first();
        if ($owner === null) {
            $password = bin2hex(random_bytes(24));
            $id = $users->insert(['username' => 'smoke_' . $suffix . '_admin', 'email' => 'owner@example.test',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'super_admin', 'status' => 'active',
                'storage_quota_bytes' => 0, 'storage_used_bytes' => 0], true);
            $owner = $users->find($id);
        }
        $session = bin2hex(random_bytes(16));
        $state = ['is_logged_in' => true, 'user_id' => (int) $owner['id'], 'role' => 'super_admin',
            'username' => $owner['username'], 'email' => $owner['email'], 'auth_started_at' => time(), '__ci_last_regenerate' => time()];
        $serialized = ''; foreach ($state as $key => $value) $serialized .= $key . '|' . serialize($value);
        $sessionFile = WRITEPATH . 'session/ci_session' . $session;
        file_put_contents($sessionFile, $serialized); chmod($sessionFile, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) { chown($sessionFile, 'www-data'); chgrp($sessionFile, 'www-data'); }
        $data = ['admin' => ['username' => $owner['username'], 'email' => $owner['email'], 'password' => $password ?? '', 'session' => $session], 'users' => []];
        $from = (string) config(\Config\Email::class)->fromEmail;
        foreach (['one', 'two'] as $name) {
            $invite = (new InvitationService())->generatePlainCode();
            (new InvitationService())->create($invite, 'HTTP deployment smoke test', 1, date('Y-m-d H:i:s', time() + 3600), (int) $owner['id'], ['storage_quota_bytes' => 524288]);
            $email = ! config(\Config\Auth::class)->emailVerificationDeferred && str_ends_with($from, '@gmail.com')
                ? str_replace('@', '+cloudtest' . $suffix . $name . '@', $from) : 'smoke_' . $suffix . '_' . $name . '@example.test';
            $data['users'][] = ['username' => 'smoke_' . $suffix . '_' . $name, 'email' => $email, 'password' => bin2hex(random_bytes(24)), 'invite' => $invite];
        }
        $handle = fopen($path, 'x'); chmod($path, 0600); fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR)); fclose($handle);
        CLI::write('Private HTTP fixtures prepared; the permanent owner was preserved.'); return EXIT_SUCCESS;
    }
}
