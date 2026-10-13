<?php

namespace App\Models;

use CodeIgniter\Model;

class AnnouncementModel extends Model
{
    protected $table = 'announcements';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'title',
        'body',
        'category',
        'target_type',
        'target_user_id',
        'channels',
        'status',
        'notification_status',
        'email_status',
        'created_by',
        'published_at',
    ];

    protected $useTimestamps = true;
}
