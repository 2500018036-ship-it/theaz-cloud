<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVersionAndActivityTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'version_number' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'extension' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'mime_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'size_bytes' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'physical_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'physical_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'checksum_sha256' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'source_version_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['item_id', 'created_at']);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey(['item_id', 'version_number']);
        $this->forge->addForeignKey('item_id', 'drive_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('drive_file_versions', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'actor_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'subject_user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
            ],
            'target_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'target_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'target_owner_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'target_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'metadata_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['actor_id', 'created_at']);
        $this->forge->addKey(['subject_user_id', 'created_at']);
        $this->forge->addKey(['target_owner_id', 'created_at']);
        $this->forge->addKey(['target_type', 'target_id', 'created_at']);
        $this->forge->addKey(['action', 'created_at']);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('actor_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('subject_user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('target_owner_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('activity_logs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('activity_logs', true);
        $this->forge->dropTable('drive_file_versions', true);
    }
}
