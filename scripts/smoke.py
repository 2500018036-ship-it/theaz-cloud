#!/usr/bin/env python3
"""HTTP smoke test. Credentials stay in memory and a private container tmpfs file."""
import argparse
import hashlib
import html
import http.cookiejar
import json
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request


class Browser:
    def __init__(self, base):
        self.base = base.rstrip('/')
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, data=None, content_type=None):
        headers = {'User-Agent': 'Theaz-Cloud-Deployment-Smoke'}
        if content_type:
            headers['Content-Type'] = content_type
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(self.base + path, data=data, headers=headers)
        try:
            with self.opener.open(req, timeout=60) as response:
                return response.status, response.read(), response.headers, response.geturl()
        except urllib.error.HTTPError as error:
            return error.code, error.read(), error.headers, error.geturl()

    def post(self, path, values, token_path=None):
        body = self.request(token_path or path)[1].decode()
        values = dict(values)
        values['csrf_test_name'] = csrf(body)
        return self.request(path, values)


def csrf(body):
    match = re.search(r'name="csrf_test_name"[^>]*value="([^"]+)"', body)
    if not match:
        raise RuntimeError('CSRF token was not rendered.')
    return html.unescape(match.group(1))


def multipart(values, name, payload):
    boundary = secrets.token_hex(20)
    parts = []
    for key, value in values.items():
        parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + str(value) + '\r\n').encode())
    parts.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="files[]"; filename="' + name + '"\r\nContent-Type: text/plain\r\n\r\n').encode() + payload + b'\r\n')
    parts.append(('--' + boundary + '--\r\n').encode())
    return b''.join(parts), 'multipart/form-data; boundary=' + boundary


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--url', required=True)
    parser.add_argument('--compose-dir', required=True)
    parser.add_argument('--env', action='append', default=[])
    parser.add_argument('--file', default='compose.yaml')
    parser.add_argument('--overlay')
    parser.add_argument('--project')
    args = parser.parse_args()
    compose = ['docker', 'compose']
    for env in args.env:
        compose += ['--env-file', env]
    compose += ['-f', args.file]
    if args.overlay:
        compose += ['-f', args.overlay]
    if args.project:
        compose += ['-p', args.project]
    def cli(*command):
        result = subprocess.run(compose + ['exec', '-T', 'app'] + list(command), cwd=args.compose_dir, capture_output=True, check=True)
        return result.stdout
    fixture_path = '/tmp/cloud-smoke-' + secrets.token_hex(8) + '.json'
    cli('php', 'spark', 'ops:smoke-fixtures', '--output=' + fixture_path)
    fixtures = json.loads(cli('cat', fixture_path))
    try:
        anonymous = Browser(args.url)
        assert anonymous.request('/ready')[0] == 200, 'Readiness failed'
        assert anonymous.request('/health')[0] == 200, 'Liveness failed'
        assert anonymous.request('/.env')[0] in (403, 404), 'Environment exposed'
        assert anonymous.request('/composer.json')[0] in (403, 404), 'Project configuration exposed'
        assert anonymous.request('/login')[0] == 200, 'Login page failed'
        denied = anonymous.request('/login', {'identifier': fixtures['admin']['username'], 'password': fixtures['admin']['password']})
        assert '/admin/dashboard' not in denied[3], 'Login bypassed CSRF'
        clients = []
        for fixture in fixtures['users']:
            client = Browser(args.url)
            invalid = client.post('/register', {'invitation_code': 'INVALID-CODE-0000', 'username': fixture['username'], 'email': fixture['email'], 'password': fixture['password'], 'password_confirm': fixture['password']})
            assert b'Invitation code is invalid' in invalid[1], 'Invalid invite accepted'
            registered = client.post('/register', {'invitation_code': fixture['invite'], 'username': fixture['username'], 'email': fixture['email'], 'password': fixture['password'], 'password_confirm': fixture['password'], 'role': 'super_admin'})
            assert b'Account created' in registered[1], 'Invite registration failed'
            logged = client.post('/login', {'identifier': fixture['username'], 'password': fixture['password']})
            assert '/dashboard' in logged[3] or '/drive' in logged[3], 'User login failed'
            assert client.request('/admin/dashboard')[0] == 403, 'User obtained administrator access'
            clients.append(client)
        one, two = clients
        admin = Browser(args.url)
        logged = admin.post('/login', {'identifier': fixtures['admin']['username'], 'password': fixtures['admin']['password']})
        assert '/admin/dashboard' in logged[3] and logged[0] == 200, 'Admin dashboard/login failed'
        folder = one.post('/drive/folders', {'name': 'Deployment smoke folder'}, '/drive')
        assert b'Deployment smoke folder' in folder[1], 'Folder creation failed'
        payload = b'Theaz Cloud HTTPS upload/download integrity test.\n'
        page = one.request('/drive')[1].decode()
        body, mime = multipart({'csrf_test_name': csrf(page)}, 'deployment-smoke.txt', payload)
        uploaded = one.request('/drive/upload', body, mime)
        assert b'deployment-smoke.txt' in uploaded[1], 'Upload failed'
        ids = re.findall(r'/drive/items/(\d+)/download', uploaded[1].decode())
        assert ids, 'Download link missing'
        download = one.request('/drive/items/' + ids[0] + '/download')
        assert download[0] == 200 and hashlib.sha256(download[1]).digest() == hashlib.sha256(payload).digest(), 'Downloaded bytes differ'
        forbidden = two.request('/drive/items/' + ids[0] + '/download')
        assert forbidden[1] != payload, 'Another user downloaded private file'
        assert b'deployment-smoke.txt' not in two.request('/drive')[1], 'Another user listed private file'
        page = one.request('/drive')[1].decode()
        body, mime = multipart({'csrf_test_name': csrf(page)}, 'over-quota.txt', b'x' * 1048576)
        overquota = one.request('/drive/upload', body, mime)
        assert b'quota' in overquota[1].lower(), 'Quota enforcement failed'
        assert one.request('/drive/items/' + ids[0] + '/download')[1] == payload, 'Quota failure damaged existing file'
        if args.url.startswith('https://'):
            sessions = [cookie for cookie in one.cookies if cookie.name == 'ci_session']
            assert sessions and all(cookie.secure and 'HttpOnly' in cookie._rest for cookie in sessions), 'Session cookie flags missing'
        print('PASS: TLS/health, invite registration, CSRF, user/admin login, folder, upload, download SHA-256, user isolation, quota, session cookies')
    finally:
        cli('php', 'spark', 'ops:smoke-fixtures', '--output=' + fixture_path, '--finish')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('FAIL:', str(error) if isinstance(error, (AssertionError, RuntimeError)) else type(error).__name__)
        raise SystemExit(1)
