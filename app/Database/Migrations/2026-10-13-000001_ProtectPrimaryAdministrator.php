<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ProtectPrimaryAdministrator extends Migration
{
    public function up()
    {
        // Preserve the provisioned owner and all existing account data.
        $owner = $this->db->table('users')->where('role', 'super_admin')->where('deleted_at', null)
            ->orderBy('id')->get()->getResultArray();
        $preferred = (string) env('AUTH_BOOTSTRAP_ADMIN_USERNAME', 'azril');
        $id = (int) ($owner[0]['id'] ?? 0);
        foreach ($owner as $row) {
            if ($row['username'] === $preferred) {
                $id = (int) $row['id'];
                break;
            }
        }
        $this->db->table('users')->whereIn('role', ['admin', 'super_admin'])->where('id !=', $id)->update(['role' => 'user']);
        if ($id > 0) {
            $this->db->table('users')->where('id', $id)->update(['status' => 'active']);
        }
        $this->sql("ALTER TABLE users ADD COLUMN primary_admin_slot INTEGER GENERATED ALWAYS AS (CASE WHEN role IN ('admin', 'super_admin') THEN 1 ELSE NULL END) VIRTUAL");
        $this->sql('CREATE UNIQUE INDEX users_one_primary_admin ON users(primary_admin_slot)');
        if ($this->db->DBDriver === 'SQLite3') {
            $this->sql("CREATE TRIGGER users_protect_owner_update BEFORE UPDATE ON users WHEN OLD.role = 'super_admin' AND (NEW.role != 'super_admin' OR NEW.status != 'active' OR NEW.deleted_at IS NOT NULL) BEGIN SELECT RAISE(ABORT, 'Primary administrator is permanent'); END");
            $this->sql("CREATE TRIGGER users_protect_owner_delete BEFORE DELETE ON users WHEN OLD.role = 'super_admin' BEGIN SELECT RAISE(ABORT, 'Primary administrator is permanent'); END");
            $this->sql("CREATE TRIGGER users_reject_staff_admin BEFORE INSERT ON users WHEN NEW.role = 'admin' BEGIN SELECT RAISE(ABORT, 'Additional administrators are disabled'); END");
            $this->sql("CREATE TRIGGER users_reject_role_change BEFORE UPDATE ON users WHEN OLD.role != NEW.role BEGIN SELECT RAISE(ABORT, 'Role changes are disabled'); END");
        } else {
            $this->sql("CREATE TRIGGER users_protect_owner_update BEFORE UPDATE ON users FOR EACH ROW BEGIN IF (OLD.role = 'super_admin' AND (NEW.role <> 'super_admin' OR NEW.status <> 'active' OR NEW.deleted_at IS NOT NULL)) OR OLD.role <> NEW.role THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Primary administrator and roles are permanent'; END IF; END");
            $this->sql("CREATE TRIGGER users_protect_owner_delete BEFORE DELETE ON users FOR EACH ROW BEGIN IF OLD.role = 'super_admin' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Primary administrator is permanent'; END IF; END");
            $this->sql("CREATE TRIGGER users_reject_staff_admin BEFORE INSERT ON users FOR EACH ROW BEGIN IF NEW.role = 'admin' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Additional administrators are disabled'; END IF; END");
        }
    }

    public function down()
    {
        foreach (['users_protect_owner_update', 'users_protect_owner_delete', 'users_reject_staff_admin', 'users_reject_role_change'] as $trigger) {
            $this->sql('DROP TRIGGER IF EXISTS ' . $trigger);
        }
        $this->sql($this->db->DBDriver === 'SQLite3' ? 'DROP INDEX users_one_primary_admin' : 'DROP INDEX users_one_primary_admin ON users');
        $this->forge->dropColumn('users', 'primary_admin_slot');
    }
    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\b(users|invitation_codes|email_queue)\b/', fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $this->db->query($sql);
    }
}
