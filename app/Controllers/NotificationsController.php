<?php

namespace App\Controllers;

use App\Libraries\NotificationService;
use CodeIgniter\HTTP\RedirectResponse;

class NotificationsController extends BaseController
{
    private NotificationService $notifications;

    public function __construct()
    {
        $this->notifications = new NotificationService();
    }

    public function index(): string
    {
        $filters = [
            'page'     => $this->request->getGet('page'),
            'perPage'  => $this->request->getGet('perPage'),
            'status'   => $this->request->getGet('status'),
            'category' => $this->request->getGet('category'),
        ];
        $result = $this->notifications->listForUser($this->currentUserId(), $filters);

        return view('notifications/index', [
            'title'       => 'Notifications',
            'pageTitle'   => 'Notifications',
            'activeMenu'  => 'notifications',
            'breadcrumbs' => [
                ['label' => lang('UI.t87bb59ba2f92')],
                ['label' => lang('UI.t788011833a5a')],
            ],
            'currentUser' => $this->currentUser(),
            'notifications' => $result['items'],
            'pager'       => $result['pager'],
            'query'       => [
                'status'   => (string) ($this->request->getGet('status') ?? ''),
                'category' => (string) ($this->request->getGet('category') ?? ''),
            ],
        ]);
    }

    public function markRead(int $id): RedirectResponse
    {
        $this->notifications->markRead($this->currentUserId(), $id);

        return redirect()->to('/notifications')->with('message', 'Notification marked as read.');
    }

    public function summary(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setJSON($this->notifications->summary($this->currentUserId()));
    }

    public function markAllRead(): RedirectResponse
    {
        $this->notifications->markAllRead($this->currentUserId());

        return redirect()->to('/notifications')->with('message', 'All notifications marked as read.');
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
