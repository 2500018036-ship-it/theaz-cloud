<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSettingsNotificationsAndAnnouncements extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'display_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'bio' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'avatar_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'avatar_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
                'null'       => true,
            ],
            'pending_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 254,
                'null'       => true,
            ],
            'pending_email_token_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'pending_email_expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'email_verified_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'default_drive_view' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'list',
            ],
            'default_sort' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'name',
            ],
            'default_sort_dir' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'default'    => 'asc',
            ],
            'notify_in_app_announcements' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'notify_in_app_sharing' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'notify_in_app_security' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'email_announcements' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'email_sharing' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'email_updates' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
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
        $this->forge->addKey('user_id', true);
        $this->forge->addKey('pending_email_token_hash');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_profiles', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'body' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'action_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'related_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'related_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'read_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'read_at', 'created_at']);
        $this->forge->addKey(['user_id', 'category', 'created_at']);
        $this->forge->addKey(['related_type', 'related_id']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('notifications', true);

        $this->forge->addField([
            'setting_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'value_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'updated_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('setting_key', true);
        $this->forge->addForeignKey('updated_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('system_settings', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'body' => [
                'type' => 'TEXT',
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'default'    => 'announcement',
            ],
            'target_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'all',
            ],
            'target_user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'channels' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'notification',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'draft',
            ],
            'notification_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'pending',
            ],
            'email_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'pending',
            ],
            'created_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'published_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->addKey(['status', 'published_at']);
        $this->forge->addKey(['target_type', 'target_user_id']);
        $this->forge->addForeignKey('target_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('announcements', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
            ],
            'event_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'recipient_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 254,
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'body_text' => [
                'type' => 'TEXT',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'queued',
            ],
            'attempts' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'available_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'sent_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey(['event_type', 'event_id']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('email_queue', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('email_queue', true);
        $this->forge->dropTable('announcements', true);
        $this->forge->dropTable('system_settings', true);
        $this->forge->dropTable('notifications', true);
        $this->forge->dropTable('user_profiles', true);
    }
}
