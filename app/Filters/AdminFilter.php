<?php

namespace App\Filters;

use App\Libraries\ActivityLogger;
use App\Libraries\AdminAuthorization;
use App\Models\UserProfileModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session()->get('is_logged_in')) {
            return redirect()->to('/login')->with('error', 'Please sign in to continue.');
        }

        $authorization = new AdminAuthorization();
        $user = $authorization->currentUser();

        if (! $authorization->isAdminRole($user)) {
            return service('response')
                ->setStatusCode(403)
                ->setBody(view('errors/html/error_403'));
        }

        session()->set([
            'role'     => $user['role'],
            'username' => $user['username'],
            'email'    => $user['email'],
        ]);
        $this->applyLocale((int) $user['id'], $request);

        $permission = $authorization->permissionForAdminPath(
            $request->getUri()->getPath(),
            method_exists($request, 'getMethod') ? $request->getMethod() : 'GET',
        );

        if ($permission !== null && ! $authorization->hasPermission((int) $user['id'], $permission)) {
            (new ActivityLogger())->record((int) $user['id'], 'admin.permission.denied', 'failure', [
                'type' => 'admin_route',
            ], [
                'permission' => $permission,
                'path'       => $request->getUri()->getPath(),
            ]);

            return service('response')
                ->setStatusCode(403)
                ->setBody(view('errors/html/error_403'));
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
