<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpgradeDriveSharesForWorkspace extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if (! $this->sharesTableExists()) {
            $this->createSharesTable();
            return;
        }

        $fields = $this->db->getFieldNames('drive_shares');
        $add = [];

        if (! in_array('shared_with_user_id', $fields, true)) {
            $add['shared_with_user_id'] = [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'item_id',
            ];
        }

        if (! in_array('access_type', $fields, true)) {
            $add['access_type'] = [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'public',
                'after'      => 'shared_with_user_id',
            ];
        }

        if (! in_array('permission', $fields, true)) {
            $add['permission'] = [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'viewer',
                'after'      => 'access_type',
            ];
        }

        if (! in_array('revoked_at', $fields, true)) {
            $add['revoked_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'expires_at',
            ];
        }

        if (! in_array('created_by', $fields, true)) {
            $add['created_by'] = [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'revoked_at',
            ];
        }

        if (! in_array('updated_at', $fields, true)) {
            $add['updated_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'created_at',
            ];
        }

        if ($add !== []) {
            $this->forge->addColumn('drive_shares', $add);
            $this->db->resetDataCache();
        }

        if (! $this->sharesTableExists()) {
            $this->createSharesTable();
            return;
        }

        $this->db->query("UPDATE drive_shares SET access_type = 'public' WHERE token IS NOT NULL AND access_type IS NULL");
        $this->db->query("UPDATE drive_shares SET permission = 'viewer' WHERE permission IS NULL OR permission = ''");

        $this->createIndexIfMissing('drive_shares_user_fk', 'CREATE INDEX drive_shares_user_fk ON drive_shares (user_id)');
        $this->createIndexIfMissing('drive_shares_item_fk', 'CREATE INDEX drive_shares_item_fk ON drive_shares (item_id)');
        $this->dropLegacyUserItemUniqueIndex();
        $this->createIndexIfMissing('drive_shares_user_item', 'CREATE INDEX drive_shares_user_item ON drive_shares (user_id, item_id)');
        $this->createIndexIfMissing('drive_shares_recipient_revoked', 'CREATE INDEX drive_shares_recipient_revoked ON drive_shares (shared_with_user_id, revoked_at)');
        $this->createIndexIfMissing('drive_shares_access_revoked', 'CREATE INDEX drive_shares_access_revoked ON drive_shares (access_type, revoked_at)');
        $this->createIndexIfMissing('drive_shares_item_recipient_unique', 'CREATE UNIQUE INDEX drive_shares_item_recipient_unique ON drive_shares (item_id, shared_with_user_id)');
    }

    public function down(): void
    {
        // The original public-link schema is still compatible with the added columns.
    }

    private function dropLegacyUserItemUniqueIndex(): void
    {
        $indexes = $this->db->query('SHOW INDEX FROM drive_shares')->getResultArray();
        $byKey = [];

        foreach ($indexes as $index) {
            $key = (string) $index['Key_name'];
            $byKey[$key][] = $index;
        }

        foreach ($byKey as $key => $parts) {
            if ($key === 'PRIMARY' || (int) $parts[0]['Non_unique'] !== 0) {
                continue;
            }

            usort($parts, static fn (array $a, array $b): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']);
            $columns = array_column($parts, 'Column_name');

            if ($columns === ['user_id', 'item_id']) {
                $this->db->query('ALTER TABLE drive_shares DROP INDEX `' . str_replace('`', '``', $key) . '`');
            }
        }
    }

    private function sharesTableExists(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM drive_shares LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createIndexIfMissing(string $name, string $sql): void
    {
        $exists = $this->db->query(
            "SHOW INDEX FROM drive_shares WHERE Key_name = " . $this->db->escape($name),
        )->getRowArray();

        if ($exists === null) {
            $this->db->query($sql);
        }
    }

    private function createSharesTable(): void
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
}
