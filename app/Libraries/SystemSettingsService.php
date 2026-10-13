<?php

namespace App\Libraries;

use App\Models\SystemSettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Auth;
use Config\Database;

class SystemSettingsService
{
    private const DEFINITIONS = [
        'app_name' => ['default' => 'Theaz Cloud', 'type' => 'string', 'category' => 'general'],
        'timezone' => ['default' => 'Asia/Jakarta', 'type' => 'string', 'category' => 'general'],
        'admin_contact_email' => ['default' => '', 'type' => 'string', 'category' => 'general'],
        'date_format' => ['default' => 'Y-m-d H:i', 'type' => 'string', 'category' => 'general'],

        'registration_public_enabled' => ['default' => '1', 'type' => 'boolean', 'category' => 'registration'],
        'invitation_registration_enabled' => ['default' => '1', 'type' => 'boolean', 'category' => 'registration'],
        'default_invitation_expires_days' => ['default' => '7', 'type' => 'integer', 'category' => 'registration'],
        'invitation_max_uses_default' => ['default' => '1', 'type' => 'integer', 'category' => 'registration'],
        'default_user_quota_bytes' => ['default' => '5368709120', 'type' => 'integer', 'category' => 'registration'],
        'max_admin_assignable_quota_bytes' => ['default' => '10737418240', 'type' => 'integer', 'category' => 'registration'],
        'email_verification_required' => ['default' => '1', 'type' => 'boolean', 'category' => 'registration'],

        'max_upload_size_bytes' => ['default' => '104857600', 'type' => 'integer', 'category' => 'storage'],
        'trash_retention_days' => ['default' => '30', 'type' => 'integer', 'category' => 'storage'],
        'version_retention_days' => ['default' => '90', 'type' => 'integer', 'category' => 'storage'],
        'storage_warning_threshold_percent' => ['default' => '85', 'type' => 'integer', 'category' => 'storage'],
        'max_preview_text_bytes' => ['default' => '524288', 'type' => 'integer', 'category' => 'storage'],

        'password_min_length' => ['default' => '10', 'type' => 'integer', 'category' => 'security'],
        'login_max_attempts' => ['default' => '5', 'type' => 'integer', 'category' => 'security'],
        'session_lifetime_minutes' => ['default' => '120', 'type' => 'integer', 'category' => 'security'],

        'notification_email_enabled' => ['default' => '0', 'type' => 'boolean', 'category' => 'notifications'],
        'smtp_configured' => ['default' => '0', 'type' => 'boolean', 'category' => 'notifications'],

        'maintenance_mode' => ['default' => '0', 'type' => 'boolean', 'category' => 'maintenance'],
        'maintenance_message' => ['default' => 'The service is temporarily unavailable for maintenance.', 'type' => 'string', 'category' => 'maintenance'],
        'maintenance_scheduled_at' => ['default' => '', 'type' => 'string', 'category' => 'maintenance'],
    ];

    private BaseConnection $db;

    private SystemSettingModel $settings;

    private ActivityLogger $activity;

    public function __construct(?BaseConnection $db = null, ?SystemSettingModel $settings = null, ?ActivityLogger $activity = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new SystemSettingModel();
        $this->activity = $activity ?? new ActivityLogger($this->db);
    }

    public function all(): array
    {
        $rows = $this->settings->findAll();
        $values = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = (string) $definition['default'];
        }

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }

            $values[$key] = $this->sanitizeValue($key, (string) ($row['value_text'] ?? ''));
        }

        return $values;
    }

    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public function get(string $key, ?string $default = null): string
    {
        $key = $this->normalizeKey($key);
        $row = $this->settings->find($key);

        if ($row === null) {
            return $default ?? (string) (self::DEFINITIONS[$key]['default'] ?? '');
        }

        return isset(self::DEFINITIONS[$key])
            ? $this->sanitizeValue($key, (string) ($row['value_text'] ?? ''))
            : (string) ($row['value_text'] ?? '');
    }

    public function getInt(string $key, ?int $default = null): int
    {
        return (int) $this->get($key, $default === null ? null : (string) $default);
    }

    public function getBool(string $key, ?bool $default = null): bool
    {
        return $this->get($key, $default === null ? null : ($default ? '1' : '0')) === '1';
    }

    public function isMaintenanceMode(): bool
    {
        return $this->getBool('maintenance_mode', false);
    }

    public function maintenanceMessage(): string
    {
        $message = trim($this->get('maintenance_message', (string) self::DEFINITIONS['maintenance_message']['default']));

        return $message !== '' ? $message : (string) self::DEFINITIONS['maintenance_message']['default'];
    }

    public function updateGeneral(int $actorId, array $input): array
    {
        $updates = [];

        foreach (array_keys(self::DEFINITIONS) as $key) {
            if (str_starts_with($key, 'maintenance_')) {
                continue;
            }

            if (! array_key_exists($key, $input)) {
                continue;
            }

            $updates[$key] = $this->sanitizeValue($key, (string) $input[$key]);
        }

        if ($updates === []) {
            throw new \DomainException('No settings were changed.');
        }

        $this->saveMany($updates, $actorId);
        $this->activity->record($actorId, 'admin.settings.update', 'success', ['type' => 'system_settings'], [
            'keys' => array_keys($updates),
        ]);

        return $this->all();
    }

    public function updateMaintenance(int $actorId, bool $enabled, string $message, string $scheduledAt = ''): array
    {
        $message = mb_substr(trim($message), 0, 500);
        if ($message === '') {
            $message = (string) self::DEFINITIONS['maintenance_message']['default'];
        }

        $this->saveMany([
            'maintenance_mode'    => $enabled ? '1' : '0',
            'maintenance_message' => $message,
            'maintenance_scheduled_at' => $this->sanitizeValue('maintenance_scheduled_at', $scheduledAt),
        ], $actorId);

        $this->activity->record($actorId, 'admin.maintenance.update', 'success', ['type' => 'system_settings'], [
            'enabled' => $enabled,
        ]);

        return $this->all();
    }

    private function saveMany(array $values, int $actorId): void
    {
        $now = Time::now()->toDateTimeString();

        $this->db->transBegin();

        try {
            foreach ($values as $key => $value) {
                $key = $this->normalizeKey((string) $key);
                $payload = [
                    'setting_key' => $key,
                    'value_text'  => $value,
                    'value_type'  => (string) (self::DEFINITIONS[$key]['type'] ?? 'string'),
                    'category'    => (string) (self::DEFINITIONS[$key]['category'] ?? 'general'),
                    'metadata_json' => null,
                    'updated_by'  => $actorId,
                    'updated_at'  => $now,
                ];

                if ($this->settings->find($key) === null) {
                    $this->settings->insert($payload);
                } else {
                    $this->settings->update($key, $payload);
                }
            }

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }

    private function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key));

        if (! preg_match('/\A[a-z0-9_.-]+\z/', $key)) {
            throw new \DomainException('Setting key is invalid.');
        }

        return mb_substr($key, 0, 100);
    }

    private function sanitizeValue(string $key, string $value): string
    {
        $value = trim($value);

        return match ($key) {
            'app_name' => mb_substr($value !== '' ? $value : 'Theaz Cloud', 0, 80),
            'timezone' => in_array($value, timezone_identifiers_list(), true) ? $value : throw new \DomainException('Timezone is invalid.'),
            'admin_contact_email' => $value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 254) : throw new \DomainException('Admin contact email is invalid.'),
            'date_format' => in_array($value, ['Y-m-d H:i', 'd/m/Y H:i', 'm/d/Y h:i A'], true) ? $value : 'Y-m-d H:i',
            'registration_public_enabled', 'invitation_registration_enabled', 'email_verification_required', 'notification_email_enabled', 'smtp_configured', 'maintenance_mode' => in_array($value, ['1', 'on', 'true', 'yes'], true) ? '1' : '0',
            'default_invitation_expires_days' => (string) min(max((int) $value, 1), 365),
            'invitation_max_uses_default' => (string) min(max((int) $value, 1), 1000),
            'default_user_quota_bytes' => (string) min(max((int) $value, 1048576), 10995116277760),
            'max_admin_assignable_quota_bytes' => (string) min(max((int) $value, 1048576), 109951162777600),
            'max_upload_size_bytes' => (string) min(max((int) $value, 1024), 1073741824),
            'trash_retention_days' => (string) min(max((int) $value, 0), 3650),
            'version_retention_days' => (string) min(max((int) $value, 0), 3650),
            'storage_warning_threshold_percent' => (string) min(max((int) $value, 1), 100),
            'max_preview_text_bytes' => (string) min(max((int) $value, 1024), 2_097_152),
            'password_min_length' => (string) min(max((int) $value, 8), 128),
            'login_max_attempts' => (string) min(max((int) $value, 1), 50),
            'session_lifetime_minutes' => (string) min(max((int) $value, 15), 10080),
            'maintenance_message' => mb_substr($value !== '' ? $value : (string) self::DEFINITIONS['maintenance_message']['default'], 0, 500),
            'maintenance_scheduled_at' => $value === '' || strtotime($value) !== false ? mb_substr($value, 0, 32) : '',
            default => mb_substr($value, 0, 1000),
        };
    }

    public function defaultUserQuotaBytes(): int
    {
        $configured = $this->getInt('default_user_quota_bytes', config(Auth::class)->defaultQuotaBytes);

        return max(1048576, $configured);
    }
}
