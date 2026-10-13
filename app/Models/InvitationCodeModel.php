<?php

namespace App\Models;

use CodeIgniter\Model;

class InvitationCodeModel extends Model
{
    protected $table = 'invitation_codes';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'label',
        'code_hash',
        'status',
        'invitation_type',
        'recipient_email',
        'role_on_accept',
        'storage_quota_bytes',
        'note',
        'max_uses',
        'use_count',
        'expires_at',
        'created_by',
        'revoked_by',
        'revoked_at',
        'last_used_at',
        'accepted_user_id',
        'accepted_at',
    ];

    protected $useTimestamps = true;

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'code_hash' => 'required|exact_length[64]|is_unique[invitation_codes.code_hash,id,{id}]',
        'status'    => 'required|in_list[active,consumed,revoked,expired]',
        'invitation_type' => 'permit_empty|in_list[user,admin]',
        'role_on_accept' => 'permit_empty|in_list[user,admin]',
        'max_uses'  => 'required|greater_than_equal_to[1]',
        'use_count' => 'permit_empty|greater_than_equal_to[0]',
    ];
}
