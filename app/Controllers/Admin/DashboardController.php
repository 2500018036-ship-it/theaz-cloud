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
                ['label' => lang('UI.t4b42c6690ebe')],
                ['label' => lang('UI.t67b696468610')],
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
