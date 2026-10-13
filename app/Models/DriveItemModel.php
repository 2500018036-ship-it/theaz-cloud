<?php

namespace App\Models;

use CodeIgniter\Model;

class DriveItemModel extends Model
{
    protected $table = 'drive_items';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'user_id',
        'parent_id',
        'original_parent_id',
        'type',
        'name',
        'normalized_name',
        'extension',
        'mime_type',
        'size_bytes',
        'physical_path',
        'physical_name',
        'checksum_sha256',
        'status',
        'last_opened_at',
        'trashed_at',
        'trashed_by',
    ];

    protected $useTimestamps = true;

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'user_id'         => 'required|integer',
        'type'            => 'required|in_list[file,folder]',
        'name'            => 'required|max_length[190]',
        'normalized_name' => 'required|max_length[190]',
        'status'          => 'required|in_list[active,trashed]',
        'size_bytes'      => 'permit_empty|greater_than_equal_to[0]',
    ];
}
