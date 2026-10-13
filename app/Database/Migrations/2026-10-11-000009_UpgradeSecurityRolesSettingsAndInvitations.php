<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpgradeSecurityRolesSettingsAndInvitations extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('invitation_type', 'invitation_codes')) {
            $this->forge->addColumn('invitation_codes', [
                'invitation_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'user',
                    'after'      => 'status',
                ],
                'recipient_email' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 254,
                    'null'       => true,
                    'after'      => 'invitation_type',
                ],
                'role_on_accept' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'user',
                    'after'      => 'recipient_email',
                ],
                'storage_quota_bytes' => [
                    'type'       => 'BIGINT',
                    'constraint' => 20,
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'role_on_accept',
                ],
                'note' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'storage_quota_bytes',
                ],
                'accepted_user_id' => [
                    'type'       => 'INT',
                    'constraint' => 10,
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'last_used_at',
                ],
                'accepted_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'accepted_user_id',
                ],
            ]);

            $this->forge->addKey(['invitation_type', 'status', 'expires_at'], false, false, 'idx_invitation_type_status');
            $this->forge->addKey('recipient_email', false, false, 'idx_invitation_recipient_email');
            $this->forge->addKey('accepted_user_id', false, false, 'idx_invitation_accepted_user');
            if ($this->db->DBDriver !== 'SQLite3') {
                $this->forge->addForeignKey('accepted_user_id', 'users', 'id', 'SET NULL', 'CASCADE', 'fk_invitation_accepted_user');
            }
            $this->forge->processIndexes('invitation_codes');
        }

        if (! $this->db->fieldExists('value_type', 'system_settings')) {
            $this->forge->addColumn('system_settings', [
                'value_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'default'    => 'string',
                    'after'      => 'value_text',
                ],
                'category' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'default'    => 'general',
                    'after'      => 'value_type',
                ],
                'metadata_json' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'category',
                ],
            ]);
            $this->forge->addKey(['category', 'setting_key'], false, false, 'idx_system_settings_category');
            $this->forge->processIndexes('system_settings');
        }

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
            'permission' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
            ],
            'granted_by' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'permission']);
        $this->forge->addKey(['permission', 'revoked_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('granted_by', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('admin_permissions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('admin_permissions', true);

        if ($this->db->fieldExists('value_type', 'system_settings')) {
            $this->forge->dropColumn('system_settings', ['value_type', 'category', 'metadata_json']);
        }

        if ($this->db->fieldExists('invitation_type', 'invitation_codes')) {
            if ($this->db->DBDriver !== 'SQLite3') {
                try {
                    $this->forge->dropForeignKey('invitation_codes', 'fk_invitation_accepted_user');
                } catch (\Throwable) {
                    // The key may be absent on databases migrated with SQLite-compatible rules.
                }
            }

            $this->forge->dropColumn('invitation_codes', [
                'invitation_type',
                'recipient_email',
                'role_on_accept',
                'storage_quota_bytes',
                'note',
                'accepted_user_id',
                'accepted_at',
            ]);
        }
    }
}
