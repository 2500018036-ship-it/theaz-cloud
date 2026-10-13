<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'username',
        'email',
        'password_hash',
        'role',
        'status',
        'storage_quota_bytes',
        'storage_used_bytes',
        'last_login_at',
    ];

    protected $useTimestamps = true;

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $deletedField = 'deleted_at';

    protected $validationRules = [
        'username'      => 'required|min_length[3]|max_length[80]|regex_match[/\A[a-zA-Z0-9._-]+\z/]|is_unique[users.username,id,{id}]',
        'email'         => 'required|valid_email|max_length[254]|is_unique[users.email,id,{id}]',
        'password_hash' => 'required|max_length[255]',
        'role'          => 'required|in_list[super_admin,admin,user]',
        'status'        => 'required|in_list[active,inactive,suspended,pending_verification]',
    ];

    public function findForLogin(string $identifier): ?array
    {
        $identifier = mb_strtolower(trim($identifier));

        return $this
            ->groupStart()
                ->where('email', $identifier)
                ->orWhere('username', $identifier)
            ->groupEnd()
            ->first();
    }
}
