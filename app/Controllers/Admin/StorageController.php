<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AdminService;

class StorageController extends BaseController
{
    public function index(): string
    {
        $service = new AdminService();
        $users = $service->storageUsers([
            'page'    => $this->request->getGet('page'),
            'perPage' => $this->request->getGet('perPage'),
        ]);

        return view('admin/storage', [
            'title'       => 'Storage',
            'pageTitle'   => 'Storage',
            'activeMenu'  => 'admin.storage',
            'breadcrumbs' => [
                ['label' => 'Administration'],
                ['label' => 'Storage'],
            ],
            'currentUser' => [
                'username' => session('username'),
                'email'    => session('email'),
                'role'     => session('role'),
            ],
            'storage' => $service->storageMetrics(),
            'drive'   => $service->driveMetrics(),
            'users'   => $users['items'],
            'pager'   => $users['pager'],
        ]);
    }
}
