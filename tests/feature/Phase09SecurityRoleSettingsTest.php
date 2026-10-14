<?php

use App\Controllers\AuthController;
use App\Libraries\AdminAuthorization;
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

    public function testUploadLimitAllowsSeventyGbAndCapsLargerSettings(): void
    {
        $superAdminId = $this->createUser('uploadlimitsuper', 'super_admin');
        $settings = new SystemSettingsService($this->db);

        $settings->updateGeneral($superAdminId, ['max_upload_size_bytes' => '75161927680']);
        $this->assertSame(75_161_927_680, $settings->getInt('max_upload_size_bytes'));
        $this->seeInDatabase('system_settings', [
            'setting_key' => 'max_upload_size_bytes',
            'value_text' => '75161927680',
        ]);

        $settings->updateGeneral($superAdminId, ['max_upload_size_bytes' => '75161927681']);
        $this->assertSame(75_161_927_680, $settings->getInt('max_upload_size_bytes'));
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
