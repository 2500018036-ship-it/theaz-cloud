<?php

use App\Libraries\ChunkedUploadService;
use App\Libraries\DriveService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Auth;
use Config\CloudDrive;

/**
 * @internal
 */
final class DriveCoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = WRITEPATH . 'test-drive-core-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        $this->removeDirectory(WRITEPATH . 'test-drive-sources');
        parent::tearDown();
    }

    public function testSuccessfulUploadStoresMetadataAndPrivateFile(): void
    {
        $userId = $this->createUser('driveuser', 1024);
        $service = $this->service();
        $source = $this->tempFile('hello.txt', 'hello cloud');

        $item = $service->importLocalFile($userId, null, $source, 'hello.txt', 'text/plain');
        $download = $service->downloadableFile($userId, (int) $item['id']);

        $this->assertSame('hello.txt', $item['name']);
        $this->assertSame('file', $item['type']);
        $this->assertFileExists($download['path']);
        $this->assertStringStartsWith(realpath($this->storagePath), realpath($download['path']));
        $this->assertStringNotContainsString(realpath(PUBLICPATH), realpath($download['path']));
        $this->seeInDatabase('users', [
            'id' => $userId,
            'storage_used_bytes' => strlen('hello cloud'),
        ]);
    }

    public function testInvalidExtensionUploadFailsWithoutMetadata(): void
    {
        $userId = $this->createUser('invalidext', 1024);
        $source = $this->tempFile('shell.php', '<?php echo "no";');

        try {
            $this->service()->importLocalFile($userId, null, $source, 'shell.php', 'text/plain');
            $this->fail('Invalid extension should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->dontSeeInDatabase('drive_items', ['user_id' => $userId]);
    }

    public function testLargeFileUploadFailsBeforeDiskWrite(): void
    {
        $userId = $this->createUser('largefile', 1024);
        $config = $this->config();
        $config->maxUploadSizeBytes = 5;
        $source = $this->tempFile('large.txt', '1234567890');

        try {
            $this->service($config)->importLocalFile($userId, null, $source, 'large.txt', 'text/plain');
            $this->fail('Oversized file should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->dontSeeInDatabase('drive_items', ['user_id' => $userId]);
    }

    public function testQuotaFullUploadFailsAndLeavesUsageUnchanged(): void
    {
        $userId = $this->createUser('quotafull', 3);
        $source = $this->tempFile('quota.txt', '1234');

        try {
            $this->service()->importLocalFile($userId, null, $source, 'quota.txt', 'text/plain');
            $this->fail('Quota overflow should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->seeInDatabase('users', [
            'id' => $userId,
            'storage_used_bytes' => 0,
        ]);
        $this->dontSeeInDatabase('drive_items', ['user_id' => $userId]);
    }

    public function testSpecialNamesAreSanitizedWithoutUsingPhysicalUserName(): void
    {
        $userId = $this->createUser('specialname', 1024);
        $source = $this->tempFile('special.txt', 'content');

        $item = $this->service()->importLocalFile($userId, null, $source, '../Project Plan (final).txt', 'text/plain');

        $this->assertSame('Project Plan (final).txt', $item['name']);
        $this->assertStringNotContainsString('Project Plan', (string) $item['physical_path']);
        $this->assertStringNotContainsString('..', (string) $item['physical_path']);
    }

    public function testFolderOperationsCopyMoveTrashRestoreAndDestroyStayConsistent(): void
    {
        $userId = $this->createUser('folderops', 4096);
        $service = $this->service();
        $folder = $service->createFolder($userId, null, 'Projects');
        $subfolder = $service->createFolder($userId, (int) $folder['id'], 'Specs');
        $source = $this->tempFile('brief.txt', 'brief');
        $file = $service->importLocalFile($userId, (int) $subfolder['id'], $source, 'brief.txt', 'text/plain');
        $originalPath = $service->downloadableFile($userId, (int) $file['id'])['path'];

        $renamed = $service->renameItem($userId, (int) $file['id'], 'brief-final.txt');
        $this->assertSame('brief-final.txt', $renamed['name']);

        $copy = $service->copyItem($userId, (int) $folder['id'], null);
        $this->assertSame('Projects copy', $copy['name']);
        $this->seeInDatabase('users', [
            'id' => $userId,
            'storage_used_bytes' => 10,
        ]);

        $service->moveItem($userId, (int) $subfolder['id'], null);
        $this->seeInDatabase('drive_items', [
            'id' => $subfolder['id'],
            'parent_id' => null,
        ]);

        $service->trashItems($userId, [(int) $file['id']], $userId);
        $this->seeInDatabase('drive_items', [
            'id' => $file['id'],
            'status' => 'trashed',
        ]);

        $service->restoreItems($userId, [(int) $file['id']]);
        $this->seeInDatabase('drive_items', [
            'id' => $file['id'],
            'status' => 'active',
        ]);

        $service->trashItems($userId, [(int) $file['id']], $userId);
        $service->destroyItems($userId, [(int) $file['id']]);
        $this->assertFileDoesNotExist($originalPath);
        $this->dontSeeInDatabase('drive_items', ['id' => $file['id']]);
    }

    public function testListingSearchPaginationRecentStarredAndTrashWork(): void
    {
        $userId = $this->createUser('listinguser', 4096);
        $service = $this->service();
        $folder = $service->createFolder($userId, null, 'Reports');
        $alpha = $service->importLocalFile($userId, null, $this->tempFile('alpha.txt', 'alpha'), 'Alpha.txt', 'text/plain');
        $beta = $service->importLocalFile($userId, null, $this->tempFile('beta.txt', 'beta'), 'Beta.txt', 'text/plain');
        $service->importLocalFile($userId, (int) $folder['id'], $this->tempFile('gamma.txt', 'gamma'), 'Gamma.txt', 'text/plain');

        $pageOne = $service->listFolder($userId, null, ['page' => 1, 'perPage' => 2, 'sort' => 'name']);
        $pageTwo = $service->listFolder($userId, null, ['page' => 2, 'perPage' => 2, 'sort' => 'name']);
        $this->assertSame(3, $pageOne['pager']['total']);
        $this->assertCount(2, $pageOne['items']);
        $this->assertCount(1, $pageTwo['items']);

        $search = $service->listFolder($userId, null, ['q' => 'alp']);
        $this->assertSame(['Alpha.txt'], array_column($search['items'], 'name'));

        $service->starItem($userId, (int) $beta['id']);
        $starred = $service->listStarred($userId);
        $this->assertSame(['Beta.txt'], array_column($starred['items'], 'name'));

        $service->downloadableFile($userId, (int) $alpha['id']);
        $recent = $service->listRecent($userId);
        $this->assertContains('Alpha.txt', array_column($recent['items'], 'name'));
        $this->assertNotContains('Reports', array_column($recent['items'], 'name'));

        $service->trashItems($userId, [(int) $beta['id']], $userId);
        $trash = $service->listTrash($userId);
        $this->assertSame(['Beta.txt'], array_column($trash['items'], 'name'));
    }

    public function testCrossUserAccessIsRejected(): void
    {
        $ownerId = $this->createUser('owneruser', 1024);
        $otherId = $this->createUser('otheruser', 1024);
        $service = $this->service();
        $source = $this->tempFile('secret.txt', 'secret');
        $item = $service->importLocalFile($ownerId, null, $source, 'secret.txt', 'text/plain');

        $this->expectException(DomainException::class);
        $service->downloadableFile($otherId, (int) $item['id']);
    }

    public function testMissingStoredFileReportsMissingInsteadOfOutsideStorage(): void
    {
        $userId = $this->createUser('missingfile', 1024);
        $service = $this->service();
        $item = $service->importLocalFile($userId, null, $this->tempFile('missing.txt', 'gone'), 'missing.txt', 'text/plain');
        $path = $service->downloadableFile($userId, (int) $item['id'])['path'];

        unlink($path);
        rmdir(dirname($path));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored file is missing from disk.');
        $service->downloadableFile($userId, (int) $item['id']);
    }

    public function testChunkedUploadAcceptsSeventyGbWithIndexesFittingDatabase(): void
    {
        $limit = 75_161_927_680;
        $userId = $this->createUser('largeupload', $limit);
        $config = $this->config();
        $config->maxUploadSizeBytes = $limit;
        $config->chunkSizeBytes = 16_777_216;
        $chunked = new ChunkedUploadService($this->db, $this->service($config), $config);

        $session = $chunked->initUpload($userId, null, 'large.txt', $limit, 'text/plain');

        $this->assertSame(4480, $session['total_chunks']);
        $this->assertLessThanOrEqual(65_535, strlen(json_encode(range(0, $session['total_chunks'] - 1))));
        $this->seeInDatabase('chunked_uploads', ['upload_id' => $session['upload_id'], 'file_size' => $limit]);
        $chunked->cancelUpload($userId, $session['upload_id']);
    }

    public function testChunkedUploadRejectsFileAboveSeventyGb(): void
    {
        $userId = $this->createUser('oversizeupload', 75_161_927_681);
        $config = $this->config();
        $config->maxUploadSizeBytes = 75_161_927_680;
        $chunked = new ChunkedUploadService($this->db, $this->service($config), $config);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('File exceeds the maximum upload size.');
        $chunked->initUpload($userId, null, 'large.txt', 75_161_927_681, 'text/plain');
    }

    public function testLargeUploadStillRequiresSufficientUserQuota(): void
    {
        $userId = $this->createUser('limitedupload', 5_368_709_120);
        $config = $this->config();
        $config->maxUploadSizeBytes = 75_161_927_680;
        $chunked = new ChunkedUploadService($this->db, $this->service($config), $config);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Not enough storage quota.');
        $chunked->initUpload($userId, null, 'large.txt', 75_161_927_680, 'text/plain');
    }

    public function testChunkedUploadAssemblesAndStoresFile(): void
    {
        $userId = $this->createUser('chunkedroundtrip', 1024);
        $config = $this->config();
        $config->chunkSizeBytes = 4;
        $chunked = new ChunkedUploadService($this->db, $this->service($config), $config);
        $session = $chunked->initUpload($userId, null, 'chunks.txt', 11, 'text/plain');

        foreach (['hell', 'o cl', 'oud'] as $index => $contents) {
            $chunked->receiveChunk($userId, $session['upload_id'], $index, $this->tempFile('chunk.txt', $contents));
        }

        $item = $chunked->completeUpload($userId, $session['upload_id']);
        $download = $this->service($config)->downloadableFile($userId, (int) $item['id']);
        $this->assertSame('hello cloud', file_get_contents($download['path']));
        $this->assertSame(11, (int) $item['size_bytes']);
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
        return new DriveService($this->db, null, null, $config ?? $this->config());
    }

    private function config(): CloudDrive
    {
        $config = new CloudDrive();
        $config->storagePath = $this->storagePath;
        $config->allowedExtensions = ['txt', 'md'];
        $config->allowedMimeTypes = ['text/plain'];

        return $config;
    }

    private function tempFile(string $name, string $contents): string
    {
        $directory = WRITEPATH . 'test-drive-sources';
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
