<?php

namespace App\Models;

use CodeIgniter\Model;

class ActivityLogModel extends Model
{
    protected $table = 'activity_logs';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'actor_id',
        'subject_user_id',
        'action',
        'target_type',
        'target_id',
        'target_owner_id',
        'target_name',
        'status',
        'ip_address',
        'user_agent',
        'metadata_json',
        'created_at',
    ];

    protected $validationRules = [
        'action' => 'required|max_length[80]',
        'status' => 'required|in_list[success,failure]',
    ];
}
