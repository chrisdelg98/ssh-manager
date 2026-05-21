<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal .env loader.
 *
 * Reads KEY=VALUE pairs into a private static cache.
 * Deliberately does NOT push values into $_ENV, $_SERVER or putenv()
 * to keep secrets out of phpinfo() and getenv() exposure surface.
 *
 * Supported syntax:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='value'
 *   # full-line comment
 *   KEY=value # trailing comment (only when value is unquoted)
 */
class Env
{
    private static array $values = [];
    private static bool  $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                'Missing or unreadable .env file. Copy .env.example to .env and fill in your values.'
            );
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Failed to read .env file');
        }

        foreach ($lines as $raw) {
            $line = ltrim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));

            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            $val = self::parseValue($val);
            self::$values[$key] = $val;
        }

        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function required(string $key): string
    {
        if (!array_key_exists($key, self::$values) || self::$values[$key] === '') {
            // Do NOT include $key in the user-facing message for production safety,
            // but error_log it for the operator.
            error_log("[Env] Missing required key: $key");
            throw new \RuntimeException('Configuration error.');
        }
        return self::$values[$key];
    }

    public static function int(string $key, int $default = 0): int
    {
        return array_key_exists($key, self::$values) ? (int)self::$values[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, self::$values)) {
            return $default;
        }
        $v = strtolower(self::$values[$key]);
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** Parse "a, b ,c" → ['a','b','c']. Empty → []. */
    public static function arrayCsv(string $key): array
    {
        $raw = self::$values[$key] ?? '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    }

    /** Whether the loader has read a file. */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    private static function parseValue(string $val): string
    {
        if ($val === '') {
            return '';
        }

        $first = $val[0];

        // Quoted value — strip matching outer quotes verbatim, no comment stripping
        if (($first === '"' || $first === "'") && substr($val, -1) === $first) {
            return substr($val, 1, -1);
        }

        // Unquoted — strip trailing inline comment ( space then # )
        $hash = strpos($val, ' #');
        if ($hash !== false) {
            $val = rtrim(substr($val, 0, $hash));
        }

        return $val;
    }
}
