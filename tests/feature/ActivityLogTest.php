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
final class ActivityLogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;

    protected $refresh = true;

    protected $namespace = 'App';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = WRITEPATH . 'test-activity-log-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
        $this->removeDirectory(WRITEPATH . 'test-activity-sources');
        parent::tearDown();
    }

    public function testActivityLogRecordsFailedOperationsAndKeepsPrivateMetadataRedacted(): void
    {
        $ownerId = $this->createUser('logowner', 100);
        $otherId = $this->createUser('logother', 100);
        $config = $this->config();
        $logger = new ActivityLogger($this->db, $config);
        $service = new DriveService($this->db, null, null, $config, $logger);
        $file = $service->importLocalFile($ownerId, null, $this->tempFile('secret.txt', 'secret'), 'secret.txt', 'text/plain');

        try {
            $service->downloadableFile($otherId, (int) $file['id']);
            $this->fail('Cross-user download should fail.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $logger->record($ownerId, 'security.test', 'failure', [], [
            'password' => 'super-secret',
            'nested'   => ['token' => 'abc123'],
            'note'     => 'safe',
        ]);

        $this->seeInDatabase('activity_logs', [
            'actor_id' => $otherId,
            'action'   => 'drive.download',
            'status'   => 'failure',
        ]);

        $row = $this->db->table('activity_logs')
            ->where('action', 'security.test')
            ->get()
            ->getRowArray();

        $this->assertIsArray($row);
        $this->assertStringNotContainsString('super-secret', (string) $row['metadata_json']);
        $this->assertStringNotContainsString('abc123', (string) $row['metadata_json']);
        $this->assertStringContainsString('[redacted]', (string) $row['metadata_json']);
    }

    public function testActivityListUsesNewestFirstPaginationAndUserScope(): void
    {
        $userId = $this->createUser('activityuser', 100);
        $otherId = $this->createUser('activityother', 100);
        $logger = new ActivityLogger($this->db, $this->config());

        $logger->record($userId, 'first.event', 'success');
        usleep(1000);
        $logger->record($userId, 'second.event', 'success');
        usleep(1000);
        $logger->record($otherId, 'other.event', 'success');
        usleep(1000);
        $logger->record($userId, 'third.event', 'failure');

        $pageOne = $logger->listForUser($userId, 'user', ['page' => 1, 'perPage' => 2]);
        $pageTwo = $logger->listForUser($userId, 'user', ['page' => 2, 'perPage' => 2]);

        $this->assertSame(3, $pageOne['pager']['total']);
        $this->assertSame(['third.event', 'second.event'], array_column($pageOne['items'], 'action'));
        $this->assertSame(['first.event'], array_column($pageTwo['items'], 'action'));

        $adminView = $logger->listForUser($userId, 'admin', ['page' => 1, 'perPage' => 10]);
        $this->assertSame(4, $adminView['pager']['total']);
    }

    public function testActivityLogEntryCanBeDeletedAndDeletionIsAudited(): void
    {
        $adminId = $this->createUser('deleteauditadmin', 100);
        $logger = new ActivityLogger($this->db, $this->config());
        $logger->record($adminId, 'delete.target', 'success');

        $row = $this->db->table('activity_logs')
            ->where('action', 'delete.target')
            ->get()
            ->getRowArray();
        $this->assertIsArray($row);

        $logger->deleteById($adminId, (int) $row['id']);

        $this->dontSeeInDatabase('activity_logs', [
            'id' => (int) $row['id'],
        ]);
        $this->seeInDatabase('activity_logs', [
            'actor_id' => $adminId,
            'action'   => 'admin.audit.delete',
            'status'   => 'success',
        ]);
    }

    public function testActivityLogsCanBeClearedAndClearActionIsAudited(): void
    {
        $adminId = $this->createUser('clearauditadmin', 100);
        $logger = new ActivityLogger($this->db, $this->config());
        $logger->record($adminId, 'clear.target.one', 'success');
        $logger->record($adminId, 'clear.target.two', 'failure');

        $deleted = $logger->clearAll($adminId);

        $this->assertSame(2, $deleted);
        $this->dontSeeInDatabase('activity_logs', [
            'action' => 'clear.target.one',
        ]);
        $this->dontSeeInDatabase('activity_logs', [
            'action' => 'clear.target.two',
        ]);
        $this->seeInDatabase('activity_logs', [
            'actor_id' => $adminId,
            'action'   => 'admin.audit.clear',
            'status'   => 'success',
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

    private function config(): CloudDrive
    {
        $config = new CloudDrive();
        $config->storagePath = $this->storagePath;
        $config->allowedExtensions = ['txt'];
        $config->allowedMimeTypes = ['text/plain'];

        return $config;
    }

    private function tempFile(string $name, string $contents): string
    {
        $directory = WRITEPATH . 'test-activity-sources';
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
