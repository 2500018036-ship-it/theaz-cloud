<?php

use App\Filters\AdminFilter;
use App\Libraries\ActivityLogger;
use App\Libraries\AdminAuthorization;
use App\Libraries\AdminService;
use App\Libraries\DriveService;
use App\Libraries\InvitationService;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth;
use Config\CloudDrive;

/**
 * @internal
 */
final class AdminControlCenterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = WRITEPATH . 'test-admin-control-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        session()->destroy();
        $this->removeDirectory($this->storagePath);
        $this->removeDirectory(WRITEPATH . 'test-admin-control-sources');
        parent::tearDown();
    }

    public function testRegularUserCannotPassAdminFilter(): void
    {
        session()->set([
            'is_logged_in' => true,
            'user_id'      => 100,
            'role'         => 'user',
        ]);

        $result = (new AdminFilter())->before(service('request'));

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(302, $result->getStatusCode());
    }

    public function testUserListPaginationAndFilters(): void
    {
        $adminId = $this->createUser('adminpager', 'super_admin', 'active');
        $this->createUser('alphauser', 'user', 'active');
        $this->createUser('betauser', 'user', 'inactive');
        $this->createUser('gammauser', 'user', 'active');

        $service = $this->adminService();
        $pageOne = $service->listUsers(['page' => 1, 'perPage' => 2]);
        $filtered = $service->listUsers(['role' => 'user', 'status' => 'inactive', 'q' => 'beta']);

        $this->assertSame(4, $pageOne['pager']['total']);
        $this->assertCount(2, $pageOne['items']);
        $this->assertSame(['betauser'], array_column($filtered['items'], 'username'));
        $this->assertGreaterThan(0, $adminId);
    }

    public function testRoleStatusQuotaUpdatesAreValidatedAndLogged(): void
    {
        $adminId = $this->createUser('controlsuper', 'super_admin', 'active');
        $userId = $this->createUser('controluser', 'user', 'active', 1024);
        $service = $this->adminService();

        $service->updateUserQuota($adminId, $userId, 2048);
        try { $service->updateUserRole($adminId, $userId, 'super_admin'); $this->fail('Role escalation must fail.'); } catch (DomainException) { $this->assertTrue(true); }
        $service->updateUserStatus($adminId, $userId, 'inactive');

        $this->seeInDatabase('users', [
            'id' => $userId,
            'role' => 'user',
            'status' => 'inactive',
            'storage_quota_bytes' => 2048,
        ]);

        $this->seeInDatabase('activity_logs', [
            'actor_id' => $adminId,
            'action'   => 'admin.user.status',
            'status'   => 'success',
        ]);
        $this->seeInDatabase('activity_logs', [
            'actor_id' => $adminId,
            'action'   => 'admin.user.quota',
            'status'   => 'success',
        ]);
    }

    public function testAdministratorQuotaCannotBeChanged(): void
    {
        $superAdminId = $this->createUser('quotasuper', 'super_admin', 'active');
        $adminId = $superAdminId;

        try {
            $this->adminService()->updateUserQuota($superAdminId, $adminId, 2048);
            $this->fail('Administrator storage quota changes should be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Administrator accounts', $exception->getMessage());
        }
    }

    public function testRegularAdminCannotChangeRolesOrSuperAdminSettings(): void
    {
        $adminId = $this->createUser('regularuseractor', 'user', 'active');
        $userId = $this->createUser('regularuser', 'user', 'active');
        $authorization = new AdminAuthorization($this->db);

        $this->assertFalse($authorization->hasPermission($adminId, 'users.manage'));
        $this->assertFalse($authorization->hasPermission($adminId, 'system_settings.view'));
        $this->assertFalse($authorization->hasPermission($adminId, 'system_settings.manage'));
        $this->assertFalse($authorization->hasPermission($adminId, 'admins.manage'));
        $this->assertFalse($authorization->hasPermission($adminId, 'activity_logs.manage'));
        $this->assertSame('system_settings.manage', $authorization->permissionForAdminPath('/storage-cloud/public/admin/settings', 'POST'));
        $this->assertSame('activity_logs.manage', $authorization->permissionForAdminPath('/storage-cloud/public/admin/audit/1/delete', 'POST'));
        $this->assertSame('activity_logs.manage', $authorization->permissionForAdminPath('/storage-cloud/public/admin/audit/clear', 'POST'));

        try {
            $this->adminService()->updateUserRole($adminId, $userId, 'admin');
            $this->fail('Regular admin should not be allowed to change roles.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->seeInDatabase('users', [
            'id' => $userId,
            'role' => 'user',
        ]);
    }

    public function testLastActiveAdminCannotBeLockedOut(): void
    {
        $adminId = $this->createUser('lastadmin', 'super_admin', 'active');
        $service = $this->adminService();

        try {
            $service->updateUserStatus($adminId, $adminId, 'inactive');
            $this->fail('Self deactivation should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        try {
            $service->updateUserRole($adminId, $adminId, 'user');
            $this->fail('Self demotion should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->seeInDatabase('users', [
            'id' => $adminId,
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    public function testInvitationUpdateAndPaginationDoNotExposePlaintextCodes(): void
    {
        $adminId = $this->createUser('invitecontrol', 'super_admin', 'active');
        $service = new InvitationService(db: $this->db);
        $codeOne = $service->generatePlainCode();
        $codeTwo = $service->generatePlainCode();
        $idOne = $service->create($codeOne, 'Ops invite', 3, null, $adminId);
        $service->create($codeTwo, 'Design invite', 1, null, $adminId);

        $admin = $this->adminService();
        $page = $admin->listInvitations(['page' => 1, 'perPage' => 1]);
        $updated = $admin->updateInvitation($adminId, $idOne, 'Ops invite updated', 5, date('Y-m-d H:i:s', strtotime('+2 days')));

        $this->assertSame(2, $page['pager']['total']);
        $this->assertArrayNotHasKey('plain_code', $page['items'][0]);
        $this->assertSame('Ops invite updated', $updated['label']);
        $this->seeInDatabase('activity_logs', [
            'actor_id' => $adminId,
            'action'   => 'admin.invitation.update',
            'status'   => 'success',
        ]);
    }

    public function testStorageMetricsUseDatabaseUsageAndDiskCapacity(): void
    {
        $userId = $this->createUser('storageadminmetric', 'user', 'active', 4096);
        $drive = new DriveService($this->db, null, null, $this->driveConfig(), new ActivityLogger($this->db, $this->driveConfig()));
        $drive->importLocalFile($userId, null, $this->tempFile('metric.txt', 'storage'), 'metric.txt', 'text/plain');

        $metrics = $this->adminService()->storageMetrics();
        $driveMetrics = $this->adminService()->driveMetrics();
        $dashboard = $this->adminService()->dashboard();

        $this->assertSame(strlen('storage'), $metrics['used_bytes']);
        $this->assertGreaterThanOrEqual($metrics['used_bytes'], $metrics['quota_bytes']);
        $this->assertIsBool($metrics['storage_writable']);
        $this->assertSame(1, $driveMetrics['files']);
        $this->assertArrayHasKey('quota_users', $dashboard);
        $this->assertContains('storageadminmetric', array_column($dashboard['quota_users'], 'username'));
    }

    private function createUser(string $username, string $role, string $status, int $quota = 1073741824): int
    {
        $id = (new UserModel())->insert([
            'username'            => $username,
            'email'               => $username . '@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => $role,
            'status'              => $status,
            'storage_quota_bytes' => $quota,
            'storage_used_bytes'  => 0,
        ], true);

        $this->assertIsInt($id);

        (new \App\Models\UserProfileModel())->insert(['user_id' => $id, 'email_verified_at' => date('Y-m-d H:i:s')]);
        return $id;
    }

    private function adminService(): AdminService
    {
        $config = $this->driveConfig();

        return new AdminService($this->db, $config, new ActivityLogger($this->db, $config));
    }

    private function driveConfig(): CloudDrive
    {
        $config = new CloudDrive();
        $config->storagePath = $this->storagePath;
        $config->allowedExtensions = ['txt'];
        $config->allowedMimeTypes = ['text/plain', 'application/octet-stream'];

        return $config;
    }

    private function tempFile(string $name, string $contents): string
    {
        $directory = WRITEPATH . 'test-admin-control-sources';
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . uniqid('', true) . '-' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            $fileInfo->isDir() ? rmdir($fileInfo->getPathname()) : unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}
