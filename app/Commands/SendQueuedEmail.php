<?php

namespace App\Commands;

use App\Libraries\QueuedEmailDeliveryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SendQueuedEmail extends BaseCommand
{
    protected $group = 'Notifications';

    protected $name = 'email:send-queued';

    protected $description = 'Sends queued notification emails using the configured email transport.';

    protected $usage = 'email:send-queued --limit=25 --max-attempts=3';

    protected $options = [
        '--limit'        => 'Maximum queued emails to process. Defaults to 25.',
        '--max-attempts' => 'Maximum delivery attempts before marking failed. Defaults to 3.',
    ];

    public function run(array $params)
    {
        $delivery = new QueuedEmailDeliveryService();
        if (! $delivery->isConfigured()) {
            CLI::error('Email transport is not configured. Set fromEmail and SMTP settings before running this command.');

            return EXIT_ERROR;
        }

        $limit = max(1, min((int) (CLI::getOption('limit') ?? 25), 100));
        $maxAttempts = max(1, min((int) (CLI::getOption('max-attempts') ?? 3), 10));
        $result = $delivery->sendAvailable($limit, $maxAttempts);
        CLI::write('Queued emails processed: ' . $result['processed'] . ', sent: ' . $result['sent'] . ', deferred/failed: ' . $result['failed'], $result['failed'] > 0 ? 'yellow' : 'green');

        return $result['failed'] > 0 && $result['sent'] === 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
