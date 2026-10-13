<?php

use App\Controllers\AuthController;
use App\Filters\AdminFilter;
use App\Filters\AuthFilter;
use App\Filters\GuestFilter;
use App\Libraries\AuthService;
use App\Libraries\EmailVerificationService;
use App\Libraries\InvitationService;
use App\Libraries\SystemSettingsService;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth;
use Config\Services;

/**
 * @internal
 */
final class AuthFlowTest extends CIUnitTestCase
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

    public function testInvitationCanOnlyBeConsumedOnce(): void
    {
        $service = new InvitationService(db: $this->db);
        $code = $service->generatePlainCode();
        $service->create($code, 'One-time test invite');

        $this->assertTrue($service->consume($code));
        $this->assertFalse($service->consume($code));
        $this->seeInDatabase('invitation_codes', [
            'code_hash' => $service->hashCode($code),
            'status'    => 'consumed',
            'use_count' => 1,
        ]);
    }

    public function testExpiredInvitationCannotBeConsumed(): void
    {
        $service = new InvitationService(db: $this->db);
        $code = $service->generatePlainCode();
        $service->create($code, 'Expired test invite', 1, '2020-01-01 00:00:00');

        $this->assertFalse($service->consume($code));
    }

    public function testMultiUseInvitationAllowsExactlyItsConfiguredUses(): void
    {
        $service = new InvitationService(db: $this->db);
        $code = $service->generatePlainCode();
        $service->create($code, 'Two-use invite', 2);
        $this->assertTrue($service->consume($code));
        $this->assertTrue($service->consume($code));
        $this->assertFalse($service->consume($code));
    }

    public function testDeferredVerificationCreatesActiveInviteOnlyUserWithoutQueuedMail(): void
    {
        $config = config(Auth::class);
        $config->emailVerificationDeferred = true;
        try {
            $service = new InvitationService(db: $this->db);
            $code = $service->generatePlainCode();
            $service->create($code, 'Deferred verification');
            $this->postAuthController('register', [
                'invitation_code' => $code, 'username' => 'deferreduser',
                'email' => 'deferred@example.test', 'password' => 'test-password-long',
                'password_confirm' => 'test-password-long', 'role' => 'super_admin',
            ])->assertRedirectTo('/login');
            $this->seeInDatabase('users', ['username' => 'deferreduser', 'status' => 'active', 'role' => 'user']);
            $this->dontSeeInDatabase('email_queue', ['recipient_email' => 'deferred@example.test']);
        } finally {
            $config->emailVerificationDeferred = false;
        }
    }

    public function testAuthenticationDeniesAccessWhenAccountValidationCannotReachDatabase(): void
    {
        session()->set(['is_logged_in' => true, 'user_id' => 123]);
        $original = $this->db->getPrefix();
        $this->db->setPrefix('unavailable_');
        try {
            $result = (new AuthFilter())->before(service('request'));
            $this->assertInstanceOf(ResponseInterface::class, $result);
            $this->assertSame(503, $result->getStatusCode());
        } finally {
            $this->db->setPrefix($original);
        }
    }

    public function testUserRegistrationDataIsAlwaysUserRole(): void
    {
        $users = new UserModel();
        $id = $users->insert([
            'username'            => 'phaseuser',
            'email'               => 'phaseuser@example.test',
            'password_hash'       => password_hash('very-secure-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true);

        $this->assertIsInt($id);
        $this->seeInDatabase('users', [
            'username' => 'phaseuser',
            'role'     => 'user',
        ]);
    }

    public function testDuplicateUsernameAndEmailAreRejected(): void
    {
        $users = new UserModel();
        $this->assertIsInt($users->insert([
            'username'            => 'duplicate',
            'email'               => 'duplicate@example.test',
            'password_hash'       => password_hash('very-secure-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true));

        $this->assertFalse($users->insert([
            'username'            => 'duplicate',
            'email'               => 'another@example.test',
            'password_hash'       => password_hash('very-secure-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ]));

        $this->assertFalse($users->insert([
            'username'            => 'another',
            'email'               => 'duplicate@example.test',
            'password_hash'       => password_hash('very-secure-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ]));
    }

    public function testPasswordConfirmationRuleRejectsMismatch(): void
    {
        $validation = Services::validation();
        $validation->setRules([
            'password'         => 'required|min_length[10]',
            'password_confirm' => 'required|matches[password]',
        ]);

        $this->assertFalse($validation->run([
            'password'         => 'very-secure-password',
            'password_confirm' => 'different-password',
        ]));
    }

    public function testRegistrationControllerConsumesInvitationAndCreatesPendingUserWithVerificationEmail(): void
    {
        $invitations = new InvitationService(db: $this->db);
        $code = $invitations->generatePlainCode();
        $invitations->create($code, 'Controller registration invite');

        $response = $this->postAuthController('register', [
            'invitation_code'  => $code,
            'username'         => 'controlleruser',
            'email'            => 'controlleruser@example.test',
            'password'         => 'controller-password',
            'password_confirm' => 'controller-password',
            'role'             => 'admin',
        ]);

        $response->assertRedirectTo('/login');
        $this->seeInDatabase('users', [
            'username' => 'controlleruser',
            'email'    => 'controlleruser@example.test',
            'role'     => 'user',
            'status'   => EmailVerificationService::PENDING_STATUS,
        ]);
        $this->seeInDatabase('email_queue', [
            'recipient_email' => 'controlleruser@example.test',
            'event_type'      => 'email_verification',
            'status'          => 'queued',
        ]);
        $this->seeInDatabase('invitation_codes', [
            'code_hash' => $invitations->hashCode($code),
            'status'    => 'consumed',
            'use_count' => 1,
        ]);

        $user = (new UserModel())->where('email', 'controlleruser@example.test')->first();
        $this->assertIsArray($user);
        $this->assertNotSame('controller-password', $user['password_hash']);
        $this->assertTrue(password_verify('controller-password', $user['password_hash']));
    }

    public function testRegistrationWithEmailVerificationQueuesEmailAndBlocksLoginUntilConfirmed(): void
    {
        $adminId = (new UserModel())->insert([
            'username'            => 'verifyadmin',
            'email'               => 'verifyadmin@example.test',
            'password_hash'       => password_hash('very-secure-password', PASSWORD_DEFAULT),
            'role'                => 'super_admin',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true);
        $this->assertIsInt($adminId);
        (new SystemSettingsService($this->db))->updateGeneral($adminId, ['email_verification_required' => '1']);

        $invitations = new InvitationService(db: $this->db);
        $code = $invitations->generatePlainCode();
        $invitations->create($code, 'Controller verification invite');

        $response = $this->postAuthController('register', [
            'invitation_code'  => $code,
            'username'         => 'pendinguser',
            'email'            => 'pendinguser@example.test',
            'password'         => 'controller-password',
            'password_confirm' => 'controller-password',
        ]);

        $response->assertRedirectTo('/login');
        $this->seeInDatabase('users', [
            'username' => 'pendinguser',
            'email'    => 'pendinguser@example.test',
            'status'   => EmailVerificationService::PENDING_STATUS,
        ]);
        $this->seeInDatabase('email_queue', [
            'recipient_email' => 'pendinguser@example.test',
            'event_type'      => 'email_verification',
            'status'          => 'queued',
        ]);

        $auth = new AuthService();
        $blocked = $auth->attemptLogin('pendinguser@example.test', 'controller-password');
        $this->assertFalse($blocked['ok']);
        $this->assertSame('email_unverified', $blocked['reason']);

        $queued = $this->db->table('email_queue')
            ->where('recipient_email', 'pendinguser@example.test')
            ->get()
            ->getRowArray();
        $this->assertIsArray($queued);
        $this->assertStringContainsString('Verify Email', (string) $queued['body_text']);
        $this->assertSame(1, preg_match('/email\\/verify\\/([a-f0-9]{64})/', (string) $queued['body_text'], $matches));

        (new EmailVerificationService($this->db))->confirm($matches[1]);

        $this->seeInDatabase('users', [
            'username' => 'pendinguser',
            'status'   => 'active',
        ]);
        $this->assertTrue($auth->attemptLogin('pendinguser@example.test', 'controller-password')['ok']);
    }

    public function testRegistrationControllerRejectsInvalidInvitation(): void
    {
        $response = $this->postAuthController('register', [
            'invitation_code'  => 'INVALID-CODE-0000',
            'username'         => 'noinviteuser',
            'email'            => 'noinvite@example.test',
            'password'         => 'controller-password',
            'password_confirm' => 'controller-password',
        ]);

        $response->assertRedirect();
        $this->dontSeeInDatabase('users', [
            'email' => 'noinvite@example.test',
        ]);
    }

    public function testLoginAcceptsEmailOrUsernameAndRejectsWrongPassword(): void
    {
        $users = new UserModel();
        $this->assertIsInt($users->insert([
            'username'            => 'loginuser',
            'email'               => 'loginuser@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true));

        $auth = new AuthService($users);

        $this->assertTrue($auth->attemptLogin('loginuser', 'correct-password')['ok']);
        $this->assertTrue($auth->attemptLogin('loginuser@example.test', 'correct-password')['ok']);
        $this->assertFalse($auth->attemptLogin('loginuser', 'wrong-password')['ok']);
    }

    public function testInactiveAccountCannotLogin(): void
    {
        $users = new UserModel();
        $this->assertIsInt($users->insert([
            'username'            => 'inactiveuser',
            'email'               => 'inactive@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'suspended',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true));

        $result = (new AuthService($users))->attemptLogin('inactiveuser', 'correct-password');

        $this->assertFalse($result['ok']);
        $this->assertSame('inactive_account', $result['reason']);
    }

    public function testLoginControllerRedirectsByDatabaseRoleAndRegeneratesSession(): void
    {
        $users = new UserModel();
        $this->assertIsInt($users->insert([
            'username'            => 'adminlogin',
            'email'               => 'adminlogin@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'admin',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true));

        $response = $this->postAuthController('login', [
            'identifier' => 'adminlogin@example.test',
            'password'   => 'correct-password',
        ]);

        $response->assertRedirectTo('/admin/dashboard');
        $response->assertSessionHas('is_logged_in', true);
        $response->assertSessionHas('role', 'admin');
        $this->assertTrue(session()->didRegenerate);
    }

    public function testLoginRateLimiterBlocksRepeatedFailures(): void
    {
        $users = new UserModel();
        $this->assertIsInt($users->insert([
            'username'            => 'limiteduser',
            'email'               => 'limited@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true));

        for ($attempt = 0; $attempt < config(Auth::class)->loginMaxAttempts; $attempt++) {
            $this->postAuthController('login', [
                'identifier' => 'limiteduser',
                'password'   => 'wrong-password',
            ])->assertRedirect();
        }

        $this->postAuthController('login', [
            'identifier' => 'limiteduser',
            'password'   => 'correct-password',
        ])->assertRedirect();

        $this->assertFalse((bool) session()->get('is_logged_in'));
    }

    public function testAdminFilterRejectsUserRole(): void
    {
        session()->set([
            'is_logged_in' => true,
            'user_id'      => 10,
            'role'         => 'user',
        ]);

        $result = (new AdminFilter())->before(service('request'));

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testAuthFilterRejectsAdminAccessToPersonalDrive(): void
    {
        $adminId = (new UserModel())->insert([
            'username'            => 'driveblockedadmin',
            'email'               => 'driveblockedadmin@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'admin',
            'status'              => 'active',
            'storage_quota_bytes' => 0,
            'storage_used_bytes'  => 0,
        ], true);
        $this->assertIsInt($adminId);

        session()->set([
            'is_logged_in' => true,
            'user_id'      => $adminId,
            'role'         => 'admin',
        ]);

        $request = service('incomingrequest', null, false);
        $uriProperty = new ReflectionProperty($request, 'uri');
        $uriProperty->setAccessible(true);
        $uriProperty->setValue($request, new \CodeIgniter\HTTP\URI('http://example.test/drive'));
        $result = (new AuthFilter())->before($request);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testAdminFilterRedirectsGuest(): void
    {
        $result = (new AdminFilter())->before(service('request'));

        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function testGuestFilterRedirectsSuperAdminToAdminDashboard(): void
    {
        session()->set([
            'is_logged_in' => true,
            'user_id'      => 20,
            'role'         => 'super_admin',
        ]);

        $result = (new GuestFilter())->before(service('request'));

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('/admin/dashboard', $result->getHeaderLine('Location'));
    }

    public function testAuthFilterExpiresSessionUsingDynamicPolicy(): void
    {
        $adminId = (new UserModel())->insert([
            'username'            => 'sessionadmin',
            'email'               => 'sessionadmin@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'super_admin',
            'status'              => 'active',
            'storage_quota_bytes' => config(Auth::class)->defaultQuotaBytes,
            'storage_used_bytes'  => 0,
        ], true);
        $this->assertIsInt($adminId);
        (new SystemSettingsService($this->db))->updateGeneral($adminId, ['session_lifetime_minutes' => '15']);

        session()->set([
            'is_logged_in' => true,
            'user_id'      => $adminId,
            'role'         => 'user',
            'auth_started_at' => time() - (16 * 60),
        ]);

        $result = (new \App\Filters\AuthFilter())->before(service('request'));

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertFalse((bool) session()->get('is_logged_in'));
    }

    public function testLogoutClearsSession(): void
    {
        session()->set([
            'is_logged_in' => true,
            'user_id'      => 10,
            'role'         => 'user',
        ]);

        (new AuthService())->logout();

        $this->assertFalse((bool) session()->get('is_logged_in'));
    }
}
