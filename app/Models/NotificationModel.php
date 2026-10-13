<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table = 'notifications';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'user_id',
        'category',
        'title',
        'body',
        'action_url',
        'related_type',
        'related_id',
        'read_at',
        'created_at',
    ];
}
