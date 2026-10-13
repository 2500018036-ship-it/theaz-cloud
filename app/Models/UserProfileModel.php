<?php

namespace App\Models;

use CodeIgniter\Model;

class UserProfileModel extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    protected $returnType = 'array';

    protected $useAutoIncrement = false;

    protected $allowedFields = [
        'user_id',
        'display_name',
        'bio',
        'avatar_path',
        'avatar_name',
        'pending_email',
        'pending_email_token_hash',
        'pending_email_expires_at',
        'email_verified_at',
        'locale',
        'default_drive_view',
        'default_sort',
        'default_sort_dir',
        'notify_in_app_announcements',
        'notify_in_app_sharing',
        'notify_in_app_security',
        'email_announcements',
        'email_sharing',
        'email_updates',
    ];

    protected $useTimestamps = true;
}
