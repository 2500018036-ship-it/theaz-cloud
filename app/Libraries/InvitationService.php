<?php

namespace App\Libraries;

use App\Models\InvitationCodeModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use CodeIgniter\I18n\Time;
use Config\Database;

class InvitationService
{
    private InvitationCodeModel $invitations;

    private BaseConnection $db;

    public function __construct(?InvitationCodeModel $invitations = null, ?BaseConnection $db = null)
    {
        $this->invitations = $invitations ?? new InvitationCodeModel();
        $this->db          = $db ?? Database::connect();
    }

    public function generatePlainCode(): string
    {
        $raw = strtoupper(bin2hex(random_bytes(16)));

        return implode('-', str_split($raw, 8));
    }

    public function hashCode(string $code): string
    {
        return hash('sha256', $this->normalizeCode($code));
    }

    public function normalizeCode(string $code): string
    {
        return preg_replace('/[\s-]+/', '', strtoupper(trim($code))) ?? '';
    }

    public function create(string $plainCode, ?string $label, int $maxUses = 1, ?string $expiresAt = null, ?int $createdBy = null, array $options = []): int
    {
        $type = (string) ($options['invitation_type'] ?? 'user');
        $roleOnAccept = (string) ($options['role_on_accept'] ?? 'user');
        if ($type !== 'user') {
            throw new \DomainException('Invitation type is not available.');
        }

        if ($type === 'admin') {
            $roleOnAccept = 'admin';
            $maxUses = 1;
        } else {
            $roleOnAccept = 'user';
        }

        $recipientEmail = isset($options['recipient_email'])
            ? mb_strtolower(trim((string) $options['recipient_email']))
            : null;
        if ($recipientEmail === '') {
            $recipientEmail = null;
        }

        if ($recipientEmail !== null && ! filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Recipient email is invalid.');
        }

        $id = $this->invitations->insert([
            'label'               => $label,
            'code_hash'           => $this->hashCode($plainCode),
            'status'              => 'active',
            'invitation_type'     => $type,
            'recipient_email'     => $recipientEmail,
            'role_on_accept'      => $roleOnAccept,
            'storage_quota_bytes' => isset($options['storage_quota_bytes']) ? (int) $options['storage_quota_bytes'] : null,
            'note'                => isset($options['note']) ? mb_substr((string) $options['note'], 0, 1000) : null,
            'max_uses'            => max(1, $maxUses),
            'use_count'           => 0,
            'expires_at'          => $expiresAt,
            'created_by'          => $createdBy,
        ], true);

        if (! is_int($id)) {
            throw new \RuntimeException('Invitation code could not be created.');
        }

        return $id;
    }

    public function findValid(string $plainCode, string $type = 'user'): ?array
    {
        $now = Time::now()->toDateTimeString();

        return $this->db->table('invitation_codes')
            ->where('deleted_at', null)
            ->where('code_hash', $this->hashCode($plainCode))
            ->where('invitation_type', $type)
            ->where('status', 'active')
            ->where('revoked_at', null)
            ->where('use_count < max_uses', null, false)
            ->groupStart()
                ->where('expires_at', null)
                ->orWhere('expires_at >=', $now)
            ->groupEnd()
            ->get()
            ->getRowArray();
    }

    public function expireStale(): int
    {
        $now = Time::now()->toDateTimeString();

        $this->db->table('invitation_codes')
            ->set('status', 'expired')
            ->set('updated_at', $now)
            ->where('status', 'active')
            ->where('expires_at IS NOT NULL', null, false)
            ->where('expires_at <', $now)
            ->update();

        return $this->db->affectedRows();
    }

    public function revoke(int $id, ?int $revokedBy): bool
    {
        $now = Time::now()->toDateTimeString();

        $builder = $this->db->table('invitation_codes');
        $builder
            ->set('status', 'revoked')
            ->set('revoked_by', $revokedBy)
            ->set('revoked_at', $now)
            ->set('updated_at', $now)
            ->where('id', $id)
            ->where('status', 'active')
            ->where('revoked_at', null)
            ->where('use_count < max_uses', null, false)
            ->update();

        return $this->db->affectedRows() === 1;
    }

    public function consume(string $plainCode): bool
    {
        return $this->consumeForType($plainCode, 'user') !== null;
    }

    public function consumeForType(string $plainCode, string $type = 'user'): ?array
    {
        if ($type !== 'user') {
            return null;
        }
        $now = Time::now()->toDateTimeString();
        $hash = $this->hashCode($plainCode);

        $builder = $this->db->table('invitation_codes');
        $builder
            // MySQL evaluates SET assignments left to right; use the old count for status.
            ->set('status', new RawSql("CASE WHEN use_count + 1 >= max_uses THEN 'consumed' ELSE status END"))
            ->set('use_count', new RawSql('use_count + 1'))
            ->set('last_used_at', $now)
            ->set('updated_at', $now)
            ->where('code_hash', $hash)
            ->where('deleted_at', null)
            ->where('invitation_type', $type)
            ->where('status', 'active')
            ->where('revoked_at', null)
            ->where('use_count < max_uses', null, false)
            ->groupStart()
                ->where('expires_at', null)
                ->orWhere('expires_at >=', $now)
            ->groupEnd()
            ->update();

        if ($this->db->affectedRows() !== 1) {
            return null;
        }

        return $this->db->table('invitation_codes')
            ->where('code_hash', $hash)
            ->get()
            ->getRowArray();
    }

    public function attachAcceptedUser(int $invitationId, int $userId): void
    {
        $this->db->table('invitation_codes')
            ->where('id', $invitationId)
            ->update([
                'accepted_user_id' => $userId,
                'accepted_at'      => Time::now()->toDateTimeString(),
                'updated_at'       => Time::now()->toDateTimeString(),
            ]);
    }

    public function archive(int $id, int $actorId): bool
    {
        (new AdminAuthorization($this->db))->requirePermission($actorId, 'invitations.manage');
        $this->expireStale();
        $now = Time::now()->toDateTimeString();
        $this->db->table('invitation_codes')->where('id', $id)->where('deleted_at', null)
            ->whereIn('status', ['expired', 'revoked'])->update(['deleted_at' => $now, 'updated_at' => $now]);
        if ($this->db->affectedRows() !== 1) {
            return false;
        }
        (new ActivityLogger($this->db))->record($actorId, 'admin.invitation.delete', 'success', ['type' => 'invitation', 'id' => $id]);
        return true;
    }
}
