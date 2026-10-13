<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ActivityLogger;
use App\Libraries\AdminService;
use App\Libraries\SystemSettingsService;
use CodeIgniter\HTTP\RedirectResponse;

class UsersController extends BaseController
{
    private AdminService $admin;

    public function __construct()
    {
        $this->admin = new AdminService();
    }

    public function index(): string
    {
        $filters = $this->filters();
        $result = $this->admin->listUsers($filters);

        return view('admin/users', [
            'title'       => 'Users',
            'pageTitle'   => 'Users',
            'activeMenu'  => 'admin.users',
            'breadcrumbs' => [
                ['label' => 'Administration'],
                ['label' => 'Users'],
            ],
            'currentUser' => $this->currentUser(),
            'users'       => $result['items'],
            'pager'       => $result['pager'],
            'query'       => [
                'q'      => (string) ($this->request->getGet('q') ?? ''),
                'role'   => (string) ($this->request->getGet('role') ?? ''),
                'status' => (string) ($this->request->getGet('status') ?? ''),
            ],
        ]);
    }

    public function show(int $id): string|RedirectResponse
    {
        try {
            return view('admin/user_detail', [
                'title'       => 'User Detail',
                'pageTitle'   => 'User Detail',
                'activeMenu'  => 'admin.users',
                'breadcrumbs' => [
                    ['label' => 'Administration'],
                    ['label' => 'Users', 'url' => site_url('admin/users')],
                    ['label' => 'Detail'],
                ],
                'currentUser' => $this->currentUser(),
                'detail'      => $this->admin->userDetail($id),
            ]);
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/users')->with('error', $exception->getMessage());
        }
    }

    public function updateStatus(int $id): RedirectResponse
    {
        try {
            $this->admin->updateUserStatus($this->currentUserId(), $id, (string) $this->request->getPost('status'));

            return $this->redirectBackToUser($id)->with('message', 'User status updated.');
        } catch (\Throwable $exception) {
            (new ActivityLogger())->record($this->currentUserId(), 'admin.user.status', 'failure', [
                'type' => 'user',
                'id'   => $id,
            ], [
                'error'  => $exception->getMessage(),
                'status' => (string) $this->request->getPost('status'),
            ], $id);

            return $this->redirectBackToUser($id)->with('error', $exception->getMessage());
        }
    }

    public function updateRole(int $id): RedirectResponse
    {
        try {
            $this->admin->updateUserRole($this->currentUserId(), $id, (string) $this->request->getPost('role'));

            return $this->redirectBackToUser($id)->with('message', 'User role updated.');
        } catch (\Throwable $exception) {
            (new ActivityLogger())->record($this->currentUserId(), 'admin.user.role', 'failure', [
                'type' => 'user',
                'id'   => $id,
            ], [
                'error' => $exception->getMessage(),
                'role'  => (string) $this->request->getPost('role'),
            ], $id);

            return $this->redirectBackToUser($id)->with('error', $exception->getMessage());
        }
    }

    public function updateQuota(int $id): RedirectResponse
    {
        try {
            $quotaBytes = $this->quotaBytesFromPost();
            $this->admin->updateUserQuota($this->currentUserId(), $id, $quotaBytes);

            return $this->redirectBackToUser($id)->with('message', 'User quota updated.');
        } catch (\Throwable $exception) {
            (new ActivityLogger())->record($this->currentUserId(), 'admin.user.quota', 'failure', [
                'type' => 'user',
                'id'   => $id,
            ], [
                'error' => $exception->getMessage(),
            ], $id);

            return $this->redirectBackToUser($id)->with('error', $exception->getMessage());
        }
    }

    private function filters(): array
    {
        return [
            'q'       => $this->request->getGet('q'),
            'role'    => $this->request->getGet('role'),
            'status'  => $this->request->getGet('status'),
            'page'    => $this->request->getGet('page'),
            'perPage' => $this->request->getGet('perPage'),
        ];
    }

    private function quotaBytesFromPost(): int
    {
        $value = (float) $this->request->getPost('quota_value');
        $unit = strtolower((string) $this->request->getPost('quota_unit'));
        $multiplier = match ($unit) {
            'mb' => 1048576,
            'tb' => 1099511627776,
            default => 1073741824,
        };

        if ($value < 0) {
            throw new \DomainException('Quota must be positive.');
        }

        $bytes = (int) round($value * $multiplier);
        $max = (new SystemSettingsService())->getInt('max_admin_assignable_quota_bytes', 10_737_418_240);
        if ($bytes > $max) {
            throw new \DomainException('Quota exceeds the allowed policy.');
        }

        return $bytes;
    }

    private function redirectBackToUser(int $id): RedirectResponse
    {
        $returnTo = (string) $this->request->getPost('return_to');
        if ($returnTo === 'dashboard') {
            return redirect()->to('/admin/dashboard');
        }

        if ($returnTo === 'management') {
            return redirect()->to('/admin/management');
        }

        if ($returnTo === 'detail') {
            return redirect()->to('/admin/users/' . $id);
        }

        return redirect()->to('/admin/users');
    }

    private function currentUserId(): int
    {
        return (int) session('user_id');
    }

    /**
     * @return array{username: mixed, email: mixed, role: mixed}
     */
    private function currentUser(): array
    {
        return [
            'username' => session('username'),
            'email'    => session('email'),
            'role'     => session('role'),
        ];
    }
}
