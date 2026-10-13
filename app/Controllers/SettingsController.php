<?php

namespace App\Controllers;

use App\Libraries\SettingsService;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class SettingsController extends BaseController
{
    private SettingsService $settings;

    public function __construct()
    {
        $this->settings = new SettingsService();
    }

    public function index(): string
    {
        $data = $this->settings->settingsFor($this->currentUserId());
        $role = (string) session('role');
        $isAdminAccount = in_array($role, ['admin', 'super_admin'], true);

        return view('settings/index', [
            'title'       => 'Settings',
            'pageTitle'   => 'My Account Settings',
            'activeMenu'  => $isAdminAccount ? '' : 'settings',
            'settingsLayout' => $isAdminAccount ? 'layouts/admin' : 'layouts/user',
            'isAdminAccount' => $isAdminAccount,
            'breadcrumbs' => [
                ['label' => $isAdminAccount ? 'Administration' : 'Workspace', 'url' => $isAdminAccount ? site_url('admin/dashboard') : site_url('dashboard')],
                ['label' => 'My Account Settings'],
            ],
            'currentUser' => $this->currentUser(),
            'user'        => $data['user'],
            'profile'     => $data['profile'],
            'notificationSummary' => $data['notifications'],
        ]);
    }

    public function updateProfile(): RedirectResponse
    {
        try {
            $this->settings->updateProfile($this->currentUserId(), $this->request->getPost());

            return redirect()->to('/settings')->with('message', 'Profile updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function updatePreferences(): RedirectResponse
    {
        try {
            $profile = $this->settings->updatePreferences($this->currentUserId(), $this->request->getPost());
            if (in_array((string) ($profile['locale'] ?? ''), ['en', 'id'], true)) {
                session()->set('locale', (string) $profile['locale']);
            }

            return redirect()->to('/settings')->with('message', 'Preferences updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function changePassword(): RedirectResponse
    {
        try {
            $this->settings->changePassword(
                $this->currentUserId(),
                (string) $this->request->getPost('current_password'),
                (string) $this->request->getPost('new_password'),
                (string) $this->request->getPost('new_password_confirm'),
            );

            return redirect()->to('/settings')->with('message', 'Password updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->with('error', $exception->getMessage());
        }
    }

    public function requestEmail(): RedirectResponse
    {
        try {
            $this->settings->requestEmailChange($this->currentUserId(), (string) $this->request->getPost('email'));

            return redirect()->to('/settings')->with('message', 'Verification email has been queued for the new address.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function verifyEmail(string $token): RedirectResponse
    {
        try {
            $userId = $this->settings->confirmEmailChange($token);
            if ($userId === $this->currentUserId()) {
                $data = $this->settings->settingsFor($userId);
                session()->set('email', $data['user']['email']);
            }

            return redirect()->to('/settings')->with('message', 'Email address verified.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->with('error', $exception->getMessage());
        }
    }

    public function updateAvatar(): RedirectResponse
    {
        try {
            $file = $this->request->getFile('avatar');
            if ($file === null) {
                throw new \DomainException('Avatar file is required.');
            }

            $this->settings->storeAvatar($this->currentUserId(), $file);

            return redirect()->to('/settings')->with('message', 'Avatar updated.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->with('error', $exception->getMessage());
        }
    }

    public function deleteAvatar(): RedirectResponse
    {
        try {
            $this->settings->deleteAvatar($this->currentUserId());

            return redirect()->to('/settings')->with('message', 'Avatar removed.');
        } catch (\Throwable $exception) {
            return redirect()->to('/settings')->with('error', $exception->getMessage());
        }
    }

    public function avatar(): DownloadResponse|ResponseInterface
    {
        $file = $this->settings->avatarFile($this->currentUserId());
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('');
        }

        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setHeader('Cache-Control', 'private, max-age=3600');

        return $this->response
            ->download($file['path'], null, true)
            ->setFileName($file['name'])
            ->inline();
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
