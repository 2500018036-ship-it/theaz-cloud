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
            'q'       => $this->request->getGet('q'),
        ]);

        return view('admin/announcements', [
            'title'       => 'Announcements',
            'pageTitle'   => 'Announcements',
            'activeMenu'  => 'admin.announcements',
            'breadcrumbs' => [
                ['label' => lang('UI.tc1c224b03cd9'), 'url' => site_url('admin/dashboard')],
                ['label' => lang('UI.tfe02680f2479')],
            ],
            'currentUser' => $this->currentUser(),
            'announcements' => $result['items'],
            'pager'       => $result['pager'],
            'query'       => [
                'status' => (string) ($this->request->getGet('status') ?? ''),
                'q'      => (string) ($this->request->getGet('q') ?? ''),
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
            if (! preg_match('/\A[a-f0-9]{64}\z/', (string) $this->request->getPost('request_key'))) throw new \DomainException('Invalid announcement request. Refresh the form.');
            $announcement = $this->notifications->createAnnouncement($this->currentUserId(), $this->request->getPost());

            if ((string) $this->request->getPost('publish_now') === '1') {
                $this->notifications->publishAnnouncement($this->currentUserId(), (int) $announcement['id']);
            }

            return redirect()->to('/admin/announcements')->with('message', 'Announcement saved. Email delivery respects each user’s announcement email preference.');
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

    public function delete(int $id): RedirectResponse
    {
        try {
            $this->notifications->deleteAnnouncement($this->currentUserId(), $id);
            return redirect()->to('/admin/announcements')->with('message', 'Announcement deleted.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/announcements')->with('error', $exception->getMessage());
        }
    }

    public function bulk(): RedirectResponse
    {
        try {
            $this->notifications->bulkAnnouncements($this->currentUserId(), $this->request->getPost('selected'), (string) $this->request->getPost('bulk_action'));
            return redirect()->to('/admin/announcements')->with('message', 'Bulk action completed.');
        } catch (\Throwable $exception) {
            return redirect()->to('/admin/announcements')->with('error', $exception->getMessage());
        }
    }

    public function delivery(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $ids = \App\Libraries\BulkSelection::ids($this->request->getGet('selected'));
            return $this->response->setJSON(['items' => (new \App\Libraries\AnnouncementDeliveryService())->summary($ids)]);
        } catch (\DomainException $exception) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $exception->getMessage()]);
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
