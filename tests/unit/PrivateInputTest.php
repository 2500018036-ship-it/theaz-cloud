<?php

use App\Filters\PrivateInputFilter;
use CodeIgniter\Test\CIUnitTestCase;

final class PrivateInputTest extends CIUnitTestCase
{
    public function testFailedFormInputPreservesNamesWithoutPersistingCredentials(): void
    {
        session()->setFlashdata('_ci_old_input', ['post' => [
            'username' => 'example', 'password' => 'sensitive-test-value',
            'password_confirm' => 'sensitive-test-value', 'invitation_code' => 'private-invite',
            'csrf_test_name' => 'csrf-value', 'nested' => ['new_password' => 'private', 'name' => 'kept'],
        ]]);
        $response = service('response');
        (new PrivateInputFilter())->after(service('request'), $response);
        $this->assertSame(['post' => ['username' => 'example', 'nested' => ['name' => 'kept']]], session()->get('_ci_old_input'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        session()->destroy();
    }
}
