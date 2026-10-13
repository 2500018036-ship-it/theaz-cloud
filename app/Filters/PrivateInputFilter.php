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
