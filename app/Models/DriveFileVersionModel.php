<?php

namespace App\Models;

use CodeIgniter\Model;

class DriveFileVersionModel extends Model
{
    protected $table = 'drive_file_versions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'item_id',
        'user_id',
        'version_number',
        'name',
        'extension',
        'mime_type',
        'size_bytes',
        'physical_path',
        'physical_name',
        'checksum_sha256',
        'source_version_id',
        'created_by',
        'created_at',
    ];

    protected $validationRules = [
        'item_id'        => 'required|integer',
        'user_id'        => 'required|integer',
        'version_number' => 'required|integer|greater_than_equal_to[1]',
        'name'           => 'required|max_length[190]',
        'size_bytes'     => 'permit_empty|greater_than_equal_to[0]',
        'physical_path'  => 'required|max_length[255]',
        'physical_name'  => 'required|max_length[120]',
    ];
}
