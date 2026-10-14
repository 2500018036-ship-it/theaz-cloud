<?php

use App\Libraries\AdminService;
use App\Libraries\AnnouncementDeliveryService;
use App\Libraries\AuthService;
use App\Libraries\InvitationService;
use App\Libraries\NotificationService;
use App\Libraries\QueuedEmailDeliveryService;
use App\Models\EmailQueueModel;
use App\Models\UserModel;
use App\Models\UserProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class BulkAndDeliveryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    protected function tearDown(): void { session()->destroy(); parent::tearDown(); }
    private function user(string $name, string $role = 'user'): int
    {
        $id = (int) (new UserModel())->insert(['username' => $name, 'email' => $name . '@example.test',
            'role' => $role, 'status' => 'active', 'password_hash' => password_hash('test-secure-password', PASSWORD_DEFAULT)], true);
        (new UserProfileModel())->insert(['user_id' => $id, 'email_verified_at' => date('Y-m-d H:i:s'), 'email_announcements' => 1]);
        return $id;
    }
    public function testPrimaryOwnerCannotBeDeletedThroughTheService(): void
    {
        $id = $this->user('owner', 'super_admin');
        $this->expectException(DomainException::class);
        (new AdminService($this->db))->deleteUser($id, $id);
    }
    public function testOrdinaryUserCannotDeleteAnotherAccount(): void
    {
        $actor = $this->user('actor'); $target = $this->user('target');
        $this->expectException(DomainException::class);
        (new AdminService($this->db))->deleteUser($actor, $target);
    }
    public function testUserDeletionRetainsHistoryCancelsMailAndDeniesLogin(): void
    {
        $owner = $this->user('owner', 'super_admin'); $target = $this->user('target');
        $before = (new UserModel())->find($target);
        $queue = (new NotificationService($this->db))->queueEmail($target, 'target@example.test', 'announcement', null, 'Title', 'Body');
        (new AdminService($this->db))->deleteUser($owner, $target);
        $this->assertNull((new UserModel())->find($target));
        $record = (new UserModel())->withDeleted()->find($target);
        $this->assertSame($before['password_hash'], $record['password_hash']);
        $this->assertNotEmpty($record['deleted_at']);
        $this->assertFalse((new AuthService())->attemptLogin('target', 'test-secure-password')['ok']);
        $this->seeInDatabase('email_queue', ['id' => $queue, 'status' => 'cancelled']);
        $this->seeInDatabase('user_profiles', ['user_id' => $target]);
    }
    public function testInvalidBulkInvitationSelectionDoesNotPartiallyDelete(): void
    {
        $owner = $this->user('owner', 'super_admin'); $invites = new InvitationService(null, $this->db);
        $expired = $invites->create($invites->generatePlainCode(), 'Expired', 1, '2020-01-01 00:00:00', $owner);
        $active = $invites->create($invites->generatePlainCode(), 'Active', 1, null, $owner);
        try { $invites->bulk($owner, [$expired, $active], 'delete'); $this->fail('Mixed invalid selection accepted.'); }
        catch (DomainException) { $this->seeInDatabase('invitation_codes', ['id' => $expired, 'deleted_at' => null]); }
        $this->assertSame(1, $invites->bulk($owner, [$active], 'revoke'));
        $this->assertSame(2, $invites->bulk($owner, [$expired, $active], 'delete'));
        $this->assertSame(2, $this->db->table('invitation_codes')->where('deleted_at IS NOT NULL', null, false)->countAllResults());
    }
    public function testBroadcastCreateAndPublishAreIdempotent(): void
    {
        $owner = $this->user('owner', 'super_admin'); $user = $this->user('recipient');
        $service = new NotificationService($this->db);
        $input = ['title' => 'Notice', 'body' => 'owner@example.test recipient@example.test other@example.test',
            'channels' => 'notification,email', 'target_type' => 'user', 'target_user_id' => $user, 'request_key' => str_repeat('a', 64)];
        $first = $service->createAnnouncement($owner, $input);
        $this->assertSame($first['id'], $service->createAnnouncement($owner, $input)['id']);
        $service->publishAnnouncement($owner, (int) $first['id']); $service->publishAnnouncement($owner, (int) $first['id']);
        $this->assertSame(1, $this->db->table('email_queue')->where('event_type', 'announcement')->countAllResults());
        $this->assertSame(1, $this->db->table('notifications')->where('related_id', $first['id'])->countAllResults());
        $mail = $this->db->table('email_queue')->get()->getRowArray();
        $this->assertStringNotContainsString('owner@example.test', $mail['body_text']);
        $this->assertStringNotContainsString('other@example.test', $mail['body_text']);
        $this->assertSame('recipient@example.test', $mail['recipient_email']);
    }
    public function testAnnouncementDeletionPreservesNotificationsAndDeliveryHistory(): void
    {
        $owner = $this->user('owner', 'super_admin'); $user = $this->user('recipient'); $service = new NotificationService($this->db);
        $ann = $service->createAnnouncement($owner, ['title' => 'Notice', 'body' => 'Body', 'channels' => 'notification,email', 'target_type' => 'user', 'target_user_id' => $user]);
        $service->publishAnnouncement($owner, (int) $ann['id']);
        $service->bulkAnnouncements($owner, [$ann['id']], 'delete');
        $this->assertSame([], $service->listAnnouncements()['items']);
        $this->seeInDatabase('email_queue', ['event_id' => $ann['id'], 'status' => 'cancelled']);
        $this->assertCount(1, $service->listForUser($user)['items']);
        $this->expectException(DomainException::class); $service->publishAnnouncement($owner, (int) $ann['id']);
    }
    public function testNotificationDetailAndReadAreRestrictedToItsRecipient(): void
    {
        $one = $this->user('one'); $two = $this->user('two'); $service = new NotificationService($this->db);
        $service->createForUser($one, 'announcement', 'owner@example.test', 'one@example.test two@example.test');
        $id = (int) $service->listForUser($one)['items'][0]['id'];
        try { $service->markRead($two, $id); $this->fail('Another recipient marked notification read.'); } catch (DomainException) {}
        $notice = $service->notificationForUser($one, $id); $this->assertNull($notice['read_at']);
        $this->assertStringNotContainsString('two@example.test', $notice['body']);
        $this->assertStringNotContainsString('owner@example.test', $notice['title']);
        $service->markRead($one, $id); $this->assertNotEmpty($service->notificationForUser($one, $id)['read_at']);
        $this->expectException(DomainException::class); $service->notificationForUser($two, $id);
    }
    public function testWorkerUpdatesPersistedAnnouncementStatusWithoutDuplicateSending(): void
    {
        $owner = $this->user('owner', 'super_admin'); $user = $this->user('recipient'); $service = new NotificationService($this->db);
        $ann = $service->createAnnouncement($owner, ['title' => 'Notice', 'body' => 'Body', 'channels' => 'email', 'target_type' => 'user', 'target_user_id' => $user]);
        $service->publishAnnouncement($owner, (int) $ann['id']);
        $row = $this->db->table('email_queue')->where('event_id', $ann['id'])->get()->getRowArray();
        $this->db->table('email_queue')->where('id', $row['id'])->update(['recipient_email' => 'recipient@gmail.com']);
        $row['recipient_email'] = 'recipient@gmail.com';
        $mock = new class extends \CodeIgniter\Email\Email { public int $calls = 0; public function send($autoClear = true): bool { $this->calls++; return true; } };
        \Config\Services::injectMock('email', $mock);
        $config = new \Config\Email(); $config->fromEmail = 'sender@example.test';
        $delivery = new QueuedEmailDeliveryService(new EmailQueueModel(), $config); $method = new ReflectionMethod($delivery, 'deliver');
        $this->assertTrue($method->invoke($delivery, $row, 5)['sent']);
        $this->assertFalse($method->invoke($delivery, $row, 5)['sent']);
        $this->assertSame(1, $mock->calls);
        $this->seeInDatabase('announcements', ['id' => $ann['id'], 'email_status' => 'sent']);
        $summary = (new AnnouncementDeliveryService($this->db))->summary([(int) $ann['id']]);
        $this->assertSame(1, $summary[(int) $ann['id']]['sent']);
        $this->assertStringNotContainsString('recipient@gmail.com', json_encode($summary));
    }
}
