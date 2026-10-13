<?php

namespace App\Libraries;

use App\Models\AnnouncementModel;
use App\Models\EmailQueueModel;
use App\Models\NotificationModel;
use App\Models\UserProfileModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Database;

class NotificationService
{
    private BaseConnection $db;

    private NotificationModel $notifications;

    private UserProfileModel $profiles;

    private AnnouncementModel $announcements;

    private EmailQueueModel $emailQueue;

    private ActivityLogger $activity;

    public function __construct(
        ?BaseConnection $db = null,
        ?NotificationModel $notifications = null,
        ?UserProfileModel $profiles = null,
        ?AnnouncementModel $announcements = null,
        ?EmailQueueModel $emailQueue = null,
        ?ActivityLogger $activity = null,
    ) {
        $this->db            = $db ?? Database::connect();
        $this->notifications = $notifications ?? new NotificationModel();
        $this->profiles      = $profiles ?? new UserProfileModel();
        $this->announcements = $announcements ?? new AnnouncementModel();
        $this->emailQueue    = $emailQueue ?? new EmailQueueModel();
        $this->activity      = $activity ?? new ActivityLogger($this->db);
    }

    public function summary(int $userId, int $limit = 5): array
    {
        return [
            'unread' => $this->unreadCount($userId),
            'items'  => $this->listForUser($userId, ['page' => 1, 'perPage' => $limit])['items'],
        ];
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->table('notifications')
            ->where('user_id', $userId)
            ->where('read_at', null)
            ->countAllResults();
    }

    public function listForUser(int $userId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 20)), 100);
        $status = (string) ($filters['status'] ?? '');
        $category = trim((string) ($filters['category'] ?? ''));

        $builderFactory = function () use ($userId, $status, $category) {
            $builder = $this->db->table('notifications')
                ->where('user_id', $userId);

            if ($status === 'unread') {
                $builder->where('read_at', null);
            } elseif ($status === 'read') {
                $builder->where('read_at IS NOT NULL', null, false);
            }

            if ($category !== '') {
                $builder->where('category', mb_substr($category, 0, 40));
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

    public function notifyShareGranted(int $recipientId, int $actorId, array $item, string $permission): void
    {
        $owner = $this->db->table('users')->select('username, email')->where('id', $actorId)->get()->getRowArray();
        $ownerName = (string) ($owner['username'] ?? $owner['email'] ?? 'A user');
        $locale = $this->localeForUser($recipientId);
        $itemName = (string) ($item['name'] ?? lang('App.notifications.sharedItemDefault', [], $locale));
        $permissionLabel = $this->permissionLabel($permission, $locale);

        $this->createForUser($recipientId, 'sharing', lang('App.notifications.shareGrantedTitle', [$ownerName], $locale), lang('App.notifications.shareGrantedBody', [$itemName, $permissionLabel], $locale), site_url('drive/shared-with-me'), [
            'related_type' => 'drive_item',
            'related_id'   => (int) ($item['id'] ?? 0),
            'email_subject' => lang('App.notifications.shareGrantedEmailSubject', [], $locale),
            'email_body'    => lang('App.notifications.shareGrantedEmailBody', [$ownerName, $itemName, $permissionLabel], $locale),
        ]);
    }

    public function notifySharePermissionChanged(int $recipientId, int $actorId, int $itemId, string $itemName, string $permission): void
    {
        $locale = $this->localeForUser($recipientId);
        $permissionLabel = $this->permissionLabel($permission, $locale);

        $this->createForUser($recipientId, 'sharing', lang('App.notifications.sharePermissionChangedTitle', [], $locale), lang('App.notifications.sharePermissionChangedBody', [$itemName, $permissionLabel], $locale), site_url('drive/shared-with-me'), [
            'related_type'  => 'drive_item',
            'related_id'    => $itemId,
            'email_subject' => lang('App.notifications.sharePermissionChangedTitle', [], $locale),
            'email_body'    => lang('App.notifications.sharePermissionChangedEmailBody', [$itemName, $permissionLabel], $locale),
        ]);
    }

    public function notifyShareRevoked(int $recipientId, int $actorId, int $itemId, string $itemName): void
    {
        $locale = $this->localeForUser($recipientId);

        $this->createForUser($recipientId, 'sharing', lang('App.notifications.shareRevokedTitle', [], $locale), lang('App.notifications.shareRevokedBody', [$itemName], $locale), site_url('drive/shared-with-me'), [
            'related_type'  => 'drive_item',
            'related_id'    => $itemId,
            'email_subject' => lang('App.notifications.shareRevokedTitle', [], $locale),
            'email_body'    => lang('App.notifications.shareRevokedBody', [$itemName], $locale),
        ]);
    }

    public function createAnnouncement(int $actorId, array $input): array
    {
        $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 190);
        $body = mb_substr(trim((string) ($input['body'] ?? '')), 0, 4000);
        $channels = $this->normalizeChannels((string) ($input['channels'] ?? 'notification'));
        $targetType = (string) ($input['target_type'] ?? 'all') === 'user' ? 'user' : 'all';
        $targetUserId = $targetType === 'user' ? (int) ($input['target_user_id'] ?? 0) : null;

        if ($title === '' || $body === '') {
            throw new \DomainException('Announcement title and body are required.');
        }

        if ($targetType === 'user' && ! $this->userExists((int) $targetUserId)) {
            throw new \DomainException('Target user was not found.');
        }

        $id = $this->announcements->insert([
            'title'       => $title,
            'body'        => $body,
            'category'    => mb_substr(trim((string) ($input['category'] ?? 'announcement')) ?: 'announcement', 0, 40),
            'target_type' => $targetType,
            'target_user_id' => $targetUserId,
            'channels'    => $channels,
            'status'      => 'draft',
            'notification_status' => str_contains($channels, 'notification') ? 'pending' : 'skipped',
            'email_status' => str_contains($channels, 'email') ? 'pending' : 'skipped',
            'created_by'  => $actorId,
        ], true);

        if (! is_int($id)) {
            throw new \RuntimeException('Announcement could not be created.');
        }

        $this->activity->record($actorId, 'admin.announcement.create', 'success', [
            'type' => 'announcement',
            'id'   => $id,
            'name' => $title,
        ], [
            'channels' => $channels,
            'target'   => $targetType,
        ]);

        return $this->announcements->find($id);
    }

    public function publishAnnouncement(int $actorId, int $announcementId): array
    {
        $announcement = $this->announcements->find($announcementId);
        if ($announcement === null) {
            throw new \DomainException('Announcement was not found.');
        }

        if ((string) $announcement['status'] === 'archived') {
            throw new \DomainException('Archived announcements cannot be published.');
        }

        $recipients = $this->announcementRecipients($announcement);
        $now = Time::now()->toDateTimeString();
        $channels = (string) $announcement['channels'];

        $this->db->transBegin();

        try {
            $notificationCount = 0;
            $emailCount = 0;

            foreach ($recipients as $recipient) {
                $profile = $this->profileFor((int) $recipient['id']);

                if (str_contains($channels, 'notification') && (int) ($profile['notify_in_app_announcements'] ?? 1) === 1) {
                    $this->notifications->insert([
                        'user_id'      => (int) $recipient['id'],
                        'category'     => 'announcement',
                        'title'        => (string) $announcement['title'],
                        'body'         => (string) $announcement['body'],
                        'action_url'   => site_url('notifications'),
                        'related_type' => 'announcement',
                        'related_id'   => $announcementId,
                        'created_at'   => $now,
                    ]);
                    $notificationCount++;
                }

                if (str_contains($channels, 'email') && (int) ($profile['email_announcements'] ?? 0) === 1) {
                    $this->queueEmail((int) $recipient['id'], (string) $recipient['email'], 'announcement', $announcementId, (string) $announcement['title'], (string) $announcement['body']);
                    $emailCount++;
                }
            }

            $this->announcements->update($announcementId, [
                'status'              => 'published',
                'notification_status' => str_contains($channels, 'notification') ? 'sent' : 'skipped',
                'email_status'        => str_contains($channels, 'email') ? ($emailCount > 0 ? 'queued' : 'skipped') : 'skipped',
                'published_at'        => $now,
            ]);

            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        $this->activity->record($actorId, 'admin.announcement.publish', 'success', [
            'type' => 'announcement',
            'id'   => $announcementId,
            'name' => (string) $announcement['title'],
        ], [
            'recipient_count'    => count($recipients),
            'notification_count' => $notificationCount,
            'email_count'        => $emailCount,
        ]);

        return $this->announcements->find($announcementId);
    }

    public function archiveAnnouncement(int $actorId, int $announcementId): array
    {
        $announcement = $this->announcements->find($announcementId);
        if ($announcement === null) {
            throw new \DomainException('Announcement was not found.');
        }

        $this->announcements->update($announcementId, ['status' => 'archived']);
        $this->activity->record($actorId, 'admin.announcement.archive', 'success', [
            'type' => 'announcement',
            'id'   => $announcementId,
            'name' => (string) $announcement['title'],
        ]);

        return $this->announcements->find($announcementId);
    }

    public function listAnnouncements(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(max(1, (int) ($filters['perPage'] ?? 15)), 100);
        $status = trim((string) ($filters['status'] ?? ''));

        $builderFactory = function () use ($status) {
            $builder = $this->db->table('announcements ann')
                ->select('ann.*, creator.username AS creator_username, target.username AS target_username, target.email AS target_email')
                ->join('users creator', 'creator.id = ann.created_by', 'left')
                ->join('users target', 'target.id = ann.target_user_id', 'left');

            if (in_array($status, ['draft', 'published', 'archived'], true)) {
                $builder->where('ann.status', $status);
            }

            return $builder;
        };

        $total = (int) $builderFactory()->countAllResults();
        $rows = $builderFactory()
            ->orderBy('ann.created_at', 'DESC')
            ->orderBy('ann.id', 'DESC')
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

    public function createForUser(int $userId, string $category, string $title, string $body, ?string $actionUrl = null, array $options = []): void
    {
        $profile = $this->profileFor($userId);
        $category = mb_substr($category, 0, 40);
        $now = Time::now()->toDateTimeString();

        $inAppKey = match ($category) {
            'sharing' => 'notify_in_app_sharing',
            'security' => 'notify_in_app_security',
            default => 'notify_in_app_announcements',
        };

        if ((int) ($profile[$inAppKey] ?? 1) === 1) {
            $this->notifications->insert([
                'user_id'      => $userId,
                'category'     => $category,
                'title'        => mb_substr($title, 0, 190),
                'body'         => mb_substr($body, 0, 4000),
                'action_url'   => $actionUrl === null ? null : mb_substr($actionUrl, 0, 255),
                'related_type' => isset($options['related_type']) ? mb_substr((string) $options['related_type'], 0, 50) : null,
                'related_id'   => isset($options['related_id']) ? (int) $options['related_id'] : null,
                'created_at'   => $now,
            ]);
        }

        $emailKey = match ($category) {
            'sharing' => 'email_sharing',
            default => 'email_announcements',
        };

        if ((int) ($profile[$emailKey] ?? 0) === 1) {
            $user = $this->db->table('users')->select('email')->where('id', $userId)->get()->getRowArray();
            if ($user !== null) {
                $this->queueEmail($userId, (string) $user['email'], $category, isset($options['related_id']) ? (int) $options['related_id'] : null, (string) ($options['email_subject'] ?? $title), (string) ($options['email_body'] ?? $body));
            }
        }
    }

    public function queueEmail(?int $userId, string $recipient, string $eventType, ?int $eventId, string $subject, string $body): int
    {
        $recipient = trim($recipient);
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Recipient email is invalid.');
        }

        $id = $this->emailQueue->insert([
            'event_type'      => mb_substr($eventType, 0, 60),
            'event_id'        => $eventId,
            'user_id'         => $userId,
            'recipient_email' => mb_substr($recipient, 0, 254),
            'subject'         => mb_substr($subject, 0, 190),
            'body_text'       => mb_substr($body, 0, 8000),
            'status'          => 'queued',
            'attempts'        => 0,
            'available_at'    => Time::now()->toDateTimeString(),
        ], true);

        if (! is_numeric($id)) {
            throw new \RuntimeException('Email could not be queued.');
        }

        return (int) $id;
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $this->db->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $userId)
            ->where('read_at', null)
            ->update(['read_at' => Time::now()->toDateTimeString()]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->table('notifications')
            ->where('user_id', $userId)
            ->where('read_at', null)
            ->update(['read_at' => Time::now()->toDateTimeString()]);
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
        $profile = $this->profileFor($userId);
        $locale = (string) ($profile['locale'] ?? 'en');

        return in_array($locale, ['en', 'id'], true) ? $locale : 'en';
    }

    private function permissionLabel(string $permission, string $locale): string
    {
        return match (mb_strtolower($permission)) {
            'editor' => lang('App.notifications.permissionEditor', [], $locale),
            default => lang('App.notifications.permissionViewer', [], $locale),
        };
    }

    private function normalizeChannels(string $channels): string
    {
        $parts = array_values(array_intersect(['notification', 'email'], array_map('trim', explode(',', mb_strtolower($channels)))));

        if ($parts === []) {
            $parts = ['notification'];
        }

        return implode(',', array_unique($parts));
    }

    private function announcementRecipients(array $announcement): array
    {
        $builder = $this->db->table('users')
            ->select('id, email')
            ->where('status', 'active');

        if ((string) $announcement['target_type'] === 'user') {
            $builder->where('id', (int) $announcement['target_user_id']);
        }

        return $builder->get()->getResultArray();
    }

    private function userExists(int $userId): bool
    {
        return $this->db->table('users')
            ->where('id', $userId)
            ->where('status', 'active')
            ->countAllResults() === 1;
    }
}
