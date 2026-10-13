<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\CloudDrive;
use Config\Database;

class ActivityLogger
{
    private BaseConnection $db;

    private CloudDrive $config;

    public function __construct(?BaseConnection $db = null, ?CloudDrive $config = null)
    {
        $this->db = $db ?? Database::connect();
        $this->config = $config ?? config(CloudDrive::class);
    }

    public function record(
        ?int $actorId,
        string $action,
        string $status = 'success',
        array $target = [],
        array $metadata = [],
        ?int $subjectUserId = null,
    ): void {
        try {
            $metadata = $this->sanitizeMetadata($metadata);
            $request = service('request');

            $this->db->table('activity_logs')->insert([
                'actor_id'        => $actorId,
                'subject_user_id' => $subjectUserId,
                'action'          => mb_substr($action, 0, 80),
                'target_type'     => isset($target['type']) ? mb_substr((string) $target['type'], 0, 50) : null,
                'target_id'       => isset($target['id']) ? (int) $target['id'] : null,
                'target_owner_id' => isset($target['owner_id']) ? (int) $target['owner_id'] : null,
                'target_name'     => isset($target['name']) ? mb_substr((string) $target['name'], 0, 190) : null,
                'status'          => $status === 'failure' ? 'failure' : 'success',
                'ip_address'      => method_exists($request, 'getIPAddress') ? mb_substr((string) $request->getIPAddress(), 0, 45) : null,
                'user_agent'      => method_exists($request, 'getUserAgent') ? mb_substr((string) $request->getUserAgent(), 0, 255) : null,
                'metadata_json'   => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'created_at'      => Time::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $exception) {
            log_message('error', 'Activity log write failed: {message}', ['message' => $exception->getMessage()]);
        }
    }

    public function listForUser(int $userId, string $role, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 25)), 100);
        $action = trim((string) ($filters['action'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $query = trim((string) ($filters['q'] ?? ''));

        $builderFactory = function () use ($userId, $role, $action, $status, $query) {
            $builder = $this->db->table('activity_logs log')
                ->select('log.*, actor.username AS actor_username, actor.email AS actor_email, subject.username AS subject_username, subject.email AS subject_email')
                ->join('users actor', 'actor.id = log.actor_id', 'left')
                ->join('users subject', 'subject.id = log.subject_user_id', 'left');

            if ($role !== 'admin') {
                $builder->groupStart()
                    ->where('log.actor_id', $userId)
                    ->orWhere('log.target_owner_id', $userId)
                    ->orWhere('log.subject_user_id', $userId)
                    ->groupEnd();
            }

            if ($action !== '') {
                $builder->where('log.action', $action);
            }

            if (in_array($status, ['success', 'failure'], true)) {
                $builder->where('log.status', $status);
            }

            if ($query !== '') {
                $builder->groupStart()
                    ->like('log.target_name', $query)
                    ->orLike('log.action', $query)
                    ->orLike('actor.username', $query)
                    ->orLike('actor.email', $query)
                    ->groupEnd();
            }

            return $builder;
        };

        $total = (int) $builderFactory()->countAllResults();
        $rows = $builderFactory()
            ->orderBy('log.created_at', 'DESC')
            ->orderBy('log.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['metadata_json'] ?? ''), true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

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

    public function deleteById(int $actorId, int $logId): void
    {
        $log = $this->db->table('activity_logs')
            ->where('id', $logId)
            ->get()
            ->getRowArray();

        if ($log === null) {
            throw new \DomainException('Activity log entry was not found.');
        }

        $this->db->transBegin();

        try {
            $this->db->table('activity_logs')
                ->where('id', $logId)
                ->delete();

            $this->record($actorId, 'admin.audit.delete', 'success', [
                'type' => 'activity_log',
                'id'   => $logId,
                'name' => (string) ($log['action'] ?? 'activity log'),
            ], [
                'deleted_action' => (string) ($log['action'] ?? ''),
                'deleted_status' => (string) ($log['status'] ?? ''),
            ]);

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }

    public function clearAll(int $actorId): int
    {
        $total = (int) $this->db->table('activity_logs')->countAllResults();
        if ($total === 0) {
            return 0;
        }

        $this->db->transBegin();

        try {
            $this->db->table('activity_logs')->emptyTable();

            $this->record($actorId, 'admin.audit.clear', 'success', [
                'type' => 'activity_log',
            ], [
                'deleted_count' => $total,
            ]);

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        return $total;
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $result = [];

        foreach ($metadata as $key => $value) {
            $key = is_string($key) ? $key : (string) $key;

            if ($this->isSensitiveKey($key)) {
                $result[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeMetadata($value);
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $result[$key] = $value;
                continue;
            }

            $result[$key] = mb_substr((string) $value, 0, 500);
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach ($this->config->sensitiveLogKeys as $needle) {
            if ($needle !== '' && str_contains($key, mb_strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
