<?php

use App\Libraries\DriveService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CloudDrive;

/**
 * @internal
 */
final class DriveSharingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = WRITEPATH . 'test-drive-sharing-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        $this->removeDirectory(WRITEPATH . 'test-drive-sharing-sources');
        parent::tearDown();
    }

    public function testFolderShareInheritanceAndPermissionLifecycle(): void
    {
        $ownerId = $this->createUser('shareowner', 4096);
        $viewerId = $this->createUser('shareviewer', 4096);
        $outsiderId = $this->createUser('shareoutsider', 4096);
        $service = $this->service();

        $folder = $service->createFolder($ownerId, null, 'Projects');
        $nested = $service->createFolder($ownerId, (int) $folder['id'], 'Specs');
        $file = $service->importLocalFile($ownerId, (int) $nested['id'], $this->tempFile('brief.txt', 'brief'), 'brief.txt', 'text/plain');

        $share = $service->shareItemWithUser($ownerId, (int) $folder['id'], 'shareviewer@example.test', 'viewer', $ownerId);

        $this->assertSame('Projects', $service->listSharedWithMe($viewerId)['items'][0]['name']);
        $this->assertSame('Projects', $service->listSharedByMe($ownerId)['items'][0]['name']);
        $this->assertFileExists($service->downloadableFile($viewerId, (int) $file['id'])['path']);

        try {
            $service->renameItem($viewerId, (int) $file['id'], 'viewer-edit.txt');
            $this->fail('Viewer should not rename inherited shared content.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $service->updateSharePermission($ownerId, (int) $share['id'], 'editor');
        $renamed = $service->renameItem($viewerId, (int) $file['id'], 'editor-edit.txt');
        $this->assertSame('editor-edit.txt', $renamed['name']);

        $service->revokeShare($ownerId, (int) $share['id']);

        try {
            $service->downloadableFile($viewerId, (int) $file['id']);
            $this->fail('Revoked access should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        try {
            $service->downloadableFile($outsiderId, (int) $file['id']);
            $this->fail('Unshared users should not access the item.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    public function testEditorCanCreateInsideSharedFolderAndOwnerQuotaIsUsed(): void
    {
        $ownerId = $this->createUser('editorowner', 4096);
        $editorId = $this->createUser('editorshare', 4096);
        $service = $this->service();
        $folder = $service->createFolder($ownerId, null, 'Workspace');

        $service->shareItemWithUser($ownerId, (int) $folder['id'], 'editorshare@example.test', 'editor', $ownerId);

        $child = $service->createFolder($editorId, (int) $folder['id'], 'Drafts');
        $uploaded = $service->importLocalFile($editorId, (int) $child['id'], $this->tempFile('note.txt', 'note'), 'note.txt', 'text/plain');

        $this->assertSame($ownerId, (int) $child['user_id']);
        $this->assertSame($ownerId, (int) $uploaded['user_id']);
        $this->seeInDatabase('users', [
            'id' => $ownerId,
            'storage_used_bytes' => 4,
        ]);
    }

    public function testSharedFileRemovedFromDriveAndExpiredPublicLinkAreRejected(): void
    {
        $ownerId = $this->createUser('removedowner', 4096);
        $viewerId = $this->createUser('removedviewer', 4096);
        $service = $this->service();
        $file = $service->importLocalFile($ownerId, null, $this->tempFile('removed.txt', 'removed'), 'removed.txt', 'text/plain');

        $service->shareItemWithUser($ownerId, (int) $file['id'], 'removedviewer@example.test', 'viewer', $ownerId);
        $this->assertFileExists($service->downloadableFile($viewerId, (int) $file['id'])['path']);

        $public = $service->createShareLink($ownerId, (int) $file['id'], 1);
        $service->revokePublicShareLink($ownerId, (int) $file['id']);

        try {
            $service->downloadSharedFile($public['token']);
            $this->fail('Revoked public link should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $public = $service->createShareLink($ownerId, (int) $file['id'], 1);
        $this->db->table('drive_shares')
            ->where('token', $public['token'])
            ->update(['expires_at' => '2000-01-01 00:00:00']);

        try {
            $service->downloadSharedFile($public['token']);
            $this->fail('Expired public link should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $service->trashItems($ownerId, [(int) $file['id']], $ownerId);

        try {
            $service->downloadableFile($viewerId, (int) $file['id']);
            $this->fail('Trashed shared files should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    private function createUser(string $username, int $quota): int
    {
        $id = (new UserModel())->insert([
            'username'            => $username,
            'email'               => $username . '@example.test',
            'password_hash'       => password_hash('correct-password', PASSWORD_DEFAULT),
            'role'                => 'user',
            'status'              => 'active',
            'storage_quota_bytes' => $quota,
            'storage_used_bytes'  => 0,
        ], true);

        $this->assertIsInt($id);

        return $id;
    }

    private function service(): DriveService
    {
        $config = new CloudDrive();
        $config->storagePath = $this->storagePath;
        $config->allowedExtensions = ['txt', 'md'];
        $config->allowedMimeTypes = ['text/plain'];

        return new DriveService($this->db, null, null, $config);
    }

    private function tempFile(string $name, string $contents): string
    {
        $directory = WRITEPATH . 'test-drive-sharing-sources';
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
