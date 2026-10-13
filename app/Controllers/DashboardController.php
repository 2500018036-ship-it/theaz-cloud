<?php

namespace App\Controllers;

use App\Libraries\DriveService;

class DashboardController extends BaseController
{
    public function index(): string
    {
        $overview = (new DriveService())->dashboardOverview((int) session('user_id'));

        return view('dashboard/user', [
            'title'       => 'Theaz Cloud',
            'pageTitle'   => 'Dashboard',
            'activeMenu'  => 'dashboard',
            'breadcrumbs' => [
                ['label' => 'Dashboard'],
            ],
            'currentUser' => [
                'username' => session('username'),
                'email'    => session('email'),
                'role'     => session('role'),
            ],
            'driveOverview' => $overview,
        ]);
    }
}
