<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class HealthController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setJSON(['status' => 'ok']);
    }
}
