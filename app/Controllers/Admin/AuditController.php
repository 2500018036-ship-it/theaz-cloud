<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AdminAuthorization;
use App\Libraries\ActivityLogger;
use CodeIgniter\HTTP\RedirectResponse;

class AuditController extends BaseController
{
    public function index(): string
    {
        $filters = [
            'q'       => $this->request->getGet('q'),
            'action'  => $this->request->getGet('action'),
            'status'  => $this->request->getGet('status'),
            'page'    => $this->request->getGet('page'),
            'perPage' => $this->request->getGet('perPage'),
        ];
        $result = (new ActivityLogger())->listForUser($this->currentUserId(), 'admin', $filters);
        $canDelete = (new AdminAuthorization())->hasPermission($this->currentUserId(), 'activity_logs.manage');

        return view('admin/audit', [
            'title'       => 'Audit Log',
            'pageTitle'   => 'Audit Log',
            'activeMenu'  => 'admin.audit',
            'breadcrumbs' => [
                ['label' => lang('UI.t4b42c6690ebe')],
                ['label' => lang('UI.t47751f88edff')],
            ],
            'currentUser' => $this->currentUser(),
            'logs'        => $result['items'],
            'pager'       => $result['pager'],
            'canDelete'   => $canDelete,
            'query'       => [
                'q'      => (string) ($this->request->getGet('q') ?? ''),
                'action' => (string) ($this->request->getGet('action') ?? ''),
                'status' => (string) ($this->request->getGet('status') ?? ''),
            ],
        ]);
    }

    public function delete(int $id): RedirectResponse
    {
        try {
            $userId = $this->currentUserId();
            (new AdminAuthorization())->requirePermission($userId, 'activity_logs.manage');
            (new ActivityLogger())->deleteById($userId, $id);

            return redirect()->to('/admin/audit')->with('message', 'Activity log entry deleted.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/audit')->with('error', $exception->getMessage());
        }
    }

    public function clear(): RedirectResponse
    {
        try {
            $userId = $this->currentUserId();
            (new AdminAuthorization())->requirePermission($userId, 'activity_logs.manage');
            $deleted = (new ActivityLogger())->clearAll($userId);

            if ($deleted === 0) {
                return redirect()->to('/admin/audit')->with('message', 'No activity logs to clear.');
            }

            return redirect()->to(site_url('admin/audit'))->with('message', 'Activity logs cleared.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/audit')->with('error', $exception->getMessage());
        }
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
