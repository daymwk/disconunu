<?php
declare(strict_types=1);

namespace DN;

/** CSRF protection (per-session tokens), output escaping, HTML sanitizing. */
final class Security
{
    public static function csrfToken(): string
    {
        App::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        App::startSession();
        return is_string($token) && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    public static function rotateCsrf(): void
    {
        App::startSession();
        unset($_SESSION['csrf']);
    }

    /** Output escaping — every variable printed in a template goes through here. */
    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Nettoyage du contenu riche des posts : allowlist de balises, strip des
     * on* event handlers, javascript: URLs, tracking attributes. (HTML Purifier would be
     * ideal; this strict allowlist is the reasonable no-dependency baseline.)
     */
    public static function sanitizeHtml(string $html): string
    {
        $allowed = '<p><br><b><strong><i><em><u><s><a><ul><ol><li><blockquote><pre><code>'
                 . '<h2><h3><h4><h5><h6><img><figure><figcaption><table><thead><tbody><tr><th><td><hr>';
        $html = strip_tags($html, $allowed);

        // strip dangerous attributes (on* handlers, expression() styles, javascript: URLs)
        $html = preg_replace_callback(
            '#<(\w+)\b([^>]*)>#i',
            static function (array $m): string {
                [$tagFull, $tag, $attrs] = $m;
                $clean = [];
                if (preg_match_all('/(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $attrs, $am, PREG_SET_ORDER)) {
                    foreach ($am as $a) {
                        $name  = strtolower($a[1]);
                        $value = $a[3] !== '' ? $a[3] : ($a[4] !== '' ? $a[4] : ($a[5] ?? ''));
                        if (str_starts_with($name, 'on')) {
                            continue;
                        }
                        if ($name === 'href' || $name === 'src') {
                            $v = strtolower(str_replace(["\t", "\n", "\r"], '', $value));
                            $v = preg_replace('/\s+/', '', $v) ?? '';
                            if (preg_match('/^(javascript|vbscript|data):/i', ltrim($v))) {
                                continue;
                            }
                        }
                        if ($name === 'style') {
                            continue; // trop de vecteurs pour un CMS minimaliste
                        }
                        $clean[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
                    }
                }
                $selfClose = str_ends_with($tagFull, '/>') ? ' /' : '';
                return '<' . $tag . ($clean ? ' ' . implode(' ', $clean) : '') . $selfClose . '>';
            },
            $html
        ) ?? '';

        return $html;
    }

    /** Extrait un extrait texte brut safe (extraits de liste, meta description). */
    public static function excerpt(string $html, int $max = 280): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max - 1) . '…';
        }
        return $text;
    }
}
