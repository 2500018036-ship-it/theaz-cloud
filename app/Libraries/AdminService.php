<?php

namespace App\Libraries;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Auth;
use Config\CloudDrive;
use Config\Database;

class AdminService
{
    private BaseConnection $db;

    private CloudDrive $driveConfig;

    private ActivityLogger $activity;

    public function __construct(?BaseConnection $db = null, ?CloudDrive $driveConfig = null, ?ActivityLogger $activity = null)
    {
        $this->db = $db ?? Database::connect();
        $this->driveConfig = $driveConfig ?? config(CloudDrive::class);
        $this->activity = $activity ?? new ActivityLogger($this->db, $this->driveConfig);
    }

    public function dashboard(): array
    {
        return [
            'users'       => $this->userMetrics(),
            'drive'       => $this->driveMetrics(),
            'storage'     => $this->storageMetrics(),
            'invitations' => $this->invitationMetrics(),
            'health'      => $this->systemHealth(),
            'activity'    => $this->recentActivity(8),
            'failures'    => $this->recentFailures(24),
            'quota_users' => $this->quotaUpgradeUsers(8),
        ];
    }

    public function userMetrics(): array
    {
        $row = $this->db->table('users')
            ->select('COUNT(*) AS total', false)
            ->select("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active", false)
            ->select("SUM(CASE WHEN status != 'active' THEN 1 ELSE 0 END) AS inactive", false)
            ->select("SUM(CASE WHEN role IN ('admin', 'super_admin') THEN 1 ELSE 0 END) AS admins", false)
            ->select("SUM(CASE WHEN role = 'super_admin' THEN 1 ELSE 0 END) AS super_admins", false)
            ->select("SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS users", false)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray() ?? [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'admins'   => (int) ($row['admins'] ?? 0),
            'super_admins' => (int) ($row['super_admins'] ?? 0),
            'users'    => (int) ($row['users'] ?? 0),
        ];
    }

    public function driveMetrics(): array
    {
        $items = $this->db->table('drive_items')
            ->select('COUNT(*) AS total', false)
            ->select("SUM(CASE WHEN type = 'file' THEN 1 ELSE 0 END) AS files", false)
            ->select("SUM(CASE WHEN type = 'folder' THEN 1 ELSE 0 END) AS folders", false)
            ->select("SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS trashed", false)
            ->select('COALESCE(SUM(size_bytes), 0) AS active_item_bytes', false)
            ->get()
            ->getRowArray() ?? [];

        $version = $this->db->table('drive_file_versions')
            ->select('COUNT(*) AS versions', false)
            ->select('COALESCE(SUM(size_bytes), 0) AS version_bytes', false)
            ->get()
            ->getRowArray() ?? [];

        return [
            'total'             => (int) ($items['total'] ?? 0),
            'files'             => (int) ($items['files'] ?? 0),
            'folders'           => (int) ($items['folders'] ?? 0),
            'trashed'           => (int) ($items['trashed'] ?? 0),
            'active_item_bytes' => (int) ($items['active_item_bytes'] ?? 0),
            'versions'          => (int) ($version['versions'] ?? 0),
            'version_bytes'     => (int) ($version['version_bytes'] ?? 0),
        ];
    }

    public function storageMetrics(): array
    {
        $usage = $this->db->table('users')
            ->select('COALESCE(SUM(storage_used_bytes), 0) AS used_bytes', false)
            ->select('COALESCE(SUM(storage_quota_bytes), 0) AS quota_bytes', false)
            ->where('deleted_at', null)
            ->where('role', AdminAuthorization::ROLE_USER)
            ->get()
            ->getRowArray() ?? [];
        $drive = $this->driveMetrics();

        $root = $this->storageRoot();
        $free = is_dir($root) ? @disk_free_space($root) : false;
        $total = is_dir($root) ? @disk_total_space($root) : false;
        $writable = is_dir($root) && is_writable($root);
        $used = (int) ($usage['used_bytes'] ?? 0);
        $quota = (int) ($usage['quota_bytes'] ?? 0);
        $hasDiskMetrics = is_numeric($free) && is_numeric($total);

        return [
            'used_bytes'        => $used,
            'quota_bytes'       => $quota,
            'quota_available'   => max(0, $quota - $used),
            'quota_percentage'  => $quota > 0 ? min(100, round(($used / $quota) * 100, 2)) : 0,
            'disk_free_bytes'   => is_numeric($free) ? (int) $free : null,
            'disk_total_bytes'  => is_numeric($total) ? (int) $total : null,
            'disk_used_bytes'   => $hasDiskMetrics ? max(0, (int) $total - (int) $free) : null,
            'disk_percentage'   => $hasDiskMetrics && (float) $total > 0 ? min(100, round(((((float) $total - (float) $free) / (float) $total) * 100), 2)) : null,
            'app_managed_bytes' => (int) ($drive['active_item_bytes'] ?? 0) + (int) ($drive['version_bytes'] ?? 0),
            'warning_threshold' => (new SystemSettingsService($this->db))->getInt('storage_warning_threshold_percent', 85),
            'measured_at'       => Time::now()->toDateTimeString(),
            'storage_root'      => $root,
            'storage_writable'  => $writable,
        ];
    }

    public function invitationMetrics(): array
    {
        $rows = $this->db->table('invitation_codes')
            ->select('status, COUNT(*) AS total', false)
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $metrics = ['active' => 0, 'consumed' => 0, 'revoked' => 0, 'expired' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $metrics)) {
                $metrics[$status] = (int) $row['total'];
            }
        }

        return $metrics;
    }

    public function systemHealth(): array
    {
        $databaseOk = true;
        $databaseMessage = 'Connected';

        try {
            $this->db->query('SELECT 1');
        } catch (\Throwable $exception) {
            $databaseOk = false;
            $databaseMessage = 'Unavailable';
        }

        $storage = $this->storageMetrics();

        return [
            'database' => [
                'ok'      => $databaseOk,
                'message' => $databaseMessage,
            ],
            'storage' => [
                'ok'      => (bool) $storage['storage_writable'],
                'message' => $storage['storage_writable'] ? 'Writable' : 'Not writable',
            ],
            'application' => [
                'name'    => 'Theaz Cloud',
                'version' => defined('CI_VERSION') ? CI_VERSION : 'Unknown',
                'php'     => PHP_VERSION,
            ],
        ];
    }

    public function recentActivity(int $limit = 10): array
    {
        return $this->db->table('activity_logs log')
            ->select('log.*, actor.username AS actor_username, actor.email AS actor_email')
            ->join('users actor', 'actor.id = log.actor_id', 'left')
            ->orderBy('log.created_at', 'DESC')
            ->orderBy('log.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    public function recentFailures(int $hours = 24): array
    {
        $since = Time::now()->subHours($hours)->toDateTimeString();
        $count = (int) $this->db->table('activity_logs')
            ->where('status', 'failure')
            ->where('created_at >=', $since)
            ->countAllResults();

        return [
            'count' => $count,
            'hours' => $hours,
        ];
    }

    public function quotaUpgradeUsers(int $limit = 8): array
    {
        return $this->db->table('users')
            ->select('id, username, email, role, status, storage_used_bytes, storage_quota_bytes, last_login_at')
            ->where('deleted_at', null)
            ->where('role', AdminAuthorization::ROLE_USER)
            ->orderBy('storage_used_bytes', 'DESC')
            ->orderBy('last_login_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min($limit, 20)))
            ->get()
            ->getResultArray();
    }

    public function listUsers(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 20)), 100);
        $query = trim((string) ($filters['q'] ?? ''));
        $role = trim((string) ($filters['role'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        $builderFactory = function () use ($query, $role, $status) {
            $builder = $this->db->table('users')
                ->where('deleted_at', null);

            if ($query !== '') {
                $builder->groupStart()
                    ->like('username', $query)
                    ->orLike('email', $query)
                    ->groupEnd();
            }

            if (in_array($role, ['super_admin', 'admin', 'user'], true)) {
                $builder->where('role', $role);
            }

            if (in_array($status, ['active', 'inactive'], true)) {
                $builder->where('status', $status);
            }

            return $builder;
        };

        $total = (int) $builderFactory()->countAllResults();
        $rows = $builderFactory()
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items' => $rows,
            'pager' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function userDetail(int $userId): array
    {
        $user = $this->db->table('users')
            ->where('id', $userId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($user === null) {
            throw new \DomainException('User was not found.');
        }

        $items = $this->db->table('drive_items')
            ->select('COUNT(*) AS total', false)
            ->select("SUM(CASE WHEN type = 'file' THEN 1 ELSE 0 END) AS files", false)
            ->select("SUM(CASE WHEN type = 'folder' THEN 1 ELSE 0 END) AS folders", false)
            ->select("SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS trashed", false)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray() ?? [];

        $shares = [
            'shared_by_user' => (int) $this->db->table('drive_shares')->where('user_id', $userId)->where('revoked_at', null)->countAllResults(),
            'shared_to_user' => (int) $this->db->table('drive_shares')->where('shared_with_user_id', $userId)->where('revoked_at', null)->countAllResults(),
        ];

        return [
            'user' => $user,
            'items' => [
                'total'   => (int) ($items['total'] ?? 0),
                'files'   => (int) ($items['files'] ?? 0),
                'folders' => (int) ($items['folders'] ?? 0),
                'trashed' => (int) ($items['trashed'] ?? 0),
            ],
            'shares' => $shares,
            'activity' => $this->db->table('activity_logs log')
                ->select('log.*, actor.username AS actor_username, actor.email AS actor_email')
                ->join('users actor', 'actor.id = log.actor_id', 'left')
                ->groupStart()
                    ->where('log.actor_id', $userId)
                    ->orWhere('log.subject_user_id', $userId)
                    ->orWhere('log.target_owner_id', $userId)
                ->groupEnd()
                ->orderBy('log.created_at', 'DESC')
                ->orderBy('log.id', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray(),
        ];
    }

    public function updateUserStatus(int $adminId, int $targetUserId, string $status): array
    {
        (new AdminAuthorization($this->db))->requirePermission($adminId, 'users.manage');
        $status = strtolower(trim($status));
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new \DomainException('Status is not available.');
        }

        $actor = $this->findUser($adminId);
        $user = $this->findUser($targetUserId);
        if ($user['role'] === 'super_admin') {
            throw new \DomainException('The primary administrator is permanent.');
        }
        if ($adminId === $targetUserId && $status !== 'active') {
            throw new \DomainException('You cannot deactivate your own admin session.');
        }

        if ($user['role'] === AdminAuthorization::ROLE_SUPER_ADMIN) {
            if ($actor['role'] !== AdminAuthorization::ROLE_SUPER_ADMIN) {
                throw new \DomainException('Only a super admin can change another super admin account.');
            }

            if ($user['status'] === 'active' && $status !== 'active' && $this->activeSuperAdminCount() <= 1) {
                throw new \DomainException('At least one active super admin must remain.');
            }
        }

        if (in_array($user['role'], ['admin', 'super_admin'], true) && $user['status'] === 'active' && $status !== 'active' && $this->activeAdminCount() <= 1) {
            throw new \DomainException('At least one active administrator must remain.');
        }

        if ($status === 'active' && (new EmailVerificationService($this->db))->requiresVerification($user)) {
            throw new \DomainException('This user must verify their email before activation.');
        }
        (new UserModel())->update($targetUserId, ['status' => $status]);

        $updated = $this->findUser($targetUserId);
        $this->activity->record($adminId, 'admin.user.status', 'success', [
            'type' => 'user',
            'id'   => $targetUserId,
            'name' => $updated['username'],
        ], [
            'old_status' => $user['status'],
            'new_status' => $status,
        ], $targetUserId);

        return $updated;
    }

    public function updateUserRole(int $adminId, int $targetUserId, string $role): array
    {
        throw new \DomainException('Account roles are permanent.');
    }

    public function updateUserQuota(int $adminId, int $targetUserId, int $quotaBytes): array
    {
        (new AdminAuthorization($this->db))->requirePermission($adminId, 'users.manage');
        if ($quotaBytes < 0) {
            throw new \DomainException('Quota must be positive.');
        }

        $user = $this->findUser($targetUserId);
        if ((string) $user['role'] !== AdminAuthorization::ROLE_USER) {
            throw new \DomainException('Administrator accounts do not use personal storage quotas.');
        }

        if ($quotaBytes < (int) $user['storage_used_bytes']) {
            throw new \DomainException('Quota cannot be lower than current usage.');
        }

        (new UserModel())->update($targetUserId, ['storage_quota_bytes' => $quotaBytes]);

        $updated = $this->findUser($targetUserId);
        $this->activity->record($adminId, 'admin.user.quota', 'success', [
            'type' => 'user',
            'id'   => $targetUserId,
            'name' => $updated['username'],
        ], [
            'old_quota_bytes' => (int) $user['storage_quota_bytes'],
            'new_quota_bytes' => $quotaBytes,
        ], $targetUserId);

        return $updated;
    }

    public function listInvitations(array $filters = []): array
    {
        (new InvitationService(db: $this->db))->expireStale();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 20)), 100);
        $query = trim((string) ($filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));

        $builderFactory = function () use ($query, $status) {
            $builder = $this->db->table('invitation_codes invitation')
                ->select('invitation.*, creator.email AS created_by_email, revoker.email AS revoked_by_email')
                ->join('users AS creator', 'creator.id = invitation.created_by', 'left')
                ->join('users AS revoker', 'revoker.id = invitation.revoked_by', 'left')
                ->where('invitation.deleted_at', null)
                ->where('invitation.invitation_type', 'user');

            if ($query !== '') {
                $builder->like('invitation.label', $query);
            }

            if (in_array($status, ['active', 'consumed', 'revoked', 'expired'], true)) {
                $builder->where('invitation.status', $status);
            }

            return $builder;
        };

        $total = (int) $builderFactory()->countAllResults();
        $rows = $builderFactory()
            ->orderBy('invitation.created_at', 'DESC')
            ->orderBy('invitation.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items' => $rows,
            'pager' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function updateInvitation(int $adminId, int $invitationId, ?string $label, int $maxUses, ?string $expiresAt, ?int $quotaBytes = null): array
    {
        $invitation = $this->db->table('invitation_codes')
            ->where('deleted_at', null)
            ->where('id', $invitationId)
            ->get()
            ->getRowArray();

        if ($invitation === null) {
            throw new \DomainException('Invitation was not found.');
        }

        if ($maxUses < (int) $invitation['use_count']) {
            throw new \DomainException('Maximum uses cannot be lower than current usage.');
        }

        if ($invitation['status'] === 'revoked' || $invitation['status'] === 'consumed') {
            throw new \DomainException('Finalized invitations cannot be edited.');
        }

        $status = 'active';
        if ($expiresAt !== null && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            $status = 'expired';
        }

        $this->db->table('invitation_codes')
            ->where('id', $invitationId)
            ->update([
                'label'      => $label,
                'max_uses'   => $maxUses,
                'expires_at' => $expiresAt,
                'storage_quota_bytes' => $quotaBytes,
                'status'     => $status,
                'updated_at' => Time::now()->toDateTimeString(),
            ]);

        $updated = $this->db->table('invitation_codes')->where('id', $invitationId)->get()->getRowArray();
        $this->activity->record($adminId, 'admin.invitation.update', 'success', [
            'type' => 'invitation',
            'id'   => $invitationId,
            'name' => $label ?? 'Unlabeled invitation',
        ], [
            'max_uses'   => $maxUses,
            'expires_at' => $expiresAt,
            'quota_bytes' => $quotaBytes,
            'status'     => $status,
        ]);

        return $updated ?? [];
    }

    public function storageUsers(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 20)), 100);

        $builderFactory = fn () => $this->db->table('users')
            ->where('deleted_at', null)
            ->where('role', AdminAuthorization::ROLE_USER);

        $total = (int) $builderFactory()->countAllResults();
        $rows = $builderFactory()
            ->orderBy('storage_used_bytes', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items' => $rows,
            'pager' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    private function findUser(int $userId): array
    {
        $user = (new UserModel())->where('id', $userId)->where('deleted_at', null)->first();
        if ($user === null) {
            throw new \DomainException('User was not found.');
        }

        return $user;
    }

    private function activeAdminCount(): int
    {
        return (int) $this->db->table('users')
            ->where('deleted_at', null)
            ->whereIn('role', ['admin', 'super_admin'])
            ->where('status', 'active')
            ->countAllResults();
    }

    private function activeSuperAdminCount(): int
    {
        return (new AdminAuthorization($this->db))->activeSuperAdminCount();
    }

    private function storageRoot(): string
    {
        $path = $this->driveConfig->storagePath;
        if (! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/|\\\\\\\\)/', $path)) {
            $path = ROOTPATH . trim($path, '\\/');
        }

        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            return rtrim($path, '\\/');
        }

        $real = realpath($path);

        return $real === false ? rtrim($path, '\\/') : $real;
    }
}
