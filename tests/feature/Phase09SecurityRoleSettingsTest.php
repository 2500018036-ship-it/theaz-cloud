<?php

use App\Controllers\AuthController;
use App\Libraries\AdminAuthorization;
use App\Libraries\AdminManagementService;
use App\Libraries\EmailVerificationService;
use App\Libraries\InvitationService;
use App\Libraries\SystemSettingsService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth;
use Config\Services;

/**
 * @internal
 */
final class Phase09SecurityRoleSettingsTest extends CIUnitTestCase
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

    public function testSuperAdminCreatesAdminAccountDirectlyWithPermissions(): void
    {
        $superAdminId = $this->createUser('rootsuper', 'super_admin');
        $service = new AdminManagementService($this->db);

        $admin = $service->createAdminAccount($superAdminId, [
            'email' => 'newadmin@example.test',
            'username' => 'newadmin',
            'password' => 'admin-password-123',
            'password_confirm' => 'admin-password-123',
            'permissions' => ['admin.dashboard', 'users.view', 'storage.view'],
            'quota_value' => '999',
            'quota_unit' => 'tb',
        ]);

        $this->seeInDatabase('users', [
            'id'                  => (int) $admin['id'],
            'email'               => 'newadmin@example.test',
            'role'                => 'admin',
            'storage_quota_bytes' => 0,
            'status'              => EmailVerificationService::PENDING_STATUS,
        ]);
        $this->seeInDatabase('email_queue', [
            'user_id'         => (int) $admin['id'],
            'recipient_email' => 'newadmin@example.test',
            'event_type'      => 'admin_email_verification',
            'status'          => 'queued',
        ]);
        $this->seeInDatabase('admin_permissions', [
            'user_id' => (int) $admin['id'],
            'permission' => 'storage.view',
            'revoked_at' => null,
        ]);
        $user = (new UserModel())->find((int) $admin['id']);
        $this->assertIsArray($user);
        $this->assertTrue(password_verify('admin-password-123', (string) $user['password_hash']));
    }

    public function testRegularAdminCannotCreateAdminAccount(): void
    {
        $adminId = $this->createUser('plainadmin', 'admin');

        try {
            (new AdminManagementService($this->db))->createAdminAccount($adminId, [
                'email' => 'blocked@example.test',
                'username' => 'blockedadmin',
                'password' => 'admin-password-123',
                'password_confirm' => 'admin-password-123',
                'permissions' => ['admin.dashboard'],
            ]);
            $this->fail('Regular admins should not create admin accounts.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->dontSeeInDatabase('users', [
            'email' => 'blocked@example.test',
        ]);
    }

    public function testAdminInvitationActivationFlowIsDisabled(): void
    {
        $superAdminId = $this->createUser('disabledinvitesuper', 'super_admin');
        $service = new AdminManagementService($this->db);

        try {
            $service->createAdminInvitation($superAdminId, 'disabled@example.test', date('Y-m-d H:i:s', strtotime('+1 day')), 4096);
            $this->fail('Admin invitations should be disabled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }

        try {
            $service->activateAdminFromInvitation('not-a-real-token', 'adminx', 'admin-password-123', 'admin-password-123');
            $this->fail('Admin invitation activation should be disabled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }
    }

    public function testDirectAdminCreationRequiresEmailVerificationWhenPolicyIsEnabled(): void
    {
        $superAdminId = $this->createUser('verifyinvitesuper', 'super_admin');
        (new SystemSettingsService($this->db))->updateGeneral($superAdminId, ['email_verification_required' => '1']);

        $service = new AdminManagementService($this->db);
        $admin = $service->createAdminAccount($superAdminId, [
            'email' => 'verifyadmin@example.test',
            'username' => 'verifyadmin',
            'password' => 'admin-password-123',
            'password_confirm' => 'admin-password-123',
            'permissions' => ['admin.dashboard'],
        ]);

        $this->seeInDatabase('users', [
            'id'     => (int) $admin['id'],
            'email'  => 'verifyadmin@example.test',
            'role'   => 'admin',
            'status' => EmailVerificationService::PENDING_STATUS,
        ]);
        $this->seeInDatabase('email_queue', [
            'user_id'         => (int) $admin['id'],
            'recipient_email' => 'verifyadmin@example.test',
            'event_type'      => 'admin_email_verification',
            'status'          => 'queued',
        ]);
    }

    public function testUserInvitationQuotaIsAppliedDuringRegistration(): void
    {
        $invitationService = new InvitationService(db: $this->db);
        $code = $invitationService->generatePlainCode();
        $invitationId = $invitationService->create($code, 'Quota invite', 1, null, null, [
            'storage_quota_bytes' => 12_345,
        ]);

        $this->postAuthController('register', [
            'invitation_code'  => $code,
            'username'         => 'quotauser',
            'email'            => 'quotauser@example.test',
            'password'         => 'quota-password-123',
            'password_confirm' => 'quota-password-123',
        ])->assertRedirectTo('/login');

        $user = (new UserModel())->where('email', 'quotauser@example.test')->first();
        $this->assertIsArray($user);
        $this->assertSame(12_345, (int) $user['storage_quota_bytes']);
        $this->seeInDatabase('invitation_codes', [
            'id'               => $invitationId,
            'status'           => 'consumed',
            'accepted_user_id' => (int) $user['id'],
        ]);
    }

    public function testDynamicSettingsPersistTypeAndCategoryMetadata(): void
    {
        $superAdminId = $this->createUser('settingssuper', 'super_admin');
        $settings = new SystemSettingsService($this->db);

        $settings->updateGeneral($superAdminId, [
            'app_name' => 'Secure Drive',
            'password_min_length' => '14',
            'max_upload_size_bytes' => '2048',
            'storage_warning_threshold_percent' => '77',
        ]);

        $this->seeInDatabase('system_settings', [
            'setting_key' => 'password_min_length',
            'value_text'  => '14',
            'value_type'  => 'integer',
            'category'    => 'security',
        ]);
        $this->seeInDatabase('system_settings', [
            'setting_key' => 'max_upload_size_bytes',
            'value_type'  => 'integer',
            'category'    => 'storage',
        ]);

        $authorization = new AdminAuthorization($this->db);
        $this->assertTrue($authorization->hasPermission($superAdminId, 'system_settings.manage'));
    }

    private function postAuthController(string $method, array $post): \CodeIgniter\Test\TestResponse
    {
        $request = service('incomingrequest', null, false);
        $request->setMethod('POST');
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $post);
        Services::injectMock('validation', service('validation', null, false));

        return $this
            ->withRequest($request)
            ->withResponse(service('response', null, false))
            ->controller(AuthController::class)
            ->execute($method);
    }

    private function createUser(string $username, string $role): int
    {
        $id = (new UserModel())->insert([
            'username'            => $username,
            'email'               => $username . '@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => $role,
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true);

        $this->assertIsInt($id);

        return $id;
    }
}
