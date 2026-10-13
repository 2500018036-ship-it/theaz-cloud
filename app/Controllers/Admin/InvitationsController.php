<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ActivityLogger;
use App\Libraries\AdminService;
use App\Libraries\InvitationService;
use App\Libraries\SystemSettingsService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\I18n\Time;

class InvitationsController extends BaseController
{
    public function index(): string
    {
        $service = new InvitationService();
        $service->expireStale();

        $result = (new AdminService())->listInvitations([
            'q'       => $this->request->getGet('q'),
            'status'  => $this->request->getGet('status'),
            'page'    => $this->request->getGet('page'),
            'perPage' => $this->request->getGet('perPage'),
        ]);

        return view('admin/invitations', [
            'title'       => 'Invitations',
            'pageTitle'   => 'Invitations',
            'activeMenu'  => 'admin.invitations',
            'breadcrumbs' => [
                ['label' => 'Administration'],
                ['label' => 'Invitations'],
            ],
            'currentUser' => $this->currentUser(),
            'invitations' => $result['items'],
            'pager'       => $result['pager'],
            'query'       => [
                'q'      => (string) ($this->request->getGet('q') ?? ''),
                'status' => (string) ($this->request->getGet('status') ?? ''),
            ],
        ]);
    }

    public function create(): RedirectResponse
    {
        $rules = [
            'label'      => 'permit_empty|max_length[120]',
            'max_uses'   => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[1000]',
            'expires_at' => 'permit_empty|max_length[32]',
            'quota_value' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'quota_unit' => 'permit_empty|in_list[mb,gb,tb]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $expiresAt = $this->normalizedExpiry((string) $this->request->getPost('expires_at'));
        if ($expiresAt === false) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Expiration must be a valid future date and time.');
        }

        $service = new InvitationService();
        $plainCode = $service->generatePlainCode();
        $label = trim((string) $this->request->getPost('label'));
        $quotaBytes = null;

        try {
            $quotaBytes = $this->quotaBytesFromPost();
            $service->create(
                $plainCode,
                $label === '' ? null : $label,
                (int) $this->request->getPost('max_uses'),
                $expiresAt,
                $this->currentUserId(),
                [
                    'invitation_type' => 'user',
                    'role_on_accept' => 'user',
                    'storage_quota_bytes' => $quotaBytes,
                ],
            );
        } catch (\Throwable $exception) {
            log_message('error', 'Invitation creation failed: {message}', ['message' => $exception->getMessage()]);
            (new ActivityLogger())->record($this->currentUserId(), 'admin.invitation.create', 'failure', [
                'type' => 'invitation',
            ], [
                'error' => $exception->getMessage(),
                'label' => $label,
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Invitation code could not be created. Please try again.');
        }

        (new ActivityLogger())->record($this->currentUserId(), 'admin.invitation.create', 'success', [
            'type' => 'invitation',
        ], [
            'label'    => $label,
            'max_uses' => (int) $this->request->getPost('max_uses'),
            'expires_at' => $expiresAt,
            'quota_bytes' => $quotaBytes,
        ]);

        return redirect()
            ->to('/admin/invitations')
            ->with('message', 'Invitation created. This code is shown once.')
            ->with('invitation_code', $plainCode);
    }

    public function update(int $id): RedirectResponse
    {
        $rules = [
            'label'      => 'permit_empty|max_length[120]',
            'max_uses'   => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[1000]',
            'expires_at' => 'permit_empty|max_length[32]',
            'quota_value' => 'permit_empty|numeric|greater_than_equal_to[0]',
            'quota_unit' => 'permit_empty|in_list[mb,gb,tb]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/admin/invitations')->withInput()->with('errors', $this->validator->getErrors());
        }

        $expiresAt = $this->normalizedExpiry((string) $this->request->getPost('expires_at'), allowPast: true);
        if ($expiresAt === false) {
            return redirect()
                ->to('/admin/invitations')
                ->withInput()
                ->with('error', 'Expiration must be a valid date and time.');
        }

        $label = trim((string) $this->request->getPost('label'));

        try {
            (new AdminService())->updateInvitation(
                $this->currentUserId() ?? 0,
                $id,
                $label === '' ? null : $label,
                (int) $this->request->getPost('max_uses'),
                $expiresAt,
                $this->quotaBytesFromPost(),
            );

            return redirect()->to('/admin/invitations')->with('message', 'Invitation updated.');
        } catch (\Throwable $exception) {
            (new ActivityLogger())->record($this->currentUserId(), 'admin.invitation.update', 'failure', [
                'type' => 'invitation',
                'id'   => $id,
            ], [
                'error' => $exception->getMessage(),
            ]);

            return redirect()->to('/admin/invitations')->with('error', $exception->getMessage());
        }
    }

    public function revoke(int $id): RedirectResponse
    {
        $service = new InvitationService();
        $service->expireStale();

        if (! $service->revoke($id, $this->currentUserId())) {
            (new ActivityLogger())->record($this->currentUserId(), 'admin.invitation.revoke', 'failure', [
                'type' => 'invitation',
                'id'   => $id,
            ], [
                'error' => 'Invitation could not be revoked.',
            ]);

            return redirect()
                ->to('/admin/invitations')
                ->with('error', 'Invitation could not be revoked. It may already be used, expired, or revoked.');
        }

        (new ActivityLogger())->record($this->currentUserId(), 'admin.invitation.revoke', 'success', [
            'type' => 'invitation',
            'id'   => $id,
        ]);

        return redirect()
            ->to('/admin/invitations')
            ->with('message', 'Invitation revoked.');
    }

    private function normalizedExpiry(string $expiresAt, bool $allowPast = false): string|false|null
    {
        $expiresAt = trim($expiresAt);
        if ($expiresAt === '') {
            return null;
        }

        $timestamp = strtotime(str_replace('T', ' ', $expiresAt));
        if ($timestamp === false || (! $allowPast && $timestamp <= Time::now()->getTimestamp())) {
            return false;
        }

        return date('Y-m-d H:i:s', $timestamp);
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
            throw new \DomainException('Invitation quota exceeds the allowed policy.');
        }

        return $bytes;
    }

    private function currentUserId(): ?int
    {
        $userId = session('user_id');

        return is_numeric($userId) ? (int) $userId : null;
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
