<?php
namespace KutSocial;

class I18n {
    private static ?string $currentLang = null;
    private static ?array $strings = null;

    /**
     * Detect or get current language ('es' or 'en').
     */
    public static function getLang(): string {
        if (self::$currentLang !== null) {
            return self::$currentLang;
        }

        // 1. Explicit query param (persists to session and cookie)
        if (!empty($_GET['lang']) && in_array($_GET['lang'], ['es', 'en'], true)) {
            self::setLang($_GET['lang']);
            return self::$currentLang;
        }

        // 2. Session
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['kutsocial_lang']) && in_array($_SESSION['kutsocial_lang'], ['es', 'en'], true)) {
            self::$currentLang = $_SESSION['kutsocial_lang'];
            return self::$currentLang;
        }

        // 3. Cookie
        if (!empty($_COOKIE['kutsocial_lang']) && in_array($_COOKIE['kutsocial_lang'], ['es', 'en'], true)) {
            self::$currentLang = $_COOKIE['kutsocial_lang'];
            return self::$currentLang;
        }

        // 4. Stored default in database options table
        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT value FROM options WHERE key = 'site_language' LIMIT 1");
            $stmt->execute();
            $stored = $stmt->fetchColumn();
            if ($stored && in_array($stored, ['es', 'en'], true)) {
                self::$currentLang = $stored;
                return self::$currentLang;
            }
        } catch (\Throwable $e) {}

        // 5. Browser Accept-Language header
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($accept) {
            $esPos = stripos($accept, 'es');
            $enPos = stripos($accept, 'en');
            if ($enPos !== false && ($esPos === false || $enPos < $esPos)) {
                self::$currentLang = 'en';
                return self::$currentLang;
            }
        }

        self::$currentLang = 'es'; // Predeterminado
        return self::$currentLang;
    }

    /**
     * Set current language and persist in session and cookie.
     */
    public static function setLang(string $lang): void {
        if (!in_array($lang, ['es', 'en'], true)) {
            $lang = 'es';
        }
        self::$currentLang = $lang;
        self::$strings = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['kutsocial_lang'] = $lang;
        }

        if (!headers_sent()) {
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            setcookie('kutsocial_lang', $lang, [
                'expires'  => time() + 31536000, // 1 year
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => false,
                'samesite' => 'Lax'
            ]);
        }
    }

    /**
     * Load strings dictionary.
     */
    public static function loadStrings(): array {
        if (self::$strings !== null) {
            return self::$strings;
        }

        $lang = self::getLang();
        $file = __DIR__ . "/lang/{$lang}.php";
        $strings = file_exists($file) ? require $file : [];

        // Fallback al español si falta alguna clave
        $fallbackFile = __DIR__ . "/lang/es.php";
        if ($lang !== 'es' && file_exists($fallbackFile)) {
            $fallback = require $fallbackFile;
            $strings = array_merge($fallback, $strings);
        }

        self::$strings = $strings;
        return self::$strings;
    }

    /**
     * Translate key with optional parameter replacements.
     */
    public static function t(string $key, string $fallback = '', array $replace = []): string {
        $strings = self::loadStrings();
        $text = $strings[$key] ?? ($fallback !== '' ? $fallback : $key);

        if (!empty($replace)) {
            foreach ($replace as $k => $v) {
                $text = str_replace(':' . $k, (string)$v, $text);
            }
        }

        return $text;
    }

    /**
     * Get all strings for JSON export to frontend JS.
     */
    public static function getAll(): array {
        return self::loadStrings();
    }
}

// Global functions for convenience in templates:
if (!function_exists('__')) {
    function __(string $key, string $fallback = '', array $replace = []): string {
        return htmlspecialchars(\KutSocial\I18n::t($key, $fallback, $replace), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('__raw')) {
    function __raw(string $key, string $fallback = '', array $replace = []): string {
        return \KutSocial\I18n::t($key, $fallback, $replace);
    }
}

