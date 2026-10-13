<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailQueueModel extends Model
{
    protected $table = 'email_queue';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'event_type',
        'event_id',
        'user_id',
        'recipient_email',
        'subject',
        'body_text',
        'status',
        'attempts',
        'last_error',
        'available_at',
        'sent_at',
    ];

    protected $useTimestamps = true;
}
