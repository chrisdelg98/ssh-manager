<?php
declare(strict_types=1);

namespace App;

class CsrfGuard
{
    private const TOKEN_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    public static function validate(?string $submitted): bool
    {
        if (empty($submitted) || empty($_SESSION[self::TOKEN_KEY])) {
            return false;
        }
        return hash_equals($_SESSION[self::TOKEN_KEY], $submitted);
    }

    /** Returns a hidden <input> field for embedding in forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }
}
