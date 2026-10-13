<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\NotificationService;
use CodeIgniter\HTTP\RedirectResponse;

class AnnouncementsController extends BaseController
{
    private NotificationService $notifications;

    public function __construct()
    {
        $this->notifications = new NotificationService();
    }

    public function index(): string
    {
        $result = $this->notifications->listAnnouncements([
            'page'    => $this->request->getGet('page'),
            'perPage' => $this->request->getGet('perPage'),
            'status'  => $this->request->getGet('status'),
        ]);

        return view('admin/announcements', [
            'title'       => 'Announcements',
            'pageTitle'   => 'Announcements',
            'activeMenu'  => 'admin.announcements',
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => site_url('admin/dashboard')],
                ['label' => 'Announcements'],
            ],
            'currentUser' => $this->currentUser(),
            'announcements' => $result['items'],
            'pager'       => $result['pager'],
            'query'       => [
                'status' => (string) ($this->request->getGet('status') ?? ''),
            ],
            'users'       => db_connect()->table('users')
                ->select('id, username, email')
                ->where('status', 'active')
                ->orderBy('username', 'ASC')
                ->limit(500)
                ->get()
                ->getResultArray(),
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            $announcement = $this->notifications->createAnnouncement($this->currentUserId(), $this->request->getPost());

            if ((string) $this->request->getPost('publish_now') === '1') {
                $this->notifications->publishAnnouncement($this->currentUserId(), (int) $announcement['id']);
            }

            return redirect()->to('/admin/announcements')->with('message', 'Announcement saved.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/announcements')->withInput()->with('error', $exception->getMessage());
        }
    }

    public function publish(int $id): RedirectResponse
    {
        try {
            $this->notifications->publishAnnouncement($this->currentUserId(), $id);

            return redirect()->to('/admin/announcements')->with('message', 'Announcement published.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/announcements')->with('error', $exception->getMessage());
        }
    }

    public function archive(int $id): RedirectResponse
    {
        try {
            $this->notifications->archiveAnnouncement($this->currentUserId(), $id);

            return redirect()->to('/admin/announcements')->with('message', 'Announcement archived.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/announcements')->with('error', $exception->getMessage());
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
