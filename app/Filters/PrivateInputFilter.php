<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class PrivateInputFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, private');
        if (in_array(trim($request->getUri()->getPath(), '/'), ['health', 'ready'], true)) {
            return null;
        }
        $response->setHeader('X-CSRF-TOKEN', csrf_hash());
        if ($request->getHeaderLine('X-Cloud-Frame') === '1' && str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            $body = $response->getBody();
            if (str_contains($body, 'id="cloud-shell"')) {
                $body = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $body);
                $body = preg_replace_callback('~<head>.*?</head>~is', static function ($match) {
                    preg_match('~<title>.*?</title>~is', $match[0], $title);
                    return '<head>' . ($title[0] ?? '') . '</head>';
                }, $body);
                $response->setBody($body);
                $response->setHeader('Vary', 'X-Cloud-Frame');
            }
        }
        $session = session();
        $input = $session->get('_ci_old_input');
        if (is_array($input)) {
            $session->set('_ci_old_input', $this->sanitize($input));
        }
        return null;
    }

    private function sanitize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (preg_match('/password|secret|token|csrf|invitation_code|authorization/i', (string) $key)) {
                unset($input[$key]);
            } elseif (is_array($value)) {
                $input[$key] = $this->sanitize($value);
            }
        }
        return $input;
    }
}
