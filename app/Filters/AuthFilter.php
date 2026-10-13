<?php

namespace App\Filters;

use App\Libraries\AuthService;
use App\Libraries\SystemSettingsService;
use App\Models\UserProfileModel;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session()->get('is_logged_in')) {
            return redirect()->to('/login')->with('error', 'Please sign in to continue.');
        }

        try {
            $settings = new SystemSettingsService();
            $lifetimeSeconds = max(15, $settings->getInt('session_lifetime_minutes', 120)) * 60;
            $startedAt = (int) (session()->get('auth_started_at') ?? time());
            if (! session()->has('auth_started_at')) {
                session()->set('auth_started_at', $startedAt);
            }

            if ($startedAt > 0 && time() - $startedAt > $lifetimeSeconds) {
                (new AuthService())->logout();

                return redirect()->to('/login')->with('error', 'Your session has expired. Please sign in again.');
            }

            $userId = (int) session()->get('user_id');
            $user = $userId > 0 ? (new UserModel())->where('id', $userId)->where('deleted_at', null)->first() : null;
            if ($user === null || (string) ($user['status'] ?? '') !== 'active') {
                (new AuthService())->logout();

                return redirect()->to('/login')->with('error', 'Your account is not active. Please sign in again.');
            }

            session()->set([
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
            ]);

            $this->applyLocale($userId, $request);

            $path = trim($request->getUri()->getPath(), '/');
            if (($path === 'drive' || str_starts_with($path, 'drive/')) && (string) $user['role'] !== 'user') {
                return service('response')
                    ->setStatusCode(403)
                    ->setBody(view('errors/html/error_403'));
            }

            if (! in_array((string) session()->get('role'), ['admin', 'super_admin'], true)) {
                if ($settings->isMaintenanceMode()) {
                    return service('response')
                        ->setStatusCode(503)
                        ->setBody(view('errors/html/maintenance', [
                            'title' => 'Maintenance',
                            'message' => $settings->maintenanceMessage(),
                        ]));
                }
            }
        } catch (\Throwable) {
            log_message('error', 'Authentication state could not be validated.');

            return service('response')
                ->setStatusCode(503)
                ->setHeader('Cache-Control', 'no-store')
                ->setBody('Authentication is temporarily unavailable. Please try again.');
        }

        return null;
    }

    private function applyLocale(int $userId, RequestInterface $request): void
    {
        $locale = 'en';

        try {
            $profile = (new UserProfileModel())->find($userId);
            $candidate = (string) ($profile['locale'] ?? session('locale') ?? 'en');
            if (in_array($candidate, ['en', 'id'], true)) {
                $locale = $candidate;
            }
        } catch (\Throwable) {
            $candidate = (string) (session('locale') ?? 'en');
            if (in_array($candidate, ['en', 'id'], true)) {
                $locale = $candidate;
            }
        }

        session()->set('locale', $locale);
        if (method_exists($request, 'setLocale')) {
            $request->setLocale($locale);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
