<?php

namespace App\Controllers;

use App\Libraries\AdminManagementService;
use CodeIgniter\HTTP\RedirectResponse;

class AdminInvitationActivationController extends BaseController
{
    public function form(string $token): string|RedirectResponse
    {
        return redirect()
            ->to('/login')
            ->with('error', 'Admin invitation activation is disabled. Contact a super admin to create the administrator account.');
    }

    public function activate(string $token): RedirectResponse
    {
        try {
            (new AdminManagementService())->activateAdminFromInvitation(
                $token,
                (string) $this->request->getPost('username'),
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('password_confirm'),
            );

            return redirect()->to('/login')->with('message', 'Admin account activated. If email verification is required, check your inbox before signing in.');
        } catch (\Throwable $exception) {
            return redirect()->to('/login')->withInput()->with('error', $exception->getMessage());
        }
    }
}
