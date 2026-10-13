<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AdminAuthorization;
use App\Libraries\AdminManagementService;
use App\Libraries\SystemSettingsService;
use CodeIgniter\HTTP\RedirectResponse;

class ManagementController extends BaseController
{
    private AdminManagementService $management;

    public function __construct()
    {
        $this->management = new AdminManagementService();
    }

    public function index(): string
    {
        $overview = $this->management->overview([
            'admin_page' => $this->request->getGet('admin_page'),
            'invite_page' => $this->request->getGet('invite_page'),
        ]);

        return view('admin/management', [
            'title'       => 'Admin Management',
            'pageTitle'   => 'Admin Management',
            'activeMenu'  => 'admin.management',
            'breadcrumbs' => [
                ['label' => 'Administration', 'url' => site_url('admin/dashboard')],
                ['label' => 'Admin Management'],
            ],
            'currentUser' => $this->currentUser(),
            'admins'      => $overview['admins'],
            'invitations' => $overview['invitations'],
            'activity'    => $overview['activity'],
            'permissionLabels' => AdminAuthorization::PERMISSION_LABELS,
            'permissionKeys' => AdminAuthorization::PERMISSIONS,
        ]);
    }

    public function createAdmin(): RedirectResponse
    {
        try {
            $admin = $this->management->createAdminAccount($this->currentUserId(), $this->request->getPost());

            return redirect()
                ->to('/admin/management')
                ->with('message', 'Admin account created for ' . (string) ($admin['email'] ?? 'the selected email') . '.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/management')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function updatePermissions(int $id): RedirectResponse
    {
        try {
            $permissions = $this->request->getPost('permissions') ?? [];
            $this->management->updateAdminPermissions($this->currentUserId(), $id, is_array($permissions) ? $permissions : [$permissions]);

            return redirect()->to('/admin/management')->with('message', 'Admin permissions updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/management')->with('error', $exception->getMessage());
        }
    }

    public function invite(): RedirectResponse
    {
        try {
            $quotaBytes = $this->quotaBytesFromPost();
            $invite = $this->management->createAdminInvitation(
                $this->currentUserId(),
                (string) $this->request->getPost('email'),
                (string) $this->request->getPost('expires_at') ?: null,
                $quotaBytes,
            );

            return redirect()
                ->to('/admin/management')
                ->with('message', 'Admin invitation created. The activation link is shown once.')
                ->with('admin_invitation_link', $invite['activation_url'])
                ->with('admin_invitation_email', $invite['email']);
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/management')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function revokeInvitation(int $id): RedirectResponse
    {
        try {
            $this->management->revokeAdminInvitation($this->currentUserId(), $id);

            return redirect()->to('/admin/management')->with('message', 'Admin invitation revoked.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/management')->with('error', $exception->getMessage());
        }
    }

    public function resendInvitation(int $id): RedirectResponse
    {
        try {
            $invite = $this->management->resendAdminInvitation($this->currentUserId(), $id);

            return redirect()
                ->to('/admin/management')
                ->with('message', 'Admin invitation link regenerated. The activation link is shown once.')
                ->with('admin_invitation_link', $invite['activation_url'])
                ->with('admin_invitation_email', $invite['email']);
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/management')->with('error', $exception->getMessage());
        }
    }

    private function quotaBytesFromPost(): ?int
    {
        $value = (float) $this->request->getPost('quota_value');
        if ($value <= 0) {
            return null;
        }

        $unit = strtolower((string) $this->request->getPost('quota_unit'));
        $multiplier = match ($unit) {
            'mb' => 1048576,
            'tb' => 1099511627776,
            default => 1073741824,
        };

        $bytes = (int) round($value * $multiplier);
        $max = (new SystemSettingsService())->getInt('max_admin_assignable_quota_bytes', 10_737_418_240);
        if ($bytes > $max) {
            throw new \DomainException('Admin invitation quota exceeds the allowed policy.');
        }

        return $bytes;
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
