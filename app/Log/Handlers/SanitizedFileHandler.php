<?php

namespace App\Log\Handlers;

use CodeIgniter\Log\Handlers\FileHandler;

class SanitizedFileHandler extends FileHandler
{
    private const SENSITIVE_KEY_PATTERN = 'api[_-]?key|authorization|cookie|csrf|database[_-]?default[_-]?password|mariadb[_-]?password|mariadb[_-]?root[_-]?password|pass(?:word)?|pwd|secret|session|token';

    public function handle($level, $message): bool
    {
        return parent::handle($level, $this->sanitize((string) $message));
    }

    private function sanitize(string $message): string
    {
        $patterns = [
            '/((?:' . self::SENSITIVE_KEY_PATTERN . ')\s*[=:]\s*)([^\s,\]}]+)/i',
            '/("?(?:' . self::SENSITIVE_KEY_PATTERN . ')"?\s*=>\s*)([^\r\n]+)/i',
            '/("?(?:' . self::SENSITIVE_KEY_PATTERN . ')"?\s*:\s*)"[^"]*"/i',
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
        ];

        $replacements = [
            '$1[REDACTED]',
            '$1[REDACTED]',
            '$1"[REDACTED]"',
            '$1[REDACTED]',
        ];

        return preg_replace($patterns, $replacements, $message) ?? '[REDACTED_LOG_MESSAGE]';
    }
}
