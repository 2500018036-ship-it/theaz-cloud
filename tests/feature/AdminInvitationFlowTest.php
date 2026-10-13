<?php

use App\Controllers\Admin\InvitationsController;
use App\Libraries\InvitationService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth;
use Config\Services;

/**
 * @internal
 */
final class AdminInvitationFlowTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    private function createAdminSession(): int
    {
        $userId = (new UserModel())->insert([
            'username'            => 'inviteadmin',
            'email'               => 'inviteadmin@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'admin',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true);

        $this->assertIsInt($userId);

        session()->set([
            'is_logged_in' => true,
            'user_id'      => $userId,
            'username'     => 'inviteadmin',
            'email'        => 'inviteadmin@example.test',
            'role'         => 'admin',
        ]);

        return $userId;
    }

    private function postInvitationsController(string $method, array $post, array $params = []): \CodeIgniter\Test\TestResponse
    {
        $request = service('incomingrequest', null, false);
        $request->setMethod('POST');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);
        Services::injectMock('validation', service('validation', null, false));

        return $this
            ->withRequest($request)
            ->withResponse(service('response', null, false))
            ->controller(InvitationsController::class)
            ->execute($method, ...$params);
    }

    public function testAdminCanCreateInvitationFromController(): void
    {
        $adminId = $this->createAdminSession();

        $response = $this->postInvitationsController('create', [
            'label'      => 'Product invite',
            'max_uses'   => '2',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+1 day')),
        ]);

        $response->assertRedirectTo('/admin/invitations');
        $response->assertSessionHas('invitation_code');
        $this->seeInDatabase('invitation_codes', [
            'label'      => 'Product invite',
            'status'     => 'active',
            'max_uses'   => 2,
            'use_count'  => 0,
            'created_by' => $adminId,
        ]);
    }

    public function testAdminCanRevokeActiveInvitation(): void
    {
        $adminId = $this->createAdminSession();
        $service = new InvitationService(db: $this->db);
        $code = $service->generatePlainCode();
        $invitationId = $service->create($code, 'Revokable invite', 1, null, $adminId);

        $response = $this->postInvitationsController('revoke', [], [$invitationId]);

        $response->assertRedirectTo('/admin/invitations');
        $this->seeInDatabase('invitation_codes', [
            'id'         => $invitationId,
            'status'     => 'revoked',
            'revoked_by' => $adminId,
        ]);
        $this->assertFalse($service->consume($code));
    }

    public function testExpiredInvitationsAreMarkedBeforeListing(): void
    {
        $this->createAdminSession();
        $service = new InvitationService(db: $this->db);
        $code = $service->generatePlainCode();
        $invitationId = $service->create($code, 'Expired invite', 1, '2020-01-01 00:00:00');

        $this
            ->withResponse(service('response', null, false))
            ->controller(InvitationsController::class)
            ->execute('index');

        $this->seeInDatabase('invitation_codes', [
            'id'     => $invitationId,
            'status' => 'expired',
        ]);
    }
}
