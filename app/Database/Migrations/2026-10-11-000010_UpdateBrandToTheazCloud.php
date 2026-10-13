<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\I18n\Time;

class UpdateBrandToTheazCloud extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('system_settings')) {
            return;
        }

        $now = Time::now()->toDateTimeString();
        $existing = $this->db->table('system_settings')
            ->where('setting_key', 'app_name')
            ->get()
            ->getRowArray();

        if ($existing === null) {
            $this->db->table('system_settings')->insert([
                'setting_key' => 'app_name',
                'value_text'  => 'Theaz Cloud',
                'value_type'  => 'string',
                'category'    => 'general',
                'updated_at'  => $now,
            ]);

            return;
        }

        if ((string) ($existing['value_text'] ?? '') === 'Cloud Drive') {
            $this->db->table('system_settings')
                ->where('setting_key', 'app_name')
                ->update([
                    'value_text' => 'Theaz Cloud',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('system_settings')) {
            return;
        }

        $this->db->table('system_settings')
            ->where('setting_key', 'app_name')
            ->where('value_text', 'Theaz Cloud')
            ->update([
                'value_text' => 'Cloud Drive',
                'updated_at' => Time::now()->toDateTimeString(),
            ]);
    }
}
