<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AdminAuthorization
{
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public const PERMISSIONS = [
        'admin.dashboard',
        'users.view',
        'users.manage',
        'invitations.manage',
        'storage.view',
        'activity_logs.view',
        'activity_logs.manage',
        'notifications.manage',
        'system_settings.view',
        'system_settings.manage',
        'admins.manage',
        'language.manage',
    ];

    public const PERMISSION_LABELS = [
        'admin.dashboard' => 'Access admin dashboard',
        'users.view' => 'View users',
        'users.manage' => 'Manage users',
        'invitations.manage' => 'Manage user invitations',
        'storage.view' => 'View storage monitoring',
        'activity_logs.view' => 'View activity logs',
        'activity_logs.manage' => 'Delete activity logs',
        'notifications.manage' => 'Manage announcements',
        'system_settings.view' => 'View system settings',
        'system_settings.manage' => 'Manage system settings',
        'admins.manage' => 'Admin management access',
        'language.manage' => 'Manage language preferences',
    ];

    private const ADMIN_DEFAULT_PERMISSIONS = [
        'admin.dashboard',
        'users.view',
        'users.manage',
        'invitations.manage',
        'storage.view',
        'activity_logs.view',
        'notifications.manage',
        'system_settings.view',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function currentUser(): ?array
    {
        $userId = session('user_id');
        if (! is_numeric($userId)) {
            return null;
        }

        return $this->userById((int) $userId);
    }

    public function userById(int $userId): ?array
    {
        return $this->db->table('users')
            ->where('id', $userId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
    }

    public function isAdminRole(?array $user): bool
    {
        return $user !== null
            && (string) ($user['status'] ?? '') === 'active'
            && in_array((string) ($user['role'] ?? ''), [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isSuperAdmin(?array $user): bool
    {
        return $user !== null
            && (string) ($user['status'] ?? '') === 'active'
            && (string) ($user['role'] ?? '') === self::ROLE_SUPER_ADMIN;
    }

    public function hasPermission(int $userId, string $permission): bool
    {
        if (! in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        $user = $this->userById($userId);
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->isAdminRole($user)) {
            return false;
        }

        if ($permission === 'admin.dashboard') {
            return true;
        }

        if (! $this->hasCustomPermissionSet($userId) && in_array($permission, self::ADMIN_DEFAULT_PERMISSIONS, true)) {
            return true;
        }

        return $this->db->table('admin_permissions')
            ->where('user_id', $userId)
            ->where('permission', $permission)
            ->where('revoked_at', null)
            ->countAllResults() === 1;
    }

    public function activePermissionsFor(int $userId): array
    {
        $user = $this->userById($userId);
        if ($this->isSuperAdmin($user)) {
            return self::PERMISSIONS;
        }

        if (! $this->isAdminRole($user)) {
            return [];
        }

        $custom = $this->db->table('admin_permissions')
            ->select('permission')
            ->where('user_id', $userId)
            ->where('revoked_at', null)
            ->get()
            ->getResultArray();

        if ($custom === []) {
            return self::ADMIN_DEFAULT_PERMISSIONS;
        }

        $permissions = array_values(array_intersect(self::PERMISSIONS, array_map(static fn (array $row): string => (string) $row['permission'], $custom)));
        if (! in_array('admin.dashboard', $permissions, true)) {
            array_unshift($permissions, 'admin.dashboard');
        }

        return $permissions;
    }

    private function hasCustomPermissionSet(int $userId): bool
    {
        return $this->db->table('admin_permissions')
            ->where('user_id', $userId)
            ->countAllResults() > 0;
    }

    public function requirePermission(int $userId, string $permission): void
    {
        if (! $this->hasPermission($userId, $permission)) {
            throw new \DomainException('You do not have permission to perform this administrative action.');
        }
    }

    public function activeSuperAdminCount(): int
    {
        return (int) $this->db->table('users')
            ->where('deleted_at', null)
            ->where('role', self::ROLE_SUPER_ADMIN)
            ->where('status', 'active')
            ->countAllResults();
    }

    public function permissionForAdminPath(string $path, string $method): ?string
    {
        $path = trim(strtolower($path), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $adminSegment = array_search('admin', $segments, true);
        if ($adminSegment !== false) {
            $path = implode('/', array_slice($segments, $adminSegment));
        }

        $method = strtoupper($method);

        if ($path === 'admin' || $path === 'admin/dashboard') {
            return 'admin.dashboard';
        }

        if (str_starts_with($path, 'admin/users')) {
            return $method === 'GET' ? 'users.view' : 'users.manage';
        }

        if (str_starts_with($path, 'admin/invitations')) {
            return 'invitations.manage';
        }

        if (str_starts_with($path, 'admin/storage')) {
            return 'storage.view';
        }

        if (str_starts_with($path, 'admin/audit')) {
            return $method === 'GET' ? 'activity_logs.view' : 'activity_logs.manage';
        }

        if (str_starts_with($path, 'admin/settings')) {
            return $method === 'GET' ? 'system_settings.view' : 'system_settings.manage';
        }

        if (str_starts_with($path, 'admin/announcements')) {
            return 'notifications.manage';
        }

        if (str_starts_with($path, 'admin/management')) {
            return 'admins.manage';
        }

        return null;
    }
}
