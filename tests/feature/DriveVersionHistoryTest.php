<?php

use App\Libraries\ActivityLogger;
use App\Libraries\DriveService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CloudDrive;

/**
 * @internal
 */
final class DriveVersionHistoryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = WRITEPATH . 'test-drive-versions-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        $this->removeDirectory(WRITEPATH . 'test-drive-version-sources');
        parent::tearDown();
    }

    public function testNewVersionArchivesCurrentContentAndRestoreSwapsWithoutLosingDiskConsistency(): void
    {
        $ownerId = $this->createUser('versionowner', 100);
        $service = $this->service();
        $file = $service->importLocalFile($ownerId, null, $this->tempFile('doc.txt', 'one'), 'doc.txt', 'text/plain');
        $originalPath = $service->downloadableFile($ownerId, (int) $file['id'])['path'];

        $updated = $service->importLocalVersion($ownerId, (int) $file['id'], $this->tempFile('doc.txt', 'twotwo'), 'doc.txt', 'text/plain');
        $updatedPath = $service->downloadableFile($ownerId, (int) $file['id'])['path'];

        $this->assertNotSame($originalPath, $updatedPath);
        $this->assertSame('twotwo', file_get_contents($updatedPath));
        $this->assertFileExists($originalPath);
        $this->seeInDatabase('drive_file_versions', [
            'item_id' => $file['id'],
            'size_bytes' => 3,
        ]);
        $this->seeInDatabase('users', [
            'id' => $ownerId,
            'storage_used_bytes' => 9,
        ]);

        $versions = $service->listFileVersions($ownerId, (int) $updated['id']);
        $service->restoreFileVersion($ownerId, (int) $file['id'], (int) $versions['versions'][0]['id']);
        $restoredPath = $service->downloadableFile($ownerId, (int) $file['id'])['path'];

        $this->assertSame('one', file_get_contents($restoredPath));
        $this->seeInDatabase('drive_file_versions', [
            'item_id' => $file['id'],
            'size_bytes' => 6,
        ]);
        $this->seeInDatabase('users', [
            'id' => $ownerId,
            'storage_used_bytes' => 9,
        ]);
    }

    public function testVersionRetentionPrunesOldRowsFilesAndQuota(): void
    {
        $ownerId = $this->createUser('retentionowner', 100);
        $config = $this->config();
        $config->maxVersionsPerFile = 2;
        $service = $this->service($config);
        $file = $service->importLocalFile($ownerId, null, $this->tempFile('doc.txt', 'a'), 'doc.txt', 'text/plain');
        $oldestPath = $service->downloadableFile($ownerId, (int) $file['id'])['path'];

        $service->importLocalVersion($ownerId, (int) $file['id'], $this->tempFile('doc.txt', 'bb'), 'doc.txt', 'text/plain');
        $service->importLocalVersion($ownerId, (int) $file['id'], $this->tempFile('doc.txt', 'ccc'), 'doc.txt', 'text/plain');
        $service->importLocalVersion($ownerId, (int) $file['id'], $this->tempFile('doc.txt', 'dddd'), 'doc.txt', 'text/plain');

        $versions = $service->listFileVersions($ownerId, (int) $file['id']);

        $this->assertSame(2, $versions['pager']['total']);
        $this->assertFileDoesNotExist($oldestPath);
        $this->seeInDatabase('users', [
            'id' => $ownerId,
            'storage_used_bytes' => 9,
        ]);
    }

    public function testVersionPermissionsFollowSharedAccess(): void
    {
        $ownerId = $this->createUser('versionaccessowner', 100);
        $viewerId = $this->createUser('versionviewer', 100);
        $service = $this->service();
        $file = $service->importLocalFile($ownerId, null, $this->tempFile('shared.txt', 'one'), 'shared.txt', 'text/plain');
        $service->importLocalVersion($ownerId, (int) $file['id'], $this->tempFile('shared.txt', 'two'), 'shared.txt', 'text/plain');
        $service->shareItemWithUser($ownerId, (int) $file['id'], 'versionviewer@example.test', 'viewer', $ownerId);

        $versions = $service->listFileVersions($viewerId, (int) $file['id']);
        $this->assertSame(1, $versions['pager']['total']);
        $this->assertFalse((bool) $versions['item']['can_manage_versions']);

        try {
            $service->restoreFileVersion($viewerId, (int) $file['id'], (int) $versions['versions'][0]['id']);
            $this->fail('Viewer should not restore a file version.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->seeInDatabase('activity_logs', [
            'actor_id' => $viewerId,
            'action'   => 'drive.version.restore',
            'status'   => 'failure',
        ]);
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

    private function service(?CloudDrive $config = null): DriveService
    {
        $config ??= $this->config();

        return new DriveService($this->db, null, null, $config, new ActivityLogger($this->db, $config));
    }

    private function config(): CloudDrive
    {
        $config = new CloudDrive();
        $config->storagePath = $this->storagePath;
        $config->allowedExtensions = ['txt', 'md'];
        $config->allowedMimeTypes = ['text/plain', 'application/octet-stream'];
        $config->versionRetentionDays = 0;

        return $config;
    }

    private function tempFile(string $name, string $contents): string
    {
        $directory = WRITEPATH . 'test-drive-version-sources';
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
