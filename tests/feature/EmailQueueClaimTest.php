<?php

use App\Libraries\NotificationService;
use App\Libraries\QueuedEmailDeliveryService;
use App\Models\EmailQueueModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

final class EmailQueueClaimTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';

    private function mail(bool $succeeds): array
    {
        $config = new \Config\Email();
        $config->fromEmail = 'sender@example.test';
        $mock = new class ($succeeds) extends \CodeIgniter\Email\Email {
            public int $calls = 0;
            public function __construct(private bool $succeeds) { parent::__construct(); }
            public function send($autoClear = true): bool { $this->calls++; return $this->succeeds; }
        };
        Services::injectMock('email', $mock);
        $id = (new NotificationService($this->db))->queueEmail(null, 'recipient@gmail.com', 'test', null, 'Delivery test', 'Test body');
        return [new QueuedEmailDeliveryService(new EmailQueueModel(), $config), $mock, (new EmailQueueModel())->find($id)];
    }

    public function testAClaimedMessageIsSentOnlyOnce(): void
    {
        [$delivery, $mail, $row] = $this->mail(true);
        $method = new ReflectionMethod($delivery, 'deliver');
        $this->assertTrue($method->invoke($delivery, $row, 5)['sent']);
        $this->assertFalse($method->invoke($delivery, $row, 5)['sent']);
        $this->assertSame(1, $mail->calls);
        $this->seeInDatabase('email_queue', ['id' => $row['id'], 'status' => 'sent', 'attempts' => 1]);
    }

    public function testTransportFailureIsDeferredAndDoesNotSendAgainBeforeDue(): void
    {
        [$delivery, $mail, $row] = $this->mail(false);
        $method = new ReflectionMethod($delivery, 'deliver');
        $this->assertFalse($method->invoke($delivery, $row, 5)['sent']);
        $this->assertFalse($method->invoke($delivery, $row, 5)['sent']);
        $this->assertSame(1, $mail->calls);
        $this->seeInDatabase('email_queue', ['id' => $row['id'], 'status' => 'queued', 'attempts' => 1]);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), (new EmailQueueModel())->find($row['id'])['available_at']);
    }

    public function testReservedTestDomainsAreNeverSent(): void
    {
        [$delivery, $mail] = $this->mail(true);
        $id = (new NotificationService($this->db))->queueEmail(null, 'recipient@example.test', 'test', null, 'Test', 'Test');
        $result = (new ReflectionMethod($delivery, 'deliver'))->invoke($delivery, (new EmailQueueModel())->find($id), 5);
        $this->assertTrue($result['skipped']); $this->assertSame(0, $mail->calls);
        $this->seeInDatabase('email_queue', ['id' => $id, 'status' => 'cancelled']);
    }
}
