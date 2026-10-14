<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AdminAuthorization;
use App\Libraries\AdminService;
use App\Libraries\SystemSettingsService;
use CodeIgniter\HTTP\RedirectResponse;

class SettingsController extends BaseController
{
    private SystemSettingsService $settings;

    public function __construct()
    {
        $this->settings = new SystemSettingsService();
    }

    public function index(): string
    {
        return view('admin/settings', [
            'title'       => 'System Settings',
            'pageTitle'   => 'System Settings',
            'activeMenu'  => 'admin.settings',
            'breadcrumbs' => [
                ['label' => lang('UI.tc1c224b03cd9'), 'url' => site_url('admin/dashboard')],
                ['label' => lang('UI.t1f87f883cc7e')],
            ],
            'currentUser' => $this->currentUser(),
            'settings'    => $this->settings->all(),
            'health'      => (new AdminService())->systemHealth(),
            'canManageSettings' => (new AdminAuthorization())->hasPermission($this->currentUserId(), 'system_settings.manage'),
        ]);
    }

    public function update(): RedirectResponse
    {
        try {
            $this->settings->updateGeneral($this->currentUserId(), $this->request->getPost());

            return redirect()->to('/admin/settings')->with('message', 'System settings updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/settings')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function maintenance(): RedirectResponse
    {
        try {
            $this->settings->updateMaintenance(
                $this->currentUserId(),
                (string) $this->request->getPost('maintenance_mode') === '1',
                (string) $this->request->getPost('maintenance_message'),
                (string) $this->request->getPost('maintenance_scheduled_at'),
            );

            return redirect()->to('/admin/settings')->with('message', 'Maintenance mode updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/settings')->withInput()->with('error', $exception->getMessage());
        }
    }

    private function currentUserId(): int
    {
        return (int) session('user_id');
    }

    private function currentUser(): array
    {
        return [
            'username' => session('username'),
            'email'    => session('email'),
            'role'     => session('role'),
        ];
    }
}
