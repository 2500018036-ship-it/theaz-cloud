<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class TrackAnnouncementRequests extends Migration
{
    public function up()
    {
        $this->forge->addColumn('announcements', [
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            'request_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        ]);
        $table = $this->db->escapeIdentifiers($this->db->prefixTable('announcements'));
        $this->db->query('CREATE UNIQUE INDEX announcement_request_key ON ' . $table . '(request_key)');
        $this->db->query('CREATE INDEX announcements_visible_status ON ' . $table . '(deleted_at,status,id)');
        $queue = $this->db->escapeIdentifiers($this->db->prefixTable('email_queue'));
        $this->db->query('CREATE INDEX email_event_delivery ON ' . $queue . '(event_type,event_id,status)');
    }
    public function down()
    {
        foreach (['announcements' => ['announcement_request_key', 'announcements_visible_status'], 'email_queue' => ['email_event_delivery']] as $table => $indexes) {
            foreach ($indexes as $index) {
                $this->db->query('DROP INDEX ' . $index . ($this->db->DBDriver === 'SQLite3' ? '' : ' ON ' . $this->db->escapeIdentifiers($this->db->prefixTable($table))));
            }
        }
        $this->forge->dropColumn('announcements', ['deleted_at', 'request_key']);
    }
}
