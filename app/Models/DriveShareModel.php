<?php

namespace App\Models;

use CodeIgniter\Model;

class DriveShareModel extends Model
{
    protected $table = 'drive_shares';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'user_id',
        'item_id',
        'shared_with_user_id',
        'access_type',
        'permission',
        'token',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    protected $useTimestamps = true;

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'user_id'     => 'required|integer',
        'item_id'     => 'required|integer',
        'access_type' => 'required|in_list[user,public]',
        'permission'  => 'required|in_list[viewer,editor]',
        'token'       => 'permit_empty|max_length[64]',
    ];
}
