<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Email;

class TestEmail extends BaseCommand
{
    protected $group = 'Notifications';

    protected $name = 'email:test';

    protected $description = 'Sends a non-sensitive SMTP test email using configured environment variables.';

    protected $usage = 'email:test --to recipient@example.com';

    protected $options = [
        '--to' => 'Recipient email address for the test message.',
    ];

    public function run(array $params)
    {
        $config = config(Email::class);
        $to = trim((string) CLI::getOption('to'));

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Provide a valid recipient with --to recipient@example.com.');

            return EXIT_ERROR;
        }

        if (! $this->isConfigured($config)) {
            CLI::error('Email transport is not fully configured. Set the email SMTP environment variables, including the App Password.');

            return EXIT_ERROR;
        }

        try {
            $email = service('email');
            $email->clear(true);
            $email->setFrom($config->fromEmail, $config->fromName ?: 'Theaz Cloud');
            $email->setTo($to);
            $email->setSubject('Theaz Cloud SMTP test');
            $email->setMessage("This is a non-sensitive test email from Theaz Cloud.\n\nIf you received it, SMTP delivery is working.");

            if (! $email->send(false)) {
                CLI::error('SMTP test failed. Check host, username, App Password, port, and TLS settings.');

                return EXIT_ERROR;
            }
        } catch (\Throwable) {
            CLI::error('SMTP test failed. Check host, username, App Password, port, and TLS settings.');

            return EXIT_ERROR;
        }

        CLI::write('SMTP test email sent to ' . $to . '.', 'green');

        return EXIT_SUCCESS;
    }

    private function isConfigured(Email $config): bool
    {
        return trim($config->fromEmail) !== ''
            && trim($config->SMTPHost) !== ''
            && trim($config->SMTPUser) !== ''
            && trim($config->SMTPPass) !== '';
    }
}
