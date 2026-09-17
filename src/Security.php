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

    /** Parse untrusted rich text and rebuild only inert, explicitly allowed HTML. */
    public static function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadHTML(
                '<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        $tags = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'a', 'ul', 'ol', 'li',
            'blockquote', 'pre', 'code', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'figure',
            'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr'];
        // Never unwrap raw-text, foreign-content or interactive subtrees into HTML.
        $drop = ['head', 'script', 'style', 'svg', 'math', 'template', 'noscript', 'iframe',
            'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'title',
            'xmp', 'plaintext', 'noembed', 'noframes'];
        $render = static function (\DOMNode $node) use (&$render, $tags, $drop): string {
            if ($node instanceof \DOMText) {
                return self::e($node->nodeValue);
            }
            if (!$node instanceof \DOMElement && !$node instanceof \DOMDocument) {
                return '';
            }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, $drop, true)) {
                return '';
            }
            $body = '';
            foreach ($node->childNodes as $child) {
                $body .= $render($child);
            }
            if (!in_array($tag, $tags, true)) {
                return $body;
            }
            $allowedAttrs = match ($tag) {
                'a' => ['href', 'title'],
                'img' => ['src', 'alt', 'title', 'width', 'height'],
                'td', 'th' => ['colspan', 'rowspan'],
                default => [],
            };
            $attrs = '';
            foreach ($allowedAttrs as $name) {
                if (!$node->hasAttribute($name)) {
                    continue;
                }
                $value = $node->getAttribute($name);
                if ($name === 'href' || $name === 'src') {
                    // The parser has decoded entities; browsers ignore these controls in schemes.
                    $url = preg_replace('/[\x00-\x20\x7f]+/', '', $value) ?? '';
                    if (preg_match('/^(javascript|vbscript|data):/i', $url)) {
                        continue;
                    }
                }
                $attrs .= ' ' . $name . '="' . self::e($value) . '"';
            }
            return '<' . $tag . $attrs . '>'
                . (in_array($tag, ['br', 'img', 'hr'], true) ? '' : $body . '</' . $tag . '>');
        };
        return $render($doc);
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
