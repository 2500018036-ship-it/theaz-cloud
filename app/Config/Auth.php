<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Auth extends BaseConfig
{
    public int $defaultQuotaBytes = 5_368_709_120;

    public int $loginMaxAttempts = 5;

    public int $loginDecaySeconds = 300;

    // Temporary development policy; set false to honor the admin verification setting.
    public bool $emailVerificationDeferred = false;

    public array $roles = ['super_admin', 'admin', 'user'];

    public array $accountStatuses = ['active', 'inactive', 'suspended', 'pending_verification'];
}
