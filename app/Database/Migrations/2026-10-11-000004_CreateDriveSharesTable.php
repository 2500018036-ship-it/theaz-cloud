<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDriveSharesTable extends Migration
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
            'item_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'shared_with_user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'access_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'user',
            ],
            'permission' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'viewer',
            ],
            'token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token');
        $this->forge->addKey(['user_id', 'item_id']);
        $this->forge->addUniqueKey(['item_id', 'shared_with_user_id']);
        $this->forge->addKey(['shared_with_user_id', 'revoked_at']);
        $this->forge->addKey(['access_type', 'revoked_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('shared_with_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('item_id', 'drive_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('drive_shares', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('drive_shares', true);
    }
}
