<?php

namespace App\Models;

use CodeIgniter\Model;

class SystemSettingModel extends Model
{
    protected $table = 'system_settings';

    protected $primaryKey = 'setting_key';

    protected $returnType = 'array';

    protected $useAutoIncrement = false;

    protected $allowedFields = [
        'setting_key',
        'value_text',
        'value_type',
        'category',
        'metadata_json',
        'updated_by',
        'updated_at',
    ];
}
