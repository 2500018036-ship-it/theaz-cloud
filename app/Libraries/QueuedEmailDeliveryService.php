<?php

namespace App\Libraries;

use App\Models\EmailQueueModel;
use CodeIgniter\I18n\Time;
use Config\Email;

class QueuedEmailDeliveryService
{
    private EmailQueueModel $queue;

    private Email $config;

    public function __construct(?EmailQueueModel $queue = null, ?Email $config = null)
    {
        $this->queue = $queue ?? new EmailQueueModel();
        $this->config = $config ?? config(Email::class);
    }

    public function isConfigured(): bool
    {
        if (trim($this->config->fromEmail) === '') {
            return false;
        }

        if ($this->config->protocol !== 'smtp') {
            return true;
        }

        return trim($this->config->SMTPHost) !== ''
            && trim($this->config->SMTPUser) !== ''
            && trim($this->config->SMTPPass) !== '';
    }

    public function sendById(int $id, int $maxAttempts = 3): array
    {
        if (! $this->isConfigured()) {
            throw new \DomainException('Email transport is not configured.');
        }

        $row = $this->queue->find($id);
        if ($row === null) {
            throw new \DomainException('Queued email was not found.');
        }

        if (ENVIRONMENT === 'testing') {
            return ['sent' => false, 'id' => $id, 'error' => null, 'skipped' => true];
        }

        return $this->deliver($row, max(1, min($maxAttempts, 10)));
    }

    public function sendAvailable(int $limit = 25, int $maxAttempts = 3): array
    {
        if (! $this->isConfigured()) {
            throw new \DomainException('Email transport is not configured.');
        }

        if (ENVIRONMENT === 'testing') {
            return [
                'processed' => 0,
                'sent'      => 0,
                'failed'    => 0,
                'skipped'   => true,
            ];
        }

        $limit = max(1, min($limit, 100));
        $maxAttempts = max(1, min($maxAttempts, 10));
        $now = Time::now()->toDateTimeString();
        // Recover work after a worker crash, without immediately retrying an in-flight send.
        $this->queue->builder()->where('status', 'processing')->where('available_at <', $now)
            ->update(['status' => 'queued']);
        $rows = $this->queue
            ->where('status', 'queued')
            ->where('available_at <=', $now)
            ->where('attempts <', $maxAttempts)
            ->orderBy('available_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll($limit);

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $result = $this->deliver($row, $maxAttempts);
            if ($result['sent']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => count($rows),
            'sent'      => $sent,
            'failed'    => $failed,
        ];
    }

    private function deliver(array $row, int $maxAttempts): array
    {
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $id = (int) $row['id'];

        if (preg_match('/@(?:[^@]+\.)?(test|invalid|localhost)$/i', (string) $row['recipient_email'])) {
            $this->queue->update($id, ['status' => 'cancelled', 'last_error' => 'Reserved test recipient.']);
            $this->syncAnnouncement($row);
            return ['sent' => false, 'id' => $id, 'error' => null, 'skipped' => true];
        }

        $this->queue->builder()->where('id', $id)->where('status', 'queued')
            ->where('available_at <=', Time::now()->toDateTimeString())
            ->update(['status' => 'processing', 'available_at' => Time::now()->addMinutes(10)->toDateTimeString()]);
        if ($this->queue->affectedRows() !== 1) {
            return ['sent' => false, 'id' => $id, 'error' => null, 'skipped' => true];
        }

        try {
            $email = service('email');
            $email->clear(true);
            $email->setFrom($this->config->fromEmail, $this->config->fromName ?: 'Theaz Cloud');
            $email->setTo((string) $row['recipient_email']);
            $email->setSubject((string) $row['subject']);

            $body = (string) $row['body_text'];
            if ($this->looksLikeHtml($body)) {
                $email->setMailType('html');
                $email->setMessage($body);
                $email->setAltMessage($this->plainText($body));
            } else {
                $email->setMailType('text');
                $email->setMessage($body);
            }

            if (! $email->send(false)) {
                throw new \RuntimeException('Email transport returned failure.');
            }

            $this->queue->update($id, [
                'status'     => 'sent',
                'attempts'   => $attempts,
                'last_error' => null,
                'sent_at'    => Time::now()->toDateTimeString(),
            ]);
            $this->syncAnnouncement($row);

            return ['sent' => true, 'id' => $id, 'error' => null];
        } catch (\Throwable $exception) {
            $retry = $attempts < $maxAttempts;
            $error = $this->redactError($exception->getMessage());
            $this->queue->update($id, [
                'status'       => $retry ? 'queued' : 'failed',
                'attempts'     => $attempts,
                'last_error'   => mb_substr($error, 0, 500),
                'available_at' => $retry ? Time::now()->addMinutes(5 * $attempts)->toDateTimeString() : Time::now()->toDateTimeString(),
            ]);
            $this->syncAnnouncement($row);

            return ['sent' => false, 'id' => $id, 'error' => $error];
        }
    }

    private function syncAnnouncement(array $row): void
    {
        if ($row['event_type'] === 'announcement' && (int) ($row['event_id'] ?? 0) > 0) {
            try {
                (new AnnouncementDeliveryService())->sync((int) $row['event_id']);
            } catch (\Throwable) {
                // A reporting failure must never requeue an email already accepted by SMTP.
                log_message('warning', 'Announcement delivery summary could not be refreshed.');
            }
        }
    }

    private function looksLikeHtml(string $body): bool
    {
        return preg_match('/<(html|body|p|a|table|div|br|strong|span)\b/i', $body) === 1;
    }

    private function plainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function redactError(string $message): string
    {
        foreach ([$this->config->SMTPPass, $this->config->SMTPUser] as $secret) {
            $secret = trim((string) $secret);
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return preg_replace('/\b[a-z0-9]{4}\s+[a-z0-9]{4}\s+[a-z0-9]{4}\s+[a-z0-9]{4}\b/i', '[redacted]', $message) ?? $message;
    }
}
