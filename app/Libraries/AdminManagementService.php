<?php

namespace App\Libraries;

use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Auth;
use Config\Database;

class AdminManagementService
{
    private BaseConnection $db;

    private ActivityLogger $activity;

    public function __construct(?BaseConnection $db = null, ?ActivityLogger $activity = null)
    {
        $this->db = $db ?? Database::connect();
        $this->activity = $activity ?? new ActivityLogger($this->db);
    }

    public function overview(array $filters = []): array
    {
        return [
            'admins' => $this->listAdmins($filters),
            'invitations' => $this->listAdminInvitations($filters),
            'activity' => $this->adminActivity(10),
        ];
    }

    public function listAdmins(array $filters = []): array
    {
        $page = max(1, (int) ($filters['admin_page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 20)), 100);

        $factory = fn () => $this->db->table('users')
            ->where('deleted_at', null)
            ->whereIn('role', ['super_admin', 'admin']);

        $total = (int) $factory()->countAllResults();
        $items = $factory()
            ->orderBy('role', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();
        $ids = array_map(static fn (array $item): int => (int) $item['id'], $items);
        $permissions = $this->permissionsByUserIds($ids);

        foreach ($items as &$item) {
            $item['permission_list'] = $permissions[(int) $item['id']] ?? [];
        }
        unset($item);

        return [
            'items' => $items,
            'pager' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function createAdminAccount(int $actorId, array $input): array
    {
        $auth = new AdminAuthorization($this->db);
        $auth->requirePermission($actorId, 'admins.manage');
        $actor = $auth->userById($actorId);
        if (! $auth->isSuperAdmin($actor)) {
            throw new \DomainException('Only a super admin can create administrator accounts.');
        }

        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirmPassword = (string) ($input['password_confirm'] ?? '');
        $permissions = $this->normalizePermissions($input['permissions'] ?? []);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Admin email is invalid.');
        }

        if (! preg_match('/\A[a-zA-Z0-9._-]{3,80}\z/', $username)) {
            throw new \DomainException('Username is invalid.');
        }

        $settings = new SystemSettingsService($this->db);
        $passwordMinLength = $settings->getInt('password_min_length', 10);
        if (strlen($password) < $passwordMinLength) {
            throw new \DomainException('Password must be at least ' . $passwordMinLength . ' characters.');
        }

        if ($password !== $confirmPassword) {
            throw new \DomainException('Password confirmation does not match.');
        }

        if ($permissions === []) {
            throw new \DomainException('Select at least one administrator permission.');
        }

        $users = new UserModel();
        if ($users->withDeleted()->where('email', $email)->first() !== null) {
            throw new \DomainException('Email is already registered.');
        }

        if ($users->withDeleted()->where('username', $username)->first() !== null) {
            throw new \DomainException('Username is already registered.');
        }

        $requiresVerification = ENVIRONMENT === 'production' || $settings->getBool('email_verification_required', false);

        $verificationQueueId = null;
        $this->db->transBegin();

        try {
            $userId = $users->insert([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'status' => $requiresVerification ? EmailVerificationService::PENDING_STATUS : 'active',
                'storage_quota_bytes' => 0,
                'storage_used_bytes' => 0,
            ], true);

            if (! is_int($userId)) {
                throw new \RuntimeException(implode(' ', $users->errors()));
            }

            $this->syncAdminPermissions($userId, $permissions, $actorId);
            if ($requiresVerification) {
                $verification = (new EmailVerificationService($this->db))->issue($userId, $email, 'admin_email_verification');
                $verificationQueueId = (int) ($verification['queue_id'] ?? 0);
            }

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        if ($verificationQueueId !== null && $verificationQueueId > 0) {
            try {
                (new QueuedEmailDeliveryService())->sendById($verificationQueueId);
            } catch (\Throwable $exception) {
                log_message('error', 'Admin verification email delivery failed: {message}', ['message' => $exception->getMessage()]);
            }
        }

        $this->activity->record($actorId, 'admin.management.account.create', 'success', [
            'type' => 'user',
            'id'   => $userId,
            'name' => $username,
        ], [
            'email_hash' => hash('sha256', $email),
            'permissions' => $permissions,
            'requires_email_verification' => $requiresVerification,
        ], $userId);

        return (new AdminAuthorization($this->db))->userById($userId) ?? [];
    }

    public function updateAdminPermissions(int $actorId, int $targetUserId, array $permissions): array
    {
        $auth = new AdminAuthorization($this->db);
        $auth->requirePermission($actorId, 'admins.manage');
        $actor = $auth->userById($actorId);
        if (! $auth->isSuperAdmin($actor)) {
            throw new \DomainException('Only a super admin can change administrator permissions.');
        }

        $target = $auth->userById($targetUserId);
        if ($target === null || (string) ($target['role'] ?? '') !== AdminAuthorization::ROLE_ADMIN) {
            throw new \DomainException('Permissions can only be managed for regular admin accounts.');
        }

        $permissions = $this->normalizePermissions($permissions);
        if ($permissions === []) {
            throw new \DomainException('Select at least one administrator permission.');
        }

        $this->syncAdminPermissions($targetUserId, $permissions, $actorId);
        $this->activity->record($actorId, 'admin.management.permissions.update', 'success', [
            'type' => 'user',
            'id'   => $targetUserId,
            'name' => (string) $target['username'],
        ], [
            'permissions' => $permissions,
        ], $targetUserId);

        $updated = $auth->userById($targetUserId) ?? [];
        $updated['permission_list'] = (new AdminAuthorization($this->db))->activePermissionsFor($targetUserId);

        return $updated;
    }

    public function listAdminInvitations(array $filters = []): array
    {
        (new InvitationService(db: $this->db))->expireStale();
        $page = max(1, (int) ($filters['invite_page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 20)), 100);

        $factory = fn () => $this->db->table('invitation_codes invitation')
            ->select('invitation.*, creator.email AS created_by_email, accepted.email AS accepted_user_email')
            ->join('users creator', 'creator.id = invitation.created_by', 'left')
            ->join('users accepted', 'accepted.id = invitation.accepted_user_id', 'left')
            ->where('invitation.invitation_type', 'admin');

        $total = (int) $factory()->countAllResults();
        $items = $factory()
            ->orderBy('invitation.created_at', 'DESC')
            ->orderBy('invitation.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items' => $items,
            'pager' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function createAdminInvitation(int $actorId, string $email, ?string $expiresAt = null, ?int $quotaBytes = null): array
    {
        throw new \DomainException('Admin invitation activation is disabled. Create administrator accounts directly from Admin Management.');

        $auth = new AdminAuthorization($this->db);
        $auth->requirePermission($actorId, 'admins.manage');
        $actor = $auth->userById($actorId);
        if (! $auth->isSuperAdmin($actor)) {
            throw new \DomainException('Only a super admin can invite administrators.');
        }

        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Admin email is invalid.');
        }

        $existing = $this->db->table('users')
            ->where('email', $email)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            throw new \DomainException('Email is already registered.');
        }

        $activeInvite = $this->db->table('invitation_codes')
            ->where('invitation_type', 'admin')
            ->where('recipient_email', $email)
            ->where('status', 'active')
            ->where('revoked_at', null)
            ->countAllResults() > 0;
        if ($activeInvite) {
            throw new \DomainException('An active admin invitation already exists for this email.');
        }

        if ($expiresAt === null || trim($expiresAt) === '') {
            $expiresAt = Time::now()->addDays(7)->toDateTimeString();
        } elseif (strtotime($expiresAt) === false || strtotime($expiresAt) <= time()) {
            throw new \DomainException('Expiration must be a future date.');
        } else {
            $expiresAt = date('Y-m-d H:i:s', strtotime($expiresAt));
        }

        $quotaBytes = $quotaBytes === null || $quotaBytes <= 0
            ? (new SystemSettingsService($this->db))->defaultUserQuotaBytes()
            : $quotaBytes;

        $plainToken = (new InvitationService(db: $this->db))->generatePlainCode();
        $invitationId = (new InvitationService(db: $this->db))->create($plainToken, 'Admin invitation for ' . $email, 1, $expiresAt, $actorId, [
            'invitation_type' => 'admin',
            'recipient_email' => $email,
            'role_on_accept' => 'admin',
            'storage_quota_bytes' => $quotaBytes,
        ]);

        $this->activity->record($actorId, 'admin.management.invitation.create', 'success', [
            'type' => 'invitation',
            'id'   => $invitationId,
            'name' => $email,
        ], [
            'recipient_hash' => hash('sha256', $email),
            'expires_at' => $expiresAt,
            'quota_bytes' => $quotaBytes,
        ]);

        return [
            'id' => $invitationId,
            'email' => $email,
            'token' => $plainToken,
            'activation_url' => site_url('admin-invitations/activate/' . rawurlencode($plainToken)),
            'expires_at' => $expiresAt,
        ];
    }

    public function revokeAdminInvitation(int $actorId, int $invitationId): void
    {
        (new AdminAuthorization($this->db))->requirePermission($actorId, 'admins.manage');
        $invitation = $this->db->table('invitation_codes')
            ->where('id', $invitationId)
            ->where('invitation_type', 'admin')
            ->get()
            ->getRowArray();

        if ($invitation === null) {
            throw new \DomainException('Admin invitation was not found.');
        }

        if (! (new InvitationService(db: $this->db))->revoke($invitationId, $actorId)) {
            throw new \DomainException('Admin invitation could not be revoked.');
        }

        $this->activity->record($actorId, 'admin.management.invitation.revoke', 'success', [
            'type' => 'invitation',
            'id'   => $invitationId,
            'name' => (string) ($invitation['recipient_email'] ?? 'admin invitation'),
        ]);
    }

    public function resendAdminInvitation(int $actorId, int $invitationId): array
    {
        throw new \DomainException('Admin invitation activation is disabled. Create administrator accounts directly from Admin Management.');

        $auth = new AdminAuthorization($this->db);
        $auth->requirePermission($actorId, 'admins.manage');
        $actor = $auth->userById($actorId);
        if (! $auth->isSuperAdmin($actor)) {
            throw new \DomainException('Only a super admin can resend administrator invitations.');
        }

        $invitation = $this->db->table('invitation_codes')
            ->where('id', $invitationId)
            ->where('invitation_type', 'admin')
            ->get()
            ->getRowArray();

        if ($invitation === null) {
            throw new \DomainException('Admin invitation was not found.');
        }

        if (in_array((string) $invitation['status'], ['consumed'], true) || ! empty($invitation['accepted_user_id'])) {
            throw new \DomainException('Accepted admin invitations cannot be resent.');
        }

        $email = mb_strtolower(trim((string) ($invitation['recipient_email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Admin invitation email is invalid.');
        }

        $existing = $this->db->table('users')
            ->where('email', $email)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            throw new \DomainException('Email is already registered.');
        }

        $now = Time::now()->toDateTimeString();
        $expiresAt = Time::now()->addDays((new SystemSettingsService($this->db))->getInt('default_invitation_expires_days', 7))->toDateTimeString();
        $plainToken = (new InvitationService(db: $this->db))->generatePlainCode();

        $this->db->transBegin();

        try {
            $this->db->table('invitation_codes')
                ->where('id', $invitationId)
                ->update([
                    'status' => 'revoked',
                    'revoked_by' => $actorId,
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            $newInvitationId = (new InvitationService(db: $this->db))->create($plainToken, 'Admin invitation for ' . $email, 1, $expiresAt, $actorId, [
                'invitation_type' => 'admin',
                'recipient_email' => $email,
                'role_on_accept' => 'admin',
                'storage_quota_bytes' => isset($invitation['storage_quota_bytes']) ? (int) $invitation['storage_quota_bytes'] : null,
                'note' => 'Regenerated from invitation #' . $invitationId,
            ]);

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        $this->activity->record($actorId, 'admin.management.invitation.resend', 'success', [
            'type' => 'invitation',
            'id'   => $newInvitationId,
            'name' => $email,
        ], [
            'old_invitation_id' => $invitationId,
            'recipient_hash' => hash('sha256', $email),
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => $newInvitationId,
            'email' => $email,
            'token' => $plainToken,
            'activation_url' => site_url('admin-invitations/activate/' . rawurlencode($plainToken)),
            'expires_at' => $expiresAt,
        ];
    }

    public function activateAdminFromInvitation(string $token, string $username, string $password, string $confirmPassword): int
    {
        throw new \DomainException('Admin invitation activation is disabled. Contact a super admin to create the administrator account.');

        $invitationService = new InvitationService(db: $this->db);
        $settings = new SystemSettingsService($this->db);
        $requiresVerification = ENVIRONMENT === 'production' || $settings->getBool('email_verification_required', false);
        $invitation = $invitationService->findValid($token, 'admin');
        if ($invitation === null) {
            throw new \DomainException('Admin invitation is invalid, expired, revoked, or already used.');
        }

        $username = mb_strtolower(trim($username));
        if (! preg_match('/\A[a-zA-Z0-9._-]{3,80}\z/', $username)) {
            throw new \DomainException('Username is invalid.');
        }

        $passwordMinLength = $settings->getInt('password_min_length', 10);
        if (strlen($password) < $passwordMinLength) {
            throw new \DomainException('Password must be at least ' . $passwordMinLength . ' characters.');
        }

        if ($password !== $confirmPassword) {
            throw new \DomainException('Password confirmation does not match.');
        }

        $email = (string) $invitation['recipient_email'];
        $users = new UserModel();
        if ($users->withDeleted()->where('email', $email)->first() !== null) {
            throw new \DomainException('Email is already registered.');
        }

        if ($users->withDeleted()->where('username', $username)->first() !== null) {
            throw new \DomainException('Username is already registered.');
        }

        $this->db->transBegin();

        try {
            $consumed = $invitationService->consumeForType($token, 'admin');
            if ($consumed === null) {
                throw new \DomainException('Admin invitation is no longer available.');
            }

            $userId = $users->insert([
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'status' => $requiresVerification ? EmailVerificationService::PENDING_STATUS : 'active',
                'storage_quota_bytes' => (int) ($invitation['storage_quota_bytes'] ?: config(Auth::class)->defaultQuotaBytes),
                'storage_used_bytes' => 0,
            ], true);

            if (! is_int($userId)) {
                throw new \RuntimeException(implode(' ', $users->errors()));
            }

            $invitationService->attachAcceptedUser((int) $invitation['id'], $userId);
            if ($requiresVerification) {
                (new EmailVerificationService($this->db))->issue($userId, $email, 'admin_email_verification');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        $this->activity->record($userId, 'admin.management.invitation.accept', 'success', [
            'type' => 'user',
            'id'   => $userId,
            'name' => $username,
        ], [
            'invitation_id' => (int) $invitation['id'],
        ], $userId);

        return $userId;
    }

    public function adminActivity(int $limit = 10): array
    {
        return $this->db->table('activity_logs log')
            ->select('log.*, actor.username AS actor_username, actor.email AS actor_email')
            ->join('users actor', 'actor.id = log.actor_id', 'left')
            ->like('log.action', 'admin.', 'after')
            ->orderBy('log.created_at', 'DESC')
            ->orderBy('log.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    private function permissionsByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->db->table('admin_permissions')
            ->select('user_id, permission')
            ->whereIn('user_id', $userIds)
            ->where('revoked_at', null)
            ->orderBy('permission', 'ASC')
            ->get()
            ->getResultArray();
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = (string) $row['permission'];
        }

        $auth = new AdminAuthorization($this->db);
        foreach ($userIds as $userId) {
            $map[$userId] = $map[$userId] ?? $auth->activePermissionsFor($userId);
        }

        return $map;
    }

    private function normalizePermissions(array|string $permissions): array
    {
        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        $permissions = array_map(static fn ($permission): string => mb_strtolower(trim((string) $permission)), $permissions);
        $permissions = array_values(array_unique(array_intersect(AdminAuthorization::PERMISSIONS, $permissions)));

        return $permissions;
    }

    private function syncAdminPermissions(int $userId, array $permissions, int $actorId): void
    {
        $now = Time::now()->toDateTimeString();
        $permissions = $this->normalizePermissions($permissions);

        $this->db->table('admin_permissions')
            ->where('user_id', $userId)
            ->whereNotIn('permission', $permissions)
            ->where('revoked_at', null)
            ->update(['revoked_at' => $now]);

        foreach ($permissions as $permission) {
            $existing = $this->db->table('admin_permissions')
                ->where('user_id', $userId)
                ->where('permission', $permission)
                ->get()
                ->getRowArray();

            if ($existing === null) {
                $this->db->table('admin_permissions')->insert([
                    'user_id' => $userId,
                    'permission' => $permission,
                    'granted_by' => $actorId,
                    'created_at' => $now,
                    'revoked_at' => null,
                ]);
            } else {
                $this->db->table('admin_permissions')
                    ->where('id', (int) $existing['id'])
                    ->update([
                        'granted_by' => $actorId,
                        'revoked_at' => null,
                    ]);
            }
        }
    }

    private function quotaBytesFromInput(array $input): int
    {
        $value = (float) ($input['quota_value'] ?? 0);
        if ($value <= 0) {
            return (new SystemSettingsService($this->db))->defaultUserQuotaBytes();
        }

        $unit = strtolower((string) ($input['quota_unit'] ?? 'gb'));
        $multiplier = match ($unit) {
            'mb' => 1048576,
            'tb' => 1099511627776,
            default => 1073741824,
        };

        $bytes = (int) round($value * $multiplier);
        $max = (new SystemSettingsService($this->db))->getInt('max_admin_assignable_quota_bytes', 10_737_418_240);
        if ($bytes > $max) {
            throw new \DomainException('Admin quota exceeds the allowed policy.');
        }

        return $bytes;
    }
}
