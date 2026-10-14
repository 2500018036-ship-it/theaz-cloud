<?php

use App\Libraries\AdminService;
use App\Libraries\AuthService;
use App\Libraries\EmailVerificationService;
use App\Libraries\InvitationService;
use App\Libraries\NotificationService;
use App\Models\UserModel;
use App\Models\UserProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class PermanentSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    protected function tearDown(): void
    {
        session()->destroy();
        parent::tearDown();
    }

    private function user(string $name, string $role = 'user', string $status = 'pending_verification'): int
    {
        return (int) (new UserModel())->insert(['username' => $name, 'email' => $name . '@example.test',
            'password_hash' => password_hash('test-secure-password', PASSWORD_DEFAULT), 'role' => $role,
            'status' => $status, 'storage_quota_bytes' => 1048576, 'storage_used_bytes' => 0], true);
    }

    public function testTokenIsHashedExpiringSingleUseAndAllowsLoginOnlyAfterConfirmation(): void
    {
        $id = $this->user('verifyonce');
        $service = new EmailVerificationService($this->db);
        $issued = $service->issue($id, 'verifyonce@example.test');
        $profile = (new UserProfileModel())->find($id);
        $this->assertSame(hash('sha256', $issued['token']), $profile['pending_email_token_hash']);
        $this->assertFalse((new AuthService())->attemptLogin('verifyonce', 'test-secure-password')['ok']);
        $this->assertSame($id, $service->confirm($issued['token']));
        $this->assertTrue((new AuthService())->attemptLogin('verifyonce', 'test-secure-password')['ok']);
        $this->expectException(DomainException::class);
        $service->confirm($issued['token']);
    }

    public function testVerificationEmailUsesTheRegisteredSessionLanguage(): void
    {
        session()->set('locale', 'id');
        $id = $this->user('indonesian');
        $issued = (new EmailVerificationService($this->db))->issue($id, 'indonesian@example.test');
        $this->assertSame('id', (new UserProfileModel())->find($id)['locale']);
        $mail = $this->db->table('email_queue')->select('subject')->where('id', $issued['queue_id'])->get()->getRowArray();
        $this->assertSame(lang('App.email.verifySubject', [], 'id'), $mail['subject']);
    }

    public function testExpiredTokenCannotActivateAccount(): void
    {
        $id = $this->user('expiredtoken'); $service = new EmailVerificationService($this->db);
        $issued = $service->issue($id, 'expiredtoken@example.test');
        (new UserProfileModel())->update($id, ['pending_email_expires_at' => '2020-01-01 00:00:00']);
        try { $service->confirm($issued['token']); $this->fail('Expired token accepted'); } catch (DomainException) {}
        $this->assertSame('pending_verification', (new UserModel())->find($id)['status']);
    }

    public function testResendRotatesTokenAndCancelsObsoleteQueuedMessage(): void
    {
        $id = $this->user('resenduser'); $service = new EmailVerificationService($this->db);
        $old = $service->issue($id, 'resenduser@example.test'); $new = $service->resendForIdentifier('resenduser');
        $this->seeInDatabase('email_queue', ['id' => $old['queue_id'], 'status' => 'cancelled']);
        try { $service->confirm($old['token']); $this->fail('Old token accepted'); } catch (DomainException) {}
        $this->assertSame($id, $service->confirm($new['token']));
    }

    public function testVerificationDoesNotReactivateAnInactiveAccount(): void
    {
        $id = $this->user('inactiveverify'); $service = new EmailVerificationService($this->db);
        $issued = $service->issue($id, 'inactiveverify@example.test');
        (new UserModel())->update($id, ['status' => 'inactive']);
        $service->confirm($issued['token']);
        $this->assertSame('inactive_account', (new AuthService())->attemptLogin('inactiveverify', 'test-secure-password')['reason']);
    }

    public function testChangingStatusCannotBypassEmailVerification(): void
    {
        $owner = $this->user('owner', 'super_admin', 'active'); $id = $this->user('unverifiedactive', 'user', 'active');
        $this->assertSame('email_unverified', (new AuthService())->attemptLogin('unverifiedactive', 'test-secure-password')['reason']);
        $this->expectException(DomainException::class);
        (new AdminService($this->db))->updateUserStatus($owner, $id, 'active');
    }

    public function testOnlyOneAdministratorCanExist(): void
    {
        $this->user('primary', 'super_admin', 'active');
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->user('secondprimary', 'super_admin', 'active');
    }

    public function testPrimaryAdministratorCannotBeDeactivatedEvenWithDirectDatabaseWrite(): void
    {
        $id = $this->user('primary', 'super_admin', 'active');
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->db->table('users')->where('id', $id)->update(['status' => 'inactive']);
    }

    public function testPrimaryAdministratorCannotBeDeleted(): void
    {
        $id = $this->user('primary', 'super_admin', 'active');
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->db->table('users')->where('id', $id)->delete();
    }

    public function testNoUserCanBePromotedThroughDirectDatabaseWrite(): void
    {
        $id = $this->user('ordinary');
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->db->table('users')->where('id', $id)->update(['role' => 'super_admin']);
    }

    public function testArchivingExpiredUsedInvitationPreservesAcceptanceAndHidesIt(): void
    {
        $owner = $this->user('owner', 'super_admin', 'active'); $user = $this->user('inviteuser');
        $service = new InvitationService(db: $this->db); $code = $service->generatePlainCode();
        $id = $service->create($code, 'Multi-use expired', 3, null, $owner);
        $service->consume($code); $service->attachAcceptedUser($id, $user);
        $this->db->table('invitation_codes')->where('id', $id)->update(['expires_at' => '2020-01-01 00:00:00']);
        $this->assertTrue($service->archive($id, $owner));
        $this->assertFalse($service->consume($code));
        $this->assertSame(0, (new AdminService($this->db))->listInvitations()['pager']['total']);
        $this->seeInDatabase('invitation_codes', ['id' => $id, 'accepted_user_id' => $user, 'use_count' => 1]);
    }

    public function testActiveInvitationsCannotBeArchivedAndOrdinaryUsersCannotArchive(): void
    {
        $owner = $this->user('owner', 'super_admin', 'active'); $ordinary = $this->user('ordinary');
        $service = new InvitationService(db: $this->db); $id = $service->create($service->generatePlainCode(), 'Active', 1, null, $owner);
        $this->assertFalse($service->archive($id, $owner));
        $this->expectException(DomainException::class); $service->archive($id, $ordinary);
    }

    public function testNotificationResponsesExposeOnlyRelevantRecipientEmail(): void
    {
        $one = $this->user('one'); $two = $this->user('two'); $service = new NotificationService($this->db);
        $service->createForUser($one, 'announcement', 'Notice from owner@example.test', 'one@example.test two@example.test owner@example.test');
        $service->createForUser($two, 'announcement', 'Other notice', 'Private');
        $rows = $service->listForUser($one)['items']; $json = json_encode($rows);
        $this->assertCount(1, $rows); $this->assertStringContainsString('one@example.test', $json);
        $this->assertStringNotContainsString('owner@example.test', $json); $this->assertStringNotContainsString('two@example.test', $json);
        $this->assertArrayNotHasKey('recipient_email', $rows[0]);
    }

    public function testPublishedBroadcastPreservesRecipientPrivacyInNotificationsAndEmailQueue(): void
    {
        $owner = $this->user('owner', 'super_admin', 'active');
        $one = $this->user('one', 'user', 'active'); $two = $this->user('two', 'user', 'active');
        (new UserProfileModel())->insert(['user_id' => $one, 'email_announcements' => 1]);
        (new UserProfileModel())->insert(['user_id' => $two, 'email_announcements' => 1]);
        $service = new NotificationService($this->db);
        $announcement = $service->createAnnouncement($owner, ['title' => 'Notice', 'body' => 'owner@example.test one@example.test two@example.test', 'channels' => 'notification,email', 'target_type' => 'all']);
        $service->publishAnnouncement($owner, (int) $announcement['id']);
        $json = json_encode($service->listForUser($one)['items']);
        $this->assertStringNotContainsString('owner@example.test', $json); $this->assertStringNotContainsString('two@example.test', $json);
        $mail = $this->db->table('email_queue')->where('user_id', $one)->where('event_type', 'announcement')->get()->getRowArray();
        $this->assertSame('one@example.test', $mail['recipient_email']);
        $this->assertStringNotContainsString('owner@example.test', $mail['body_text']); $this->assertStringNotContainsString('two@example.test', $mail['body_text']);
    }
}
