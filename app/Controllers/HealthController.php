<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\CloudDrive;

class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setJSON(['status' => 'ok']);
    }

    public function ready(): ResponseInterface
    {
        try {
            $db = Database::connect();
            $db->query('SELECT 1');
            foreach (['users', 'invitation_codes', 'drive_items', 'system_settings', 'user_profiles', 'email_queue'] as $table) {
                if (! $db->tableExists($table)) {
                    throw new \RuntimeException('Schema not ready.');
                }
            }
            if (! is_writable(WRITEPATH . 'session') || ! is_writable(config(CloudDrive::class)->storagePath)) {
                throw new \RuntimeException('Dependency not ready.');
            }
            return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['status' => 'ready']);
        } catch (\Throwable) {
            return $this->response->setStatusCode(503)->setHeader('Cache-Control', 'no-store')
                ->setJSON(['status' => 'unavailable']);
        }
    }
}
