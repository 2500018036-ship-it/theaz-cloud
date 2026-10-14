<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ArchiveInvitationsAndIndexEmailQueue extends Migration
{
    public function up()
    {
        $this->forge->addColumn('invitation_codes', ['deleted_at' => ['type' => 'DATETIME', 'null' => true]]);
        $this->sql('CREATE INDEX invitations_visible_status ON invitation_codes(deleted_at, invitation_type, status, id)');
        $this->sql('CREATE INDEX email_queue_due ON email_queue(status, available_at, attempts, id)');
    }

    public function down()
    {
        $this->sql($this->db->DBDriver === 'SQLite3' ? 'DROP INDEX email_queue_due' : 'DROP INDEX email_queue_due ON email_queue');
        $this->sql($this->db->DBDriver === 'SQLite3' ? 'DROP INDEX invitations_visible_status' : 'DROP INDEX invitations_visible_status ON invitation_codes');
        $this->forge->dropColumn('invitation_codes', 'deleted_at');
    }
    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\b(users|invitation_codes|email_queue)\b/', fn ($m) => $this->db->protectIdentifiers($this->db->prefixTable($m[1])), $sql);
        $this->db->query($sql);
    }
}
