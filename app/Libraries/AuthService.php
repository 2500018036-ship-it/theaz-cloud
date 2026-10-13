<?php

namespace App\Libraries;

use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use Config\Auth;

class AuthService
{
    private UserModel $users;

    public function __construct(?UserModel $users = null)
    {
        $this->users = $users ?? new UserModel();
    }

    public function attemptLogin(string $identifier, string $password): array
    {
        $user = $this->users->findForLogin($identifier);

        if ($user === null || ! password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'reason' => 'invalid_credentials'];
        }

        if ($user['status'] === EmailVerificationService::PENDING_STATUS) {
            return ['ok' => false, 'reason' => 'email_unverified', 'user' => $user];
        }

        if ($user['status'] !== 'active') {
            return ['ok' => false, 'reason' => 'inactive_account', 'user' => $user];
        }

        if (! in_array($user['role'], config(Auth::class)->roles, true)) {
            return ['ok' => false, 'reason' => 'invalid_role', 'user' => $user];
        }

        $this->users->update($user['id'], ['last_login_at' => Time::now()->toDateTimeString()]);

        return ['ok' => true, 'user' => $user];
    }

    public function loginUser(array $user): void
    {
        session()->regenerate(true);
        session()->set([
            'is_logged_in' => true,
            'user_id'      => (int) $user['id'],
            'username'     => $user['username'],
            'email'        => $user['email'],
            'role'         => $user['role'],
            'auth_started_at' => time(),
        ]);
    }

    public function logout(): void
    {
        session()->remove([
            'is_logged_in',
            'user_id',
            'username',
            'email',
            'role',
            'auth_started_at',
        ]);
        session()->destroy();
    }

    public function redirectPathFor(array $user): string
    {
        return in_array($user['role'], ['super_admin', 'admin'], true) ? '/admin/dashboard' : '/dashboard';
    }
}
