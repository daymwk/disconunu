<?php
declare(strict_types=1);

namespace DN;

use DN\Model\Settings;

/** Renders PHP views from the active theme (Settings::theme(), fallback handled at boot). */
final class View
{
    public const FALLBACK_THEME = 'teal';

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        $file    = self::resolve($template);
        $layout  = self::resolve('layout');

        extract($data, EXTR_SKIP);
        /** @var array<string,mixed> $__ */
        $__ = [
            'appName'    => Settings::siteTitle(),
            'subtitle'   => Settings::siteTagline(),
            'baseUrl'    => App::baseUrl(),
            'csrf'       => Security::csrfToken(),
            'flashes'    => Http::takeFlash(),
            'user'       => Auth::user(),
            'themeUrl'   => App::baseUrl() . '/themes/' . rawurlencode(Settings::theme()),
            'fallbackThemeUrl' => App::baseUrl() . '/themes/' . rawurlencode(self::FALLBACK_THEME),
            'logoUrl'    => self::logoUrl(),
            'rssEnabled' => Settings::rssEnabled(),
        ];
        extract($__);

        // the template produces $content, the layout wraps it
        ob_start();
        require $file;
        $content = (string)ob_get_clean();
        require $layout;
    }

    /**
     * Path of a template in the active theme, falling back to the bundled
     * "teal" theme for templates the theme does not override. A theme can
     * therefore ship a single layout.php (or a handful of overrides) instead of
     * a full copy — and a partial theme never turns a page into a 500.
     */
    private static function resolve(string $template): string
    {
        $file = self::themeDir() . '/' . $template . '.php';
        if (is_file($file)) {
            return $file;
        }
        $fallback = self::fallbackDir() . '/' . $template . '.php';
        if (is_file($fallback)) {
            return $fallback;
        }
        throw new \RuntimeException(
            "View not found: {$template} (theme '" . Settings::theme() . "' and the fallback theme provide none)"
        );
    }

    /** Logo: admin-uploaded override (var/uploads) wins over the theme default. */
    public static function logoUrl(): ?string
    {
        foreach (['logo.jpg', 'logo.png', 'logo.svg', 'logo.webp'] as $f) {
            if (is_file(App::rootDir() . '/var/uploads/' . $f)) {
                return App::baseUrl() . '/uploads/' . $f;
            }
        }
        $themeLogo = self::themeDir() . '/images/logo.jpg';
        return is_file($themeLogo) ? App::baseUrl() . '/themes/' . rawurlencode(Settings::theme()) . '/images/logo.jpg' : null;
    }

    /**
     * Windowed pagination markup: « 1 … 4 [5] 6 … 20 » — never overflows on many pages.
     */
    public static function pagination(int $page, int $pages, string $urlPattern): string
    {
        if ($pages <= 1) {
            return '';
        }
        $window = 2;
        $pagesVisible = [];
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === 1 || $i === $pages || abs($i - $page) <= $window) {
                $pagesVisible[] = $i;
            }
        }
        $html = '<nav class="pagination" aria-label="Pagination"><div class="pagination-inner">';
        $prev = null;
        foreach ($pagesVisible as $i) {
            if ($prev !== null && $i - $prev > 1) {
                $html .= '<span class="pagination-gap">…</span>';
            }
            $url = str_replace('{page}', (string)$i, $urlPattern);
            $html .= $i === $page
                ? '<span class="current">' . $i . '</span>'
                : '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $i . '</a>';
            $prev = $i;
        }
        return $html . '</div></nav>';
    }

    private static function themeDir(): string
    {
        $theme = Settings::theme();
        $root  = realpath(__DIR__ . '/../themes');
        $dir   = ($theme !== '' && $root !== false) ? realpath($root . '/' . $theme) : false;
        // anti traversal: the active theme must live under themes/
        if ($dir === false || $root === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            return self::fallbackDir();
        }
        return $dir;
    }

    /** The bundled theme every install ships with: the per-template fallback. */
    private static function fallbackDir(): string
    {
        $root = realpath(__DIR__ . '/../themes');
        $dir  = $root !== false ? realpath($root . '/' . self::FALLBACK_THEME) : false;
        if ($dir === false) {
            throw new \RuntimeException('The bundled "' . self::FALLBACK_THEME . '" theme is missing.');
        }
        return $dir;
    }
}
