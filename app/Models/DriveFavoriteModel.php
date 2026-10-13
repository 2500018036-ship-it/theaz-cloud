<?php

namespace App\Models;

use CodeIgniter\Model;

class DriveFavoriteModel extends Model
{
    protected $table = 'drive_favorites';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $allowedFields = [
        'user_id',
        'item_id',
    ];

    protected $useTimestamps = true;

    protected $createdField = 'created_at';

    protected $updatedField = '';

    protected $validationRules = [
        'user_id' => 'required|integer',
        'item_id' => 'required|integer',
    ];
}
