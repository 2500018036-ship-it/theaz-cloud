<?php
namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AnnouncementDeliveryService
{
    private BaseConnection $db;
    public function __construct(?BaseConnection $db = null) { $this->db = $db ?? Database::connect(); }

    public function summary(array $ids): array
    {
        if ($ids === []) return [];
        $rows = $this->db->table('email_queue')->select("event_id,status,COUNT(*) AS total,SUM(CASE WHEN status = 'queued' AND attempts > 0 THEN 1 ELSE 0 END) AS retrying", false)
            ->where('event_type', 'announcement')->whereIn('event_id', $ids)->groupBy(['event_id', 'status'])->get()->getResultArray();
        $result = [];
        foreach ($ids as $id) $result[(int) $id] = ['sent' => 0, 'queued' => 0, 'processing' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $result[(int) $row['event_id']][$row['status']] = (int) $row['total'];
            $result[(int) $row['event_id']]['retrying'] = ($result[(int) $row['event_id']]['retrying'] ?? 0) + (int) $row['retrying'];
        }
        foreach ($result as $id => &$counts) {
            $counts['retrying'] ??= 0;
            $counts['total'] = array_sum($counts) - $counts['retrying'];
            $counts['status'] = $counts['queued'] + $counts['processing'] > 0 ? 'queued'
                : ($counts['failed'] > 0 ? ($counts['sent'] > 0 ? 'partial' : 'failed')
                : ($counts['cancelled'] > 0 ? ($counts['sent'] > 0 ? 'partial' : 'cancelled')
                : ($counts['sent'] > 0 ? 'sent' : 'skipped')));
            $counts['error'] = $counts['failed'] > 0 ? 'Email delivery failed after retries. Check SMTP settings and connectivity.'
                : ($counts['retrying'] > 0 ? 'Email transport failed; a retry is scheduled. Check SMTP settings and connectivity.' : null);
        }
        unset($counts);
        return $result;
    }

    public function sync(int $id): void
    {
        $summary = $this->summary([$id])[$id];
        $this->db->table('announcements')->where('id', $id)->where('status', 'published')
            ->where('email_status !=', 'skipped')->update(['email_status' => $summary['status']]);
    }
}
