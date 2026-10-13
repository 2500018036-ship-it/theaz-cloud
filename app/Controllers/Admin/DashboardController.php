<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AdminService;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $summary = (new AdminService())->dashboard();

        return view('admin/dashboard', [
            'title'       => 'Admin Dashboard',
            'pageTitle'   => 'Admin Dashboard',
            'activeMenu'  => 'admin.dashboard',
            'breadcrumbs' => [
                ['label' => 'Administration'],
                ['label' => 'Dashboard'],
            ],
            'currentUser' => [
                'username' => session('username'),
                'email'    => session('email'),
                'role'     => session('role'),
            ],
            'summary' => $summary,
        ]);
    }
}
