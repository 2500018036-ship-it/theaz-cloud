<?php

namespace App\Commands;

use App\Libraries\InvitationService;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CreateInvitation extends BaseCommand
{
    protected $group = 'Auth';

    protected $name = 'invitation:create';

    protected $description = 'Creates an invitation code and prints the plaintext code once.';

    protected $usage = 'invitation:create --label "Developer invite" --expires-at "2026-12-31 23:59:59"';

    protected $options = [
        '--label'            => 'Optional invitation label.',
        '--max-uses'         => 'Maximum usage count. Defaults to 1.',
        '--expires-at'       => 'Optional expiration datetime in Y-m-d H:i:s format.',
        '--created-by-email' => 'Optional admin email to store as creator.',
    ];

    public function run(array $params)
    {
        $label = CLI::getOption('label');
        $maxUses = (int) (CLI::getOption('max-uses') ?: 1);
        $expiresAt = CLI::getOption('expires-at') ?: null;
        $createdBy = null;

        if ($maxUses < 1) {
            CLI::error('--max-uses must be at least 1.');

            return EXIT_ERROR;
        }

        if ($expiresAt !== null && strtotime($expiresAt) === false) {
            CLI::error('--expires-at must be a valid datetime.');

            return EXIT_ERROR;
        }

        $creatorEmail = CLI::getOption('created-by-email');
        if ($creatorEmail !== null) {
            $creator = (new UserModel())->where('email', mb_strtolower(trim((string) $creatorEmail)))->first();
            if ($creator === null) {
                CLI::error('Creator email was not found.');

                return EXIT_ERROR;
            }

            $createdBy = (int) $creator['id'];
        }

        $service = new InvitationService();
        $plainCode = $service->generatePlainCode();
        $service->create($plainCode, $label === null ? null : (string) $label, $maxUses, $expiresAt === null ? null : date('Y-m-d H:i:s', strtotime($expiresAt)), $createdBy);

        CLI::write('Invitation code created. This plaintext value is shown once:', 'green');
        CLI::write($plainCode, 'yellow');

        return EXIT_SUCCESS;
    }
}
