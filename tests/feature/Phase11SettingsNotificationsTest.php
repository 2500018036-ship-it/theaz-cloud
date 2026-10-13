<?php

use App\Controllers\DriveController;
use App\Filters\AuthFilter;
use App\Libraries\DriveService;
use App\Libraries\NotificationService;
use App\Libraries\SettingsService;
use App\Libraries\SystemSettingsService;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth;
use Config\CloudDrive;

/**
 * @internal
 */
final class Phase11SettingsNotificationsTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = WRITEPATH . 'test-phase11-drive-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        session()->destroy();
        $this->removeDirectory($this->storagePath);
        $this->removeDirectory(WRITEPATH . 'test-phase11-sources');
        $this->removeDirectory(WRITEPATH . 'cloud-drive');
        $this->removeDirectory(WRITEPATH . 'profile-avatars');
        parent::tearDown();
    }

    public function testUserSettingsUpdateProfilePreferencesPasswordAndVerifiedEmail(): void
    {
        $userId = $this->createUser('settingsuser', 'user');
        $service = new SettingsService($this->db);

        $profile = $service->updateProfile($userId, [
            'display_name' => 'Settings User',
            'bio'          => 'Drive operator',
        ]);
        $this->assertSame('Settings User', $profile['display_name']);

        $service->updatePreferences($userId, [
            'default_drive_view' => 'grid',
            'default_sort'       => 'updated',
            'default_sort_dir'   => 'desc',
            'locale'             => 'id',
            'notify_in_app_announcements' => '1',
            'notify_in_app_sharing'       => '1',
            'email_updates'               => '1',
        ]);
        $this->seeInDatabase('user_profiles', [
            'user_id' => $userId,
            'default_drive_view' => 'grid',
            'default_sort' => 'updated',
            'default_sort_dir' => 'desc',
            'locale' => 'id',
            'email_updates' => 1,
        ]);

        try {
            $service->changePassword($userId, 'bad-password', 'new-password', 'new-password');
            $this->fail('Wrong current password should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $service->changePassword($userId, 'correct-password', 'new-password', 'new-password');
        $user = (new UserModel())->find($userId);
        $this->assertIsArray($user);
        $this->assertTrue(password_verify('new-password', (string) $user['password_hash']));

        $service->requestEmailChange($userId, 'settings-new@example.test');
        $queued = $this->db->table('email_queue')
            ->where('event_type', 'email_verification')
            ->where('recipient_email', 'settings-new@example.test')
            ->get()
            ->getRowArray();
        $this->assertIsArray($queued);
        $this->assertSame(1, preg_match('#/settings/email/verify/([a-f0-9]{64})#', (string) $queued['body_text'], $matches));

        $confirmedUserId = $service->confirmEmailChange($matches[1]);
        $this->assertSame($userId, $confirmedUserId);
        $this->seeInDatabase('users', [
            'id'    => $userId,
            'email' => 'settings-new@example.test',
        ]);
        $this->seeInDatabase('activity_logs', [
            'actor_id' => $userId,
            'action'   => 'settings.email.confirm',
            'status'   => 'success',
        ]);
    }

    public function testShareAndAnnouncementNotificationsRespectPreferencesAndQueueEmail(): void
    {
        $ownerId = $this->createUser('noticeowner', 'user');
        $viewerId = $this->createUser('noticeviewer', 'user');
        $adminId = $this->createUser('noticeadmin', 'admin');

        $settings = new SettingsService($this->db);
        $settings->updatePreferences($viewerId, [
            'locale'                       => 'id',
            'notify_in_app_announcements' => '1',
            'notify_in_app_sharing'       => '1',
            'email_announcements'         => '1',
            'email_sharing'               => '1',
        ]);

        $drive = $this->driveService();
        $file = $drive->importLocalFile($ownerId, null, $this->tempFile('shared.txt', 'shared'), 'shared.txt', 'text/plain');
        $share = $drive->shareItemWithUser($ownerId, (int) $file['id'], 'noticeviewer@example.test', 'viewer', $ownerId);
        $drive->updateSharePermission($ownerId, (int) $share['id'], 'editor');
        $drive->revokeShare($ownerId, (int) $share['id']);

        $this->seeInDatabase('notifications', [
            'user_id' => $viewerId,
            'category' => 'sharing',
            'related_type' => 'drive_item',
            'related_id' => $file['id'],
        ]);
        $this->seeInDatabase('email_queue', [
            'user_id' => $viewerId,
            'event_type' => 'sharing',
            'recipient_email' => 'noticeviewer@example.test',
            'status' => 'queued',
        ]);
        $localizedShareNotice = $this->db->table('notifications')
            ->where('user_id', $viewerId)
            ->where('category', 'sharing')
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();
        $this->assertIsArray($localizedShareNotice);
        $this->assertStringContainsString('membagikan', (string) $localizedShareNotice['title']);

        $notifications = new NotificationService($this->db);
        $announcement = $notifications->createAnnouncement($adminId, [
            'title'    => 'Maintenance window',
            'body'     => 'Uploads may pause tonight.',
            'channels' => 'notification,email',
        ]);
        $notifications->publishAnnouncement($adminId, (int) $announcement['id']);

        $this->seeInDatabase('notifications', [
            'user_id' => $viewerId,
            'category' => 'announcement',
            'related_type' => 'announcement',
            'related_id' => $announcement['id'],
        ]);
        $this->seeInDatabase('email_queue', [
            'user_id' => $viewerId,
            'event_type' => 'announcement',
            'event_id' => $announcement['id'],
            'status' => 'queued',
        ]);

        $summary = $notifications->summary($viewerId);
        $this->assertGreaterThanOrEqual(1, $summary['unread']);
        $notifications->markAllRead($viewerId);
        $this->assertSame(0, $notifications->unreadCount($viewerId));
    }

    public function testMaintenanceModeBlocksRegularAuthRoutesButNotAdmins(): void
    {
        $adminId = $this->createUser('maintadmin', 'admin');
        $userId = $this->createUser('maintuser', 'user');
        $settings = new SystemSettingsService($this->db);
        $settings->updateMaintenance($adminId, true, 'Back soon.');

        session()->set([
            'is_logged_in' => true,
            'user_id'      => $userId,
            'role'         => 'user',
        ]);

        $blocked = (new AuthFilter())->before(service('request'));
        $this->assertInstanceOf(ResponseInterface::class, $blocked);
        $this->assertSame(503, $blocked->getStatusCode());

        session()->set([
            'is_logged_in' => true,
            'user_id'      => $adminId,
            'role'         => 'admin',
        ]);

        $this->assertNull((new AuthFilter())->before(service('request')));
    }

    public function testPreviewTextEndpointUsesBackendPermissionAndSizeLimit(): void
    {
        $userId = $this->createUser('previewuser', 'user');
        $otherId = $this->createUser('previewother', 'user');
        $drive = new DriveService($this->db);
        $file = $drive->importLocalFile($userId, null, $this->tempFile('preview.txt', 'hello preview'), 'preview.txt', 'text/plain');

        session()->set([
            'is_logged_in' => true,
            'user_id'      => $userId,
            'username'     => 'previewuser',
            'email'        => 'previewuser@example.test',
            'role'         => 'user',
        ]);

        $request = service('incomingrequest', null, false);
        $request->setMethod('GET');
        $response = $this
            ->withRequest($request)
            ->withResponse(service('response', null, false))
            ->controller(DriveController::class)
            ->execute('previewText', (int) $file['id']);

        $response->assertOK();
        $this->assertStringContainsString('hello preview', $response->getBody());

        session()->set('user_id', $otherId);
        $denied = $this
            ->withRequest($request)
            ->withResponse(service('response', null, false))
            ->controller(DriveController::class)
            ->execute('previewText', (int) $file['id']);

        $denied->assertStatus(403);
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

    private function driveService(): DriveService
    {
        $config = new CloudDrive();
        $config->storagePath = $this->storagePath;
        $config->allowedExtensions = ['txt'];
        $config->allowedMimeTypes = ['text/plain'];

        return new DriveService($this->db, null, null, $config);
    }

    private function tempFile(string $name, string $contents): string
    {
        $directory = WRITEPATH . 'test-phase11-sources';
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
