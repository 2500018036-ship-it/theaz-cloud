<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Home extends BaseController
{
    public function index(): RedirectResponse
    {
        if (! session('is_logged_in')) {
            return redirect()->to('/login');
        }

        return in_array((string) session('role'), ['admin', 'super_admin'], true)
            ? redirect()->to('/admin/dashboard')
            : redirect()->to('/dashboard');
    }
}
