<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnableZipUploadsAnd120GbLimits extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'batch_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'parent_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'archive_name' => ['type' => 'VARCHAR', 'constraint' => 190],
            'file_count' => ['type' => 'INT', 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'item_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('batch_id', true);
        $this->forge->addKey(['user_id', 'status']);
        $this->forge->createTable('upload_batches', true);
        $this->forge->addColumn('chunked_uploads', [
            'batch_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        ]);

        // Existing installations keep their user quotas; raise the administrative/file policies.
        foreach (['max_admin_assignable_quota_bytes' => 'registration', 'max_upload_size_bytes' => 'storage'] as $key => $category) {
            $row = $this->db->table('system_settings')->where('setting_key', $key)->get()->getRowArray();
            if ($row !== null) {
                $this->db->table('system_settings')->where('setting_key', $key)->update(['value_text' => '128849018880']);
            } else {
                $this->db->table('system_settings')->insert([
                    'setting_key' => $key, 'value_text' => '128849018880', 'value_type' => 'integer', 'category' => $category,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropColumn('chunked_uploads', 'batch_id');
        $this->forge->dropTable('upload_batches', true);
    }
}
