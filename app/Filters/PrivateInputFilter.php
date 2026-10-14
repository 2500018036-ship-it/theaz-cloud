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
        $this->preserveContext($request, $response);
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

    private function preserveContext(RequestInterface $request, ResponseInterface $response): void
    {
        if (strtoupper($request->getMethod()) !== 'POST' || $request->getHeaderLine('X-Cloud-Frame') !== '1' || ! $response->hasHeader('Location')) return;
        $context = $request->getHeaderLine('X-Cloud-Context');
        if (strlen($context) > 2000 || ! str_starts_with($context, '/') || str_starts_with($context, '//') || preg_match('/[\r\n\\\\]/', $context)) return;
        $normalize = static fn (string $path): string => preg_replace('#^/index\.php(?:/|$)#', '/', $path);
        $path = $normalize((string) parse_url($context, PHP_URL_PATH));
        $destination = $normalize((string) parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
        $postPath = $normalize('/' . trim($request->getUri()->getPath(), '/'));
        $same = $path === $destination;
        $drivePages = ['drive', 'drive/recent', 'drive/starred', 'drive/trash', 'drive/shared-with-me', 'drive/shared-by-me'];
        $drive = in_array(trim($path, '/'), $drivePages, true) && in_array(trim($destination, '/'), $drivePages, true) && str_starts_with($postPath, '/drive/');
        $notice = preg_match('#^/notifications(?:/\d+)?$#', $path) && $destination === '/notifications'
            && preg_match('#^/notifications/(?:\d+/read|read-all)$#', $postPath);
        if (! $same && ! $drive && ! $notice) return;
        if (! preg_match('#^/(?:admin/(?:users(?:/\d+)?|invitations|announcements|settings|activity)|drive(?:/[a-z-]+)?|settings|notifications(?:/\d+)?)$#', $path)) return;
        parse_str((string) parse_url($context, PHP_URL_QUERY), $query);
        $allowed = ['q', 'status', 'role', 'page', 'perPage', 'sort', 'dir', 'view', 'folder', 'type', 'ext', 'date_from', 'date_to', 'category', 'action', 'size_min', 'size_max'];
        $query = array_filter(array_intersect_key($query, array_flip($allowed)), static fn ($value) => is_scalar($value) && strlen((string) $value) <= 200);
        $response->setHeader('Location', site_url(ltrim($path, '/')) . ($query === [] ? '' : '?' . http_build_query($query)));
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
