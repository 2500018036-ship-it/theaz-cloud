<?php

namespace App\Controllers;

use App\Libraries\AuthService;
use App\Libraries\ActivityLogger;
use App\Libraries\EmailVerificationService;
use App\Libraries\InvitationService;
use App\Libraries\SystemSettingsService;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Auth;
use Config\Database;

class AuthController extends BaseController
{
    public function loginForm(): string
    {
        return view('auth/login', [
            'title' => lang('UI.t9d6322c1f4d9'),
        ]);
    }

    public function login(): RedirectResponse
    {
        $authConfig = config(Auth::class);
        $identifier = trim((string) $this->request->getPost('identifier'));
        $throttleKey = 'login.' . md5($this->request->getIPAddress() . '|' . mb_strtolower($identifier));

        if (! service('throttler')->check($throttleKey, $this->loginMaxAttempts($authConfig), $authConfig->loginDecaySeconds)) {
            (new ActivityLogger())->record(null, 'auth.login', 'failure', [], [
                'reason'          => 'rate_limited',
                'identifier_hash' => hash('sha256', mb_strtolower($identifier)),
            ]);

            return redirect()
                ->to('/login')
                ->withInput()
                ->with('error', 'Too many login attempts. Please wait before trying again.');
        }

        $rules = [
            'identifier' => 'required|max_length[254]',
            'password'   => 'required|max_length[255]',
        ];

        if (! $this->validate($rules)) {
            (new ActivityLogger())->record(null, 'auth.login', 'failure', [], [
                'reason'          => 'validation_failed',
                'identifier_hash' => hash('sha256', mb_strtolower($identifier)),
            ]);

            return redirect()->to('/login')->withInput()->with('errors', $this->validator->getErrors());
        }

        $result = (new AuthService())->attemptLogin(
            $identifier,
            (string) $this->request->getPost('password'),
        );

        if (! $result['ok']) {
            $message = match ($result['reason']) {
                'inactive_account' => 'This account is not active.',
                'email_unverified' => 'Please verify your email address before signing in.',
                default => 'The provided credentials are invalid.',
            };

            (new ActivityLogger())->record(isset($result['user']) ? (int) $result['user']['id'] : null, 'auth.login', 'failure', [], [
                'reason'          => $result['reason'],
                'identifier_hash' => hash('sha256', mb_strtolower($identifier)),
            ]);

            return redirect()->to('/login')->withInput()->with('error', $message);
        }

        $auth = new AuthService();
        $auth->loginUser($result['user']);
        (new ActivityLogger())->record((int) $result['user']['id'], 'auth.login', 'success');

        return redirect()->to($auth->redirectPathFor($result['user']));
    }

    public function registerForm(): string
    {
        return view('auth/register', [
            'title' => lang('UI.tbb7234ec1245'),
        ]);
    }

    public function register(): RedirectResponse
    {
        $passwordMinLength = $this->passwordMinLength();
        $rules = [
            'invitation_code'  => 'required|min_length[16]|max_length[128]|regex_match[/\A[A-Za-z0-9\s-]+\z/]',
            'username'         => 'required|min_length[3]|max_length[80]|regex_match[/\A[a-zA-Z0-9._-]+\z/]|is_unique[users.username]',
            'email'            => 'required|valid_email|max_length[254]|is_unique[users.email]',
            'password'         => 'required|min_length[' . $passwordMinLength . ']|max_length[255]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/register')->withInput()->with('errors', $this->validator->getErrors());
        }

        $db = Database::connect();
        $users = new UserModel();
        $invitations = new InvitationService(db: $db);
        $requiresVerification = ! config(Auth::class)->emailVerificationDeferred;

        $db->transBegin();

        try {
            $invitation = $invitations->consumeForType((string) $this->request->getPost('invitation_code'), 'user');
            if ($invitation === null) {
                $db->transRollback();

                return redirect()
                    ->to('/register')
                    ->withInput()
                    ->with('error', 'Invitation code is invalid, expired, revoked, or already used.');
            }

            $quotaBytes = (int) ($invitation['storage_quota_bytes'] ?? 0);
            if ($quotaBytes <= 0) {
                $quotaBytes = (new SystemSettingsService($db))->defaultUserQuotaBytes();
            }

            $userId = $users->insert([
                'username'            => mb_strtolower(trim((string) $this->request->getPost('username'))),
                'email'               => mb_strtolower(trim((string) $this->request->getPost('email'))),
                'password_hash'       => password_hash((string) $this->request->getPost('password'), PASSWORD_DEFAULT),
                'role'                => 'user',
                'status'              => $requiresVerification ? EmailVerificationService::PENDING_STATUS : 'active',
                'storage_quota_bytes' => $quotaBytes,
                'storage_used_bytes'  => 0,
            ], true);

            if (! is_int($userId)) {
                throw new \RuntimeException(implode(' ', $users->errors()));
            }

            $invitations->attachAcceptedUser((int) $invitation['id'], $userId);
            if ($requiresVerification) {
                (new EmailVerificationService($db))->issue($userId, mb_strtolower(trim((string) $this->request->getPost('email'))));
            }
            if ($db->transStatus() === false) { throw new \RuntimeException('Registration could not be saved.'); }
            $db->transCommit();
        } catch (\Throwable $exception) {
            $db->transRollback();

            log_message('error', 'Registration failed: {message}', ['message' => $exception->getMessage()]);

            return redirect()
                ->to('/register')
                ->withInput()
                ->with('error', 'Registration could not be completed. Please check your details and try again.');
        }

        return redirect()
            ->to('/login')
            ->with('message', $requiresVerification ? lang('App.email.registrationQueued', [mb_strtolower(trim((string) $this->request->getPost('email')))]) : 'Account created. You can sign in now.');
    }

    public function verifyEmail(string $token): RedirectResponse
    {
        try {
            (new EmailVerificationService())->confirm($token);

            return redirect()->to('/login')->with('message', 'Email verified. You can sign in now.');
        } catch (\Throwable $exception) {
            return redirect()->to('/login')->with('error', lang('App.email.invalidLink'));
        }
    }

    public function resendVerification(): RedirectResponse
    {
        $identifier = trim((string) $this->request->getPost('identifier'));
        $throttleKey = 'email.verify.resend.' . md5($this->request->getIPAddress() . '|' . mb_strtolower($identifier));

        if (! service('throttler')->check($throttleKey, 3, 900)) {
            return redirect()->to('/login')->with('error', 'Please wait before requesting another verification email.');
        }

        try {
            (new EmailVerificationService())->resendForIdentifier($identifier);
        } catch (\Throwable $exception) {
            log_message('error', 'Verification resend failed: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to('/login')->with('message', 'If the account is waiting for verification, we sent a new verification email or queued it for delivery.');
    }

    public function logout(): RedirectResponse
    {
        $userId = is_numeric(session('user_id')) ? (int) session('user_id') : null;
        (new ActivityLogger())->record($userId, 'auth.logout', 'success');
        (new AuthService())->logout();

        return redirect()->to('/login')->with('message', 'You have been signed out.');
    }

    private function loginMaxAttempts(Auth $authConfig): int
    {
        try {
            return max(1, (new SystemSettingsService())->getInt('login_max_attempts', $authConfig->loginMaxAttempts));
        } catch (\Throwable) {
            return $authConfig->loginMaxAttempts;
        }
    }

    private function passwordMinLength(): int
    {
        try {
            return max(8, (new SystemSettingsService())->getInt('password_min_length', 10));
        } catch (\Throwable) {
            return 10;
        }
    }

}
