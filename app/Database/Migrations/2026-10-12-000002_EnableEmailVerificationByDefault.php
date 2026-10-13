<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\I18n\Time;

class EnableEmailVerificationByDefault extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('system_settings')) {
            return;
        }

        $now = Time::now()->toDateTimeString();
        $payload = [
            'setting_key' => 'email_verification_required',
            'value_text'  => '1',
            'updated_by'  => null,
            'updated_at'  => $now,
        ];

        if ($this->db->fieldExists('value_type', 'system_settings')) {
            $payload['value_type'] = 'boolean';
        }

        if ($this->db->fieldExists('category', 'system_settings')) {
            $payload['category'] = 'registration';
        }

        if ($this->db->fieldExists('metadata_json', 'system_settings')) {
            $payload['metadata_json'] = null;
        }

        $exists = $this->db->table('system_settings')
            ->where('setting_key', 'email_verification_required')
            ->countAllResults() > 0;

        if ($exists) {
            $this->db->table('system_settings')
                ->where('setting_key', 'email_verification_required')
                ->update($payload);

            return;
        }

        $this->db->table('system_settings')->insert($payload);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('system_settings')) {
            return;
        }

        $this->db->table('system_settings')
            ->where('setting_key', 'email_verification_required')
            ->update([
                'value_text' => '0',
                'updated_at' => Time::now()->toDateTimeString(),
            ]);
    }
}
