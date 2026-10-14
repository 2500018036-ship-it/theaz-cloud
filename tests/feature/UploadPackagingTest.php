<?php

use App\Libraries\AdminService;
use App\Libraries\BatchUploadService;
use App\Libraries\ChunkedUploadService;
use App\Libraries\DriveService;
use App\Libraries\SystemSettingsService;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CloudDrive;

final class UploadPackagingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';
    private string $directory;
    private CloudDrive $driveConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = WRITEPATH . 'test-packaging-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
        $this->driveConfig = new CloudDrive();
        $this->driveConfig->storagePath = $this->directory . '/storage';
        $this->driveConfig->chunkSizeBytes = 4;
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
        session()->destroy();
        parent::tearDown();
    }

    public function testElevenFilesBecomeOneZipAndCompletionIsIdempotent(): void
    {
        $user = $this->user('zipowner');
        $service = $this->batch();
        $manifest = $this->manifest();
        $manifest[1]['name'] = $manifest[0]['name'];
        $batch = $service->init($user, null, $manifest);
        $this->assertCount(11, $batch['sessions']);
        foreach ($batch['sessions'] as $index => $session) {
            $this->stage($user, $batch, $session, 'hello');
            $this->assertSame(0, $this->db->table('drive_items')->countAllResults());
            $this->assertSame(0, $this->drive()->userStorageSummary($user)['used']);
        }
        $item = $service->complete($user, $batch['batch_id']);
        $download = $this->drive()->readableFile($user, (int) $item['id']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($download['path']));
        $this->assertSame(11, $zip->numFiles);
        $this->assertSame('hello', $zip->getFromName('file-0.txt'));
        $this->assertSame('hello', $zip->getFromName('file-0 (2).txt'));
        $zip->close();
        $this->assertSame((int) $item['size_bytes'], $this->drive()->userStorageSummary($user)['used']);
        $this->assertSame((int) $item['id'], (int) $service->complete($user, $batch['batch_id'])['id']);
        $this->assertSame(1, $this->db->table('drive_items')->countAllResults());
        $this->assertSame(0, $this->db->table('chunked_uploads')->countAllResults());
        $this->assertSame([], glob($this->driveConfig->storagePath . '/tmp/*_assembled'));
    }

    public function testTenFilesAndInvalidNamesCannotStartAutomaticZip(): void
    {
        $user = $this->user('manifestowner');
        foreach ([array_slice($this->manifest(), 0, 10), array_replace($this->manifest(), [0 => ['name' => '../shell.php', 'size' => 5]])] as $files) {
            try { $this->batch()->init($user, null, $files); $this->fail('Invalid manifest must fail.'); }
            catch (DomainException) { $this->assertSame(0, $this->db->table('upload_batches')->countAllResults()); }
        }
    }

    public function testCancellationRemovesStagedFilesAndOtherUserCannotFinishBatch(): void
    {
        $owner = $this->user('cancelowner');
        $other = $this->user('cancelother');
        $batch = $this->batch()->init($owner, null, $this->manifest());
        $this->stage($owner, $batch, $batch['sessions'][0], 'hello');
        try { $this->batch()->complete($other, $batch['batch_id']); $this->fail('Cross-user completion must fail.'); }
        catch (DomainException) { $this->assertSame(0, $this->db->table('drive_items')->countAllResults()); }
        try { $this->batch()->complete($owner, $batch['batch_id']); $this->fail('Incomplete batch must fail.'); }
        catch (DomainException $error) { $this->assertSame(lang('App.transfers.batchIncomplete'), $error->getMessage()); }
        $this->batch()->cancel($owner, $batch['batch_id']);
        $this->assertSame(0, $this->db->table('chunked_uploads')->countAllResults());
        $this->assertSame(0, $this->db->table('upload_batches')->countAllResults());
        $this->assertSame([], glob($this->driveConfig->storagePath . '/tmp/*_assembled'));
    }

    public function testBatchUploadCannotBeCompletedAsAnIndividualFile(): void
    {
        $user = $this->user('boundowner');
        $batch = $this->batch()->init($user, null, $this->manifest());
        $this->expectException(DomainException::class);
        $this->chunks()->completeUpload($user, $batch['sessions'][0]['upload_id']);
    }

    public function testSharedUploadUsesFolderOwnersQuota(): void
    {
        $owner = $this->user('folderowner', 1_048_576);
        $editor = $this->user('foldereditor', 0);
        $folder = $this->drive()->createFolder($owner, null, 'Shared');
        $this->drive()->shareItemWithUser($owner, (int) $folder['id'], 'foldereditor@example.test', 'editor', $owner);
        $batch = $this->batch()->init($editor, (int) $folder['id'], $this->manifest());
        foreach ($batch['sessions'] as $session) $this->stage($editor, $batch, $session, 'hello');
        $item = $this->batch()->complete($editor, $batch['batch_id']);
        $this->assertSame($owner, (int) $item['user_id']);
        $this->assertSame(0, $this->drive()->userStorageSummary($editor)['used']);
        $this->assertSame((int) $item['size_bytes'], $this->drive()->userStorageSummary($owner)['used']);
    }

    public function testQuotaCanBeUpgradedTo120GbAndRejectsLargerAssignments(): void
    {
        $admin = $this->user('quotaadmin', 0, 'super_admin');
        $user = $this->user('quotatarget', 5_368_709_120);
        $this->assertSame(128_849_018_880, (new SystemSettingsService($this->db))->getInt('max_admin_assignable_quota_bytes'));
        $updated = (new AdminService($this->db))->updateUserQuota($admin, $user, 128_849_018_880);
        $this->assertSame(128_849_018_880, (int) $updated['storage_quota_bytes']);
        $this->expectException(DomainException::class);
        (new AdminService($this->db))->updateUserQuota($admin, $user, 128_849_018_881);
    }

    public function testFiveGbQuotaStillRejectsFivePointFortyEightGbZip(): void
    {
        $user = $this->user('limitedowner', 5_368_709_120);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Not enough storage quota.');
        $this->chunks()->initUpload($user, null, 'DIANA.zip', (int) (5.48 * 1073741824));
    }

    public function testImageGalleryFiltersBeforePaginationAndDownloadIncludesAllSelectedFiles(): void
    {
        $user = $this->user('galleryowner');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        file_put_contents($this->directory . '/pixel.png', $png);
        $photo = $this->drive()->importLocalFile($user, null, $this->directory . '/pixel.png', 'photo.png');
        file_put_contents($this->directory . '/notes.txt', 'notes');
        $notes = $this->drive()->importLocalFile($user, null, $this->directory . '/notes.txt', 'notes.txt');
        $images = $this->drive()->listFolder($user, null, ['type' => 'image', 'perPage' => 1]);
        $this->assertSame(1, $images['pager']['total']);
        $this->assertSame('photo.png', $images['items'][0]['name']);
        $download = $this->drive()->collectDownloadFiles($user, [(int) $photo['id'], (int) $notes['id']]);
        $this->assertCount(2, $download['items']);
        $this->assertSame(strlen($png) + 5, $download['total_bytes']);
    }

    private function manifest(): array { return array_map(static fn ($i) => ['name' => 'file-' . $i . '.txt', 'size' => 5], range(0, 10)); }
    private function drive(): DriveService { return new DriveService($this->db, null, null, $this->driveConfig); }
    private function batch(): BatchUploadService { return new BatchUploadService($this->db, $this->drive(), $this->driveConfig); }
    private function chunks(): ChunkedUploadService { return new ChunkedUploadService($this->db, $this->drive(), $this->driveConfig); }
    private function stage(int $user, array $batch, array $session, string $contents): void
    {
        foreach (str_split($contents, $session['chunk_size']) as $i => $part) {
            $path = $this->directory . '/part'; file_put_contents($path, $part);
            $this->chunks()->receiveChunk($user, $session['upload_id'], $i, $path);
        }
        $this->batch()->stage($user, $batch['batch_id'], $session['upload_id']);
    }
    private function user(string $name, int $quota = 1_048_576, string $role = 'user'): int
    {
        return (int) (new UserModel())->insert(['username' => $name, 'email' => $name . '@example.test',
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active',
            'storage_quota_bytes' => $quota, 'storage_used_bytes' => 0], true);
    }
}
