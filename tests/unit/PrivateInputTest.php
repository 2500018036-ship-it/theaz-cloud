<?php

use App\Filters\PrivateInputFilter;
use CodeIgniter\Test\CIUnitTestCase;

final class PrivateInputTest extends CIUnitTestCase
{
    public function testAsyncMutationsPreserveSafeListQueryAndRejectExternalContext(): void
    {
        $request = new \CodeIgniter\HTTP\Request(config(\Config\App::class));
        $request->setMethod('POST'); $request->getUri()->setPath('/admin/users/2/status');
        $request->setHeader('X-Cloud-Frame', '1');
        $request->setHeader('X-Cloud-Context', '/admin/users?q=alice&status=active&page=3&perPage=20&secret=ignored');
        $response = new \CodeIgniter\HTTP\Response(config(\Config\App::class));
        $response->setHeader('Location', 'http://example.com/admin/users');
        (new PrivateInputFilter())->after($request, $response);
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['q' => 'alice', 'status' => 'active', 'page' => '3', 'perPage' => '20'], $query);
        $request->setHeader('X-Cloud-Context', '//external.example/admin/users');
        $response->setHeader('Location', 'http://example.com/admin/users');
        (new PrivateInputFilter())->after($request, $response);
        $this->assertSame('http://example.com/admin/users', $response->getHeaderLine('Location'));
    }

    public function testLocalHealthChecksDoNotGenerateSecureCsrfCookies(): void
    {
        $cookie = config(\Config\Cookie::class);
        $originalSecure = $cookie->secure;
        $cookie->secure = true;
        $request = new \CodeIgniter\HTTP\Request(config(\Config\App::class));

        try {
            foreach (['/health', '/ready'] as $path) {
                $request->getUri()->setPath($path);
                $response = new \CodeIgniter\HTTP\Response(config(\Config\App::class));
                (new PrivateInputFilter())->after($request, $response);
                $this->assertFalse($response->hasHeader('X-CSRF-TOKEN'));
                $this->assertSame([], $response->getCookies());
                $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            }
        } finally {
            $cookie->secure = $originalSecure;
        }
    }

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
