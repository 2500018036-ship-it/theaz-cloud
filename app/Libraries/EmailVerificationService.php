<?php

namespace App\Libraries;

use App\Models\UserModel;
use App\Models\UserProfileModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Database;

class EmailVerificationService
{
    public const PENDING_STATUS = 'pending_verification';

    private BaseConnection $db;

    private UserModel $users;

    private UserProfileModel $profiles;

    private NotificationService $notifications;

    private ActivityLogger $activity;

    public function __construct(
        ?BaseConnection $db = null,
        ?UserModel $users = null,
        ?UserProfileModel $profiles = null,
        ?NotificationService $notifications = null,
        ?ActivityLogger $activity = null,
    ) {
        $this->db            = $db ?? Database::connect();
        $this->users         = $users ?? new UserModel();
        $this->profiles      = $profiles ?? new UserProfileModel();
        $this->notifications = $notifications ?? new NotificationService($this->db);
        $this->activity      = $activity ?? new ActivityLogger($this->db);
    }

    public function issue(int $userId, string $email, string $eventType = 'email_verification', int $ttlHours = 24): array
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Email address is invalid.');
        }

        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \DomainException('User account was not found.');
        }

        $token = bin2hex(random_bytes(32));
        $expires = Time::now()->addHours(max(1, min($ttlHours, 168)))->toDateTimeString();
        $verificationUrl = site_url('email/verify/' . $token);

        $this->profileFor($userId);
        // A resend invalidates earlier links and prevents delivery of obsolete queued mail.
        $this->db->table('email_queue')->where('user_id', $userId)
            ->whereIn('event_type', ['email_verification', 'admin_email_verification', 'email_change_verification'])
            ->where('status', 'queued')->update(['status' => 'cancelled']);
        $this->profiles->update($userId, [
            'pending_email'            => $email,
            'pending_email_token_hash' => hash('sha256', $token),
            'pending_email_expires_at' => $expires,
        ]);

        $locale = $this->localeForUser($userId);
        $isAdminVerification = $eventType === 'admin_email_verification';
        $subject = $isAdminVerification
            ? lang('App.email.verifyAdminSubject', [], $locale)
            : lang('App.email.verifySubject', [], $locale);
        $body = $this->verificationEmailBody(
            (string) ($user['username'] ?? 'there'),
            $verificationUrl,
            $expires,
            $isAdminVerification,
            $locale,
        );

        $queueId = $this->notifications->queueEmail($userId, $email, $eventType, $userId, $subject, $body);
        $this->activity->record($userId, 'auth.email.verification.issue', 'success', ['type' => 'user', 'id' => $userId], [
            'event_type' => $eventType,
            'email_hash' => hash('sha256', $email),
            'expires_at' => $expires,
        ], $userId);

        return [
            'token' => $token,
            'expires_at' => $expires,
            'queue_id' => $queueId,
        ];
    }

    public function confirm(string $token): int
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new \DomainException('Email verification link is invalid or expired.');
        }
        $hash = hash('sha256', trim($token));
        $profile = $this->db->table('user_profiles')
            ->where('pending_email_token_hash', $hash)
            ->where('pending_email_expires_at >=', Time::now()->toDateTimeString())
            ->get()
            ->getRowArray();

        if ($profile === null || empty($profile['pending_email'])) {
            throw new \DomainException('Email verification link is invalid or expired.');
        }

        $userId = (int) $profile['user_id'];
        $email = mb_strtolower((string) $profile['pending_email']);
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \DomainException('User account was not found.');
        }

        $exists = $this->db->table('users')
            ->where('email', $email)
            ->where('id !=', $userId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
        if ($exists) {
            throw new \DomainException('Email address is already registered.');
        }

        $this->db->transBegin();

        try {
            // Claim the token atomically. Concurrent requests cannot reuse it.
            $this->db->table('user_profiles')->where('user_id', $userId)
                ->where('pending_email_token_hash', $hash)
                ->where('pending_email_expires_at >=', Time::now()->toDateTimeString())
                ->update(['pending_email_token_hash' => null]);
            if ($this->db->affectedRows() !== 1) {
                throw new \DomainException('Email verification link is invalid or expired.');
            }
            $payload = ['email' => $email];
            if ((string) $user['status'] === self::PENDING_STATUS) {
                $payload['status'] = 'active';
            }

            $payload['updated_at'] = Time::now()->toDateTimeString();
            $this->db->table('users')
                ->where('id', $userId)
                ->update($payload);
            $this->profiles->update($userId, [
                'pending_email'            => null,
                'pending_email_token_hash' => null,
                'pending_email_expires_at' => null,
                'email_verified_at'        => Time::now()->toDateTimeString(),
            ]);
            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Email verification could not be saved.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        $this->activity->record($userId, 'auth.email.verification.confirm', 'success', ['type' => 'user', 'id' => $userId], [
            'email_hash' => hash('sha256', $email),
        ], $userId);

        return $userId;
    }

    public function resendForIdentifier(string $identifier): ?array
    {
        $identifier = mb_strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $user = $this->db->table('users')
            ->groupStart()
                ->where('email', $identifier)
                ->orWhere('username', $identifier)
            ->groupEnd()
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($user === null || ! in_array((string) ($user['status'] ?? ''), [self::PENDING_STATUS, 'active'], true)
            || ($user['role'] === 'super_admin') || ! $this->requiresVerification($user)) {
            return null;
        }

        return $this->issue((int) $user['id'], (string) $user['email']);
    }

    public function requiresVerification(array $user): bool
    {
        if ($user['role'] === 'super_admin' || config(\Config\Auth::class)->emailVerificationDeferred) {
            return false;
        }
        $profile = $this->profiles->find((int) $user['id']);
        return empty($profile['email_verified_at']);
    }

    private function profileFor(int $userId): array
    {
        $profile = $this->profiles->find($userId);
        if ($profile !== null) {
            return $profile;
        }

        $this->profiles->insert(['user_id' => $userId]);

        return $this->profiles->find($userId) ?? ['user_id' => $userId];
    }

    private function localeForUser(int $userId): string
    {
        $profile = $this->profiles->find($userId);
        $locale = (string) ($profile['locale'] ?? session('locale') ?? 'en');

        return in_array($locale, ['en', 'id'], true) ? $locale : 'en';
    }

    private function verificationEmailBody(string $username, string $verificationUrl, string $expires, bool $adminAccount, string $locale): string
    {
        $safeGreeting = htmlspecialchars(lang('App.email.verifyGreeting', [$username], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeIntro = htmlspecialchars(lang($adminAccount ? 'App.email.verifyAdminIntro' : 'App.email.verifyIntro', [], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeButton = htmlspecialchars(lang('App.email.verifyButton', [], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeFallback = htmlspecialchars(lang('App.email.verifyFallback', [], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeExpiresMessage = htmlspecialchars(lang('App.email.verifyExpires', [$expires], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html><body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.55;">'
            . '<p>' . $safeGreeting . '</p>'
            . '<p>' . $safeIntro . '</p>'
            . '<p><a href="' . $safeUrl . '" style="display: inline-block; padding: 12px 18px; background: #0d6efd; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;">' . $safeButton . '</a></p>'
            . '<p>' . $safeFallback . '</p>'
            . '<p><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>'
            . '<p>' . $safeExpiresMessage . '</p>'
            . '</body></html>';
    }
}
