<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLocaleToUserProfiles extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('user_profiles') || $this->db->fieldExists('locale', 'user_profiles')) {
            return;
        }

        $this->forge->addColumn('user_profiles', [
            'locale' => [
                'type'       => 'VARCHAR',
                'constraint' => 5,
                'default'    => 'en',
                'after'      => 'email_verified_at',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('user_profiles') && $this->db->fieldExists('locale', 'user_profiles')) {
            $this->forge->dropColumn('user_profiles', 'locale');
        }
    }
}
