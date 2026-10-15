<?php

use App\Libraries\DriveService;
use App\Controllers\DriveController;
use App\Models\DriveItemModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\CloudDrive;
use PHPUnit\Framework\Attributes\DataProvider;

final class ArchiveExtractionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use ControllerTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';
    private string $directory;
    private CloudDrive $driveConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = WRITEPATH . 'test-extract-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
        $this->driveConfig = new CloudDrive();
        $this->driveConfig->storagePath = $this->directory . '/storage';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testNestedFilesEmptyFoldersQuotaAndUniqueDestinationArePreserved(): void
    {
        $user = $this->user('extractowner');
        $service = $this->drive();
        $target = $service->createFolder($user, null, 'Destination');
        $archive = $this->archive($user, ['docs/readme.txt' => 'hello cloud', 'notes.md' => 'notes', 'empty/' => null]);
        $used = $service->userStorageSummary($user)['used'];
        $folder = $service->extractArchive($user, (int) $archive['id'], (int) $target['id']);
        $this->assertSame('bundle', $folder['name']);
        $this->assertSame((int) $target['id'], (int) $folder['parent_id']);
        $children = $service->listFolder($user, (int) $folder['id'])['items'];
        $this->assertEqualsCanonicalizing(['docs', 'notes.md', 'empty'], array_column($children, 'name'));
        $docs = array_values(array_filter($children, static fn ($row) => $row['name'] === 'docs'))[0];
        $readme = $service->listFolder($user, (int) $docs['id'])['items'][0];
        $this->assertSame('hello cloud', file_get_contents($service->readableFile($user, (int) $readme['id'])['path']));
        $this->assertSame($used + 16, $service->userStorageSummary($user)['used']);
        $this->assertSame('bundle (2)', $service->extractArchive($user, (int) $archive['id'], (int) $target['id'])['name']);
        $this->assertSame([], glob($this->driveConfig->storagePath . '/tmp/extract-*'));
        $this->assertFileExists($service->readableFile($user, (int) $archive['id'])['path']);
    }

    public static function unsafePaths(): array
    {
        return array_map(static fn ($path) => [$path], ['../escape.txt', '/absolute.txt', 'C:/drive.txt', '..\\escape.txt', 'a/../../escape.txt', 'a//b.txt', './file.txt', 'bad./file.txt']);
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsNeverCreateFilesOrConsumeQuota(string $path): void
    {
        $user = $this->user('unsafepath');
        $archive = $this->archive($user, [$path => 'unsafe']);
        $this->reject($user, $archive, lang('App.transfers.unsafeArchivePath'));
    }

    public function testDisallowedFileRejectsEntireArchive(): void
    {
        $user = $this->user('blockedfile');
        $archive = $this->archive($user, ['safe.txt' => 'hello', 'shell.php' => '<?php echo 1;']);
        $this->reject($user, $archive, lang('App.transfers.archiveFileNotAllowed', ['shell.php']));
    }

    public function testMimeValidationFailureLeavesNoPartialFoldersOrFiles(): void
    {
        $user = $this->user('blockedmime');
        $archive = $this->archive($user, ['safe.txt' => 'hello', 'binary.txt' => str_repeat("\x00\x01\x02", 100)]);
        $this->driveConfig->allowedMimeTypes = ['text/plain', 'application/zip'];
        $this->reject($user, $archive, 'File type is not allowed.');
    }

    public function testQuotaIncludesUncompressedBytesAndArchiveRemains(): void
    {
        $user = $this->user('smallquota');
        $archive = $this->archive($user, ['large.txt' => str_repeat('hello', 1000)]);
        $used = $this->drive()->userStorageSummary($user)['used'];
        $this->db->table('users')->where('id', $user)->update(['storage_quota_bytes' => $used + 4999]);
        $this->reject($user, $archive, 'Storage quota is full.');
    }

    public function testArchiveExpansionAndEntryLimitsAreEnforced(): void
    {
        $user = $this->user('limits');
        $archive = $this->archive($user, ['one.txt' => '12345', 'two.txt' => '67890']);
        $this->driveConfig->maxArchiveExtractBytes = 9;
        $this->reject($user, $archive, lang('App.transfers.archiveSizeLimit'));
        $this->driveConfig->maxArchiveExtractBytes = 1000;
        $this->driveConfig->maxArchiveEntries = 1;
        $this->reject($user, $archive, lang('App.transfers.archiveEntryLimit'));
        $nested = $this->archive($user, ['deep/folder/one.txt' => 'nested']);
        $this->reject($user, $nested, lang('App.transfers.archiveEntryLimit'));
    }

    public function testNonZipAndDamagedZipAreRejected(): void
    {
        $user = $this->user('damagedzip');
        $path = $this->directory . '/damaged.txt';
        file_put_contents($path, 'This is not a ZIP archive.');
        $file = $this->drive()->importLocalFile($user, null, $path, 'ordinary.txt');
        $this->reject($user, $file, lang('App.transfers.zipOnly'));
        $file = $this->drive()->importLocalFile($user, null, $path, 'damaged.zip');
        $this->reject($user, $file, lang('App.transfers.invalidArchive'));
    }

    public function testMetadataFailureRollsBackQuotaFoldersAndPrivateFile(): void
    {
        $user = $this->user('rollback');
        $archive = $this->archive($user, ['docs/hello.txt' => 'hello']);
        $used = $this->drive()->userStorageSummary($user)['used'];
        $model = new class extends DriveItemModel {
            public function insert($row = null, bool $returnID = true)
            {
                if ($row['type'] === 'file') throw new RuntimeException('Simulated metadata failure');

                return parent::insert($row, $returnID);
            }
        };
        try {
            (new DriveService($this->db, $model, null, $this->driveConfig))->extractArchive($user, (int) $archive['id'], null);
            $this->fail('The simulated write failure must reject extraction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated metadata failure', $error->getMessage());
        }
        $this->assertSame(1, $this->db->table('drive_items')->countAllResults());
        $this->assertSame($used, $this->drive()->userStorageSummary($user)['used']);
        $this->assertCount(1, glob($this->driveConfig->storagePath . '/users/' . $user . '/files/*'));
        $this->assertSame([], glob($this->driveConfig->storagePath . '/tmp/extract-*'));
    }

    public function testJsonEndpointsReturnExplicitSuccessErrorsAndCsrf(): void
    {
        $owner = $this->user('jsonowner');
        $other = $this->user('jsonother');
        $archive = $this->archive($owner, ['hello.txt' => 'hello']);
        $sharedConfig = config(CloudDrive::class);
        $previousPath = $sharedConfig->storagePath;
        $sharedConfig->storagePath = $this->driveConfig->storagePath;
        session()->set('user_id', $owner);
        try {
            $request = service('incomingrequest', null, false);
            $request->setMethod('POST')->setHeader('Accept', 'application/json')->setGlobal('post', ['target_folder_id' => '']);
            $response = $this->withRequest($request)->withResponse(service('response', null, false))
                ->controller(DriveController::class)->execute('extract', (int) $archive['id']);
            $response->assertOK();
            $payload = json_decode($response->response()->getBody(), true);
            $this->assertTrue($payload['success']);
            $this->assertStringContainsString('/drive?folder=' . $payload['folder']['id'], $payload['folder_url']);
            $this->assertSame(csrf_token(), $payload['csrf_name']);
            $this->assertNotEmpty($payload['csrf_hash']);

            session()->set('user_id', $other);
            $response = $this->withRequest($request)->withResponse(service('response', null, false))
                ->controller(DriveController::class)->execute('extract', (int) $archive['id']);
            $response->assertStatus(422);
            $this->assertFalse(json_decode($response->response()->getBody(), true)['success']);

            $response = $this->withRequest($request)->withResponse(service('response', null, false))
                ->controller(DriveController::class)->execute('upload');
            $response->assertStatus(422);
            $payload = json_decode($response->response()->getBody(), true);
            $this->assertFalse($payload['success']);
            $this->assertSame('No files were uploaded.', $payload['error']);
        } finally {
            $sharedConfig->storagePath = $previousPath;
            session()->destroy();
        }
    }

    public function testCaseAndFileFolderConflictsAreRejected(): void
    {
        $user = $this->user('conflicts');
        $archive = $this->archive($user, ['same.txt' => 'first', 'SAME.txt' => 'second']);
        $this->reject($user, $archive, lang('App.transfers.duplicateArchivePath'));
        $archive = $this->archive($user, ['parent.txt' => 'first', 'parent.txt/child.txt' => 'second']);
        $this->reject($user, $archive, lang('App.transfers.duplicateArchivePath'));
    }

    public function testSymbolicLinkAndEncryptedEntriesAreRejected(): void
    {
        $user = $this->user('specialentries');
        $archive = $this->archive($user, ['link.txt' => '/etc/passwd'], static function (ZipArchive $zip): void {
            $zip->setExternalAttributesName('link.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        $this->reject($user, $archive, lang('App.transfers.unsupportedArchiveEntry'));
        $archive = $this->archive($user, ['secret.txt' => 'secret'], static function (ZipArchive $zip): void {
            $zip->setPassword('test-password');
            $zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256);
        });
        $this->reject($user, $archive, lang('App.transfers.unsupportedArchiveEntry'));
    }

    public function testUnauthorizedSourceAndDestinationAreRejected(): void
    {
        $owner = $this->user('privateowner');
        $other = $this->user('privateother');
        $archive = $this->archive($owner, ['hello.txt' => 'hello']);
        try {
            $this->drive()->extractArchive($other, (int) $archive['id'], null);
            $this->fail('Private archive access must fail.');
        } catch (DomainException) {
            $this->dontSeeInDatabase('drive_items', ['user_id' => $other]);
        }
        $target = $this->drive()->createFolder($other, null, 'Private');
        $this->expectException(DomainException::class);
        $this->drive()->extractArchive($owner, (int) $archive['id'], (int) $target['id']);
    }

    public function testSharedViewerCanExtractIntoOwnDriveAndEditorUsesDestinationOwnerQuota(): void
    {
        $owner = $this->user('shareowner');
        $viewer = $this->user('shareviewer');
        $archive = $this->archive($owner, ['hello.txt' => 'hello']);
        $service = $this->drive();
        $service->shareItemWithUser($owner, (int) $archive['id'], 'shareviewer@example.test', 'viewer', $owner);
        $own = $service->extractArchive($viewer, (int) $archive['id'], null);
        $this->assertSame($viewer, (int) $own['user_id']);
        $shared = $service->createFolder($owner, null, 'Shared');
        $service->shareItemWithUser($owner, (int) $shared['id'], 'shareviewer@example.test', 'editor', $owner);
        $used = $service->userStorageSummary($owner)['used'];
        $result = $service->extractArchive($viewer, (int) $archive['id'], (int) $shared['id']);
        $this->assertSame($owner, (int) $result['user_id']);
        $this->assertSame($used + 5, $service->userStorageSummary($owner)['used']);
        $this->assertSame(5, $service->userStorageSummary($viewer)['used']);
    }

    private function reject(int $user, array $archive, string $message): void
    {
        $used = $this->drive()->userStorageSummary($user)['used'];
        $count = $this->db->table('drive_items')->countAllResults();
        try {
            $this->drive()->extractArchive($user, (int) $archive['id'], null);
            $this->fail('Archive should have been rejected.');
        } catch (DomainException $error) {
            $this->assertSame($message, $error->getMessage());
        }
        $this->assertSame($used, $this->drive()->userStorageSummary($user)['used']);
        $this->assertSame($count, $this->db->table('drive_items')->countAllResults());
        $this->assertSame([], glob($this->driveConfig->storagePath . '/tmp/extract-*'));
    }

    private function archive(int $user, array $entries, ?callable $configure = null): array
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $content === null ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
        }
        if ($configure !== null) $configure($zip);
        $zip->close();

        return $this->drive()->importLocalFile($user, null, $path, 'bundle.zip');
    }

    private function user(string $name): int
    {
        return (int) (new UserModel())->insert([
            'username' => $name, 'email' => $name . '@example.test', 'status' => 'active', 'role' => 'user',
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'storage_quota_bytes' => 1_048_576, 'storage_used_bytes' => 0,
        ], true);
    }

    private function drive(): DriveService
    {
        return new DriveService($this->db, null, null, $this->driveConfig);
    }
}
