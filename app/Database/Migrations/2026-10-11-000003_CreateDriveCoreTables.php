<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDriveCoreTables extends Migration
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
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'parent_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'original_parent_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'normalized_name' => [
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
                'null'       => true,
            ],
            'physical_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'checksum_sha256' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'active',
            ],
            'last_opened_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'trashed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'trashed_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'parent_id', 'status', 'type']);
        $this->forge->addKey(['user_id', 'status', 'trashed_at']);
        $this->forge->addKey(['user_id', 'type', 'updated_at']);
        $this->forge->addKey(['user_id', 'normalized_name']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('parent_id', 'drive_items', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('original_parent_id', 'drive_items', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('trashed_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('drive_items', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'item_id']);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('item_id', 'drive_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('drive_favorites', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('drive_favorites', true);
        $this->forge->dropTable('drive_items', true);
    }
}
