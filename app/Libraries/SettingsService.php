<?php

namespace App\Libraries;

use App\Models\UserModel;
use App\Models\UserProfileModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use Config\Database;

class SettingsService
{
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

    public function settingsFor(int $userId): array
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \DomainException('User account was not found.');
        }

        return [
            'user'         => $user,
            'profile'      => $this->profileFor($userId),
            'notifications' => $this->notifications->summary($userId),
        ];
    }

    public function updateProfile(int $userId, array $input): array
    {
        $this->profileFor($userId);

        $displayName = mb_substr(trim((string) ($input['display_name'] ?? '')), 0, 120);
        $bio = mb_substr(trim((string) ($input['bio'] ?? '')), 0, 1000);

        $this->profiles->update($userId, [
            'display_name' => $displayName === '' ? null : $displayName,
            'bio'          => $bio === '' ? null : $bio,
        ]);

        $this->activity->record($userId, 'settings.profile.update', 'success', ['type' => 'user_profile', 'id' => $userId]);

        return $this->profileFor($userId);
    }

    public function updatePreferences(int $userId, array $input): array
    {
        $this->profileFor($userId);

        $view = (string) ($input['default_drive_view'] ?? 'list');
        $sort = (string) ($input['default_sort'] ?? 'name');
        $dir = (string) ($input['default_sort_dir'] ?? 'asc');
        $locale = (string) ($input['locale'] ?? 'en');

        $payload = [
            'locale' => in_array($locale, ['en', 'id'], true) ? $locale : 'en',
            'default_drive_view' => in_array($view, ['list', 'grid'], true) ? $view : 'list',
            'default_sort'       => in_array($sort, ['name', 'updated', 'size', 'type'], true) ? $sort : 'name',
            'default_sort_dir'   => $dir === 'desc' ? 'desc' : 'asc',
            'notify_in_app_announcements' => $this->boolInput($input, 'notify_in_app_announcements'),
            'notify_in_app_sharing'       => $this->boolInput($input, 'notify_in_app_sharing'),
            'notify_in_app_security'      => $this->boolInput($input, 'notify_in_app_security'),
            'email_announcements'         => $this->boolInput($input, 'email_announcements'),
            'email_sharing'               => $this->boolInput($input, 'email_sharing'),
            'email_updates'               => $this->boolInput($input, 'email_updates'),
        ];

        try {
            $this->profiles->update($userId, $payload);
        } catch (\Throwable $exception) {
            if (! str_contains(mb_strtolower($exception->getMessage()), 'locale')) {
                throw $exception;
            }

            unset($payload['locale']);
            $this->profiles->update($userId, $payload);
        }
        $this->activity->record($userId, 'settings.preferences.update', 'success', ['type' => 'user_profile', 'id' => $userId], [
            'email_enabled' => [
                'announcements' => (bool) $payload['email_announcements'],
                'sharing'       => (bool) $payload['email_sharing'],
                'updates'       => (bool) $payload['email_updates'],
            ],
        ]);

        return $this->profileFor($userId);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): void
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \DomainException('User account was not found.');
        }

        if (! password_verify($currentPassword, (string) $user['password_hash'])) {
            $this->activity->record($userId, 'settings.password.update', 'failure', ['type' => 'user', 'id' => $userId], [
                'reason' => 'current_password_mismatch',
            ]);

            throw new \DomainException('Current password is incorrect.');
        }

        $passwordMinLength = (new SystemSettingsService($this->db))->getInt('password_min_length', 8);
        if (strlen($newPassword) < $passwordMinLength) {
            throw new \DomainException('New password must be at least ' . $passwordMinLength . ' characters.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new \DomainException('Password confirmation does not match.');
        }

        $this->users->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);

        $this->activity->record($userId, 'settings.password.update', 'success', ['type' => 'user', 'id' => $userId]);
    }

    public function requestEmailChange(int $userId, string $newEmail): array
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new \DomainException('User account was not found.');
        }

        $newEmail = mb_strtolower(trim($newEmail));
        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Email address is invalid.');
        }

        if ($newEmail === mb_strtolower((string) $user['email'])) {
            throw new \DomainException('This email address is already used by your account.');
        }

        $exists = $this->db->table('users')
            ->where('email', $newEmail)
            ->where('id !=', $userId)
            ->countAllResults() > 0;
        if ($exists) {
            throw new \DomainException('Email address is already registered.');
        }

        $this->profileFor($userId);
        $token = bin2hex(random_bytes(32));
        $expires = Time::now()->addHours(24)->toDateTimeString();
        $verificationUrl = site_url('settings/email/verify/' . $token);

        $this->profiles->update($userId, [
            'pending_email'            => $newEmail,
            'pending_email_token_hash' => hash('sha256', $token),
            'pending_email_expires_at' => $expires,
        ]);

        $locale = $this->localeForUser($userId);
        $body = lang('App.email.verifyChangeIntro', [], $locale)
            . "\n\n"
            . lang('App.email.verifyFallback', [], $locale)
            . "\n\n"
            . $verificationUrl
            . "\n\n"
            . lang('App.email.verifyExpires', [$expires], $locale);
        $this->notifications->queueEmail($userId, $newEmail, 'email_verification', $userId, lang('App.email.verifySubject', [], $locale), $body);
        $this->activity->record($userId, 'settings.email.request', 'success', ['type' => 'user', 'id' => $userId], [
            'email_hash' => hash('sha256', $newEmail),
            'expires_at' => $expires,
        ]);

        return [
            'pending_email' => $newEmail,
            'expires_at'    => $expires,
        ];
    }

    public function confirmEmailChange(string $token): int
    {
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
        $email = (string) $profile['pending_email'];
        $exists = $this->db->table('users')
            ->where('email', $email)
            ->where('id !=', $userId)
            ->countAllResults() > 0;
        if ($exists) {
            throw new \DomainException('Email address is already registered.');
        }

        $this->db->transBegin();

        try {
            $this->users->update($userId, ['email' => $email]);
            $this->profiles->update($userId, [
                'pending_email'            => null,
                'pending_email_token_hash' => null,
                'pending_email_expires_at' => null,
                'email_verified_at'        => Time::now()->toDateTimeString(),
            ]);
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        $this->activity->record($userId, 'settings.email.confirm', 'success', ['type' => 'user', 'id' => $userId]);

        return $userId;
    }

    public function storeAvatar(int $userId, UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new \DomainException($file->getErrorString());
        }

        if ($file->getSize() > 2_097_152) {
            throw new \DomainException('Avatar file is too large.');
        }

        $mime = $file->getMimeType();
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];

        if (! isset($allowed[$mime])) {
            throw new \DomainException('Avatar file type is not allowed.');
        }

        if (@getimagesize($file->getTempName()) === false) {
            throw new \DomainException('Avatar file is not a valid image.');
        }

        $this->profileFor($userId);
        $profile = $this->profiles->find($userId);
        $directory = $this->avatarRoot() . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $userId;
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Avatar storage directory could not be created.');
        }

        $physicalName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $relative = 'users/' . $userId . '/' . $physicalName;

        if (! $file->move($directory, $physicalName, true)) {
            throw new \RuntimeException('Avatar could not be stored.');
        }

        if (! empty($profile['avatar_path'])) {
            $this->deleteAvatarPath((string) $profile['avatar_path']);
        }

        $this->profiles->update($userId, [
            'avatar_path' => $relative,
            'avatar_name' => mb_substr($file->getClientName(), 0, 190),
        ]);
        $this->activity->record($userId, 'settings.avatar.update', 'success', ['type' => 'user_profile', 'id' => $userId]);

        return $this->profileFor($userId);
    }

    public function deleteAvatar(int $userId): void
    {
        $profile = $this->profileFor($userId);
        if (! empty($profile['avatar_path'])) {
            $this->deleteAvatarPath((string) $profile['avatar_path']);
        }

        $this->profiles->update($userId, [
            'avatar_path' => null,
            'avatar_name' => null,
        ]);
        $this->activity->record($userId, 'settings.avatar.delete', 'success', ['type' => 'user_profile', 'id' => $userId]);
    }

    public function avatarFile(int $userId): ?array
    {
        $profile = $this->profileFor($userId);
        $relative = (string) ($profile['avatar_path'] ?? '');
        if ($relative === '' || preg_match('#(^|/)\.\.($|/)#', str_replace('\\', '/', $relative))) {
            return null;
        }

        $root = $this->avatarRoot();
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = realpath(dirname($path));
        if ($directory === false || ! str_starts_with($directory, $root) || ! is_file($path)) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        return [
            'path' => $path,
            'name' => (string) ($profile['avatar_name'] ?? 'avatar'),
            'mime' => $mime,
        ];
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

    private function boolInput(array $input, string $key): int
    {
        return isset($input[$key]) && in_array((string) $input[$key], ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    private function localeForUser(int $userId): string
    {
        $profile = $this->profileFor($userId);
        $locale = (string) ($profile['locale'] ?? session('locale') ?? 'en');

        return in_array($locale, ['en', 'id'], true) ? $locale : 'en';
    }

    private function avatarRoot(): string
    {
        $root = WRITEPATH . 'profile-avatars';
        if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new \RuntimeException('Avatar storage directory could not be created.');
        }

        $real = realpath($root);

        return $real === false ? $root : $real;
    }

    private function deleteAvatarPath(string $relative): void
    {
        if ($relative === '' || preg_match('#(^|/)\.\.($|/)#', str_replace('\\', '/', $relative))) {
            return;
        }

        $root = $this->avatarRoot();
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = realpath(dirname($path));

        if ($directory !== false && str_starts_with($directory, $root) && is_file($path)) {
            @unlink($path);
        }
    }
}
