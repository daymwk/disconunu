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
        $isAdmin = str_starts_with($template, 'admin/');
        $layout  = self::resolve('layout', $isAdmin);

        extract($data, EXTR_SKIP);
        extract(self::vars());

        // the template produces $content, the layout wraps it
        ob_start();
        require $file;
        $content = (string)ob_get_clean();
        require $layout;
    }

    /**
     * Render a fragment inside the current template (e.g. the shared admin nav).
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        extract(self::vars(false));
        require self::resolve($template);
    }

    /**
     * Variables every template (and every fragment) can rely on.
     * @return array<string,mixed>
     */
    private static function vars(bool $consumeFlashes = true): array
    {
        return [
            'appName'    => Settings::siteTitle(),
            'subtitle'   => Settings::siteTagline(),
            'baseUrl'    => App::baseUrl(),
            'csrf'       => Security::csrfToken(),
            'flashes'    => $consumeFlashes ? Http::takeFlash() : [],
            'user'       => Auth::user(),
            'themeUrl'   => App::baseUrl() . '/themes/' . rawurlencode(Settings::theme()),
            'fallbackThemeUrl' => App::baseUrl() . '/themes/' . rawurlencode(self::FALLBACK_THEME),
            'logoUrl'    => self::logoUrl(),
            'rssEnabled' => Settings::rssEnabled(),
            'themes'     => ThemeManager::available(),
        ];
    }

    /**
     * Path of a template in the active theme, falling back to the bundled
     * "teal" theme for templates the theme does not override. A theme can
     * therefore ship a single layout.php (or a handful of overrides) instead of
     * a full copy — and a partial theme never turns a page into a 500.
     */
    private static function resolve(string $template, bool $bundled = false): string
    {
        $bundled = $bundled || str_starts_with($template, 'admin/');
        $file = ($bundled ? self::fallbackDir() : self::themeDir()) . '/' . $template . '.php';
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
        foreach (['logo.jpg', 'logo.png', 'logo.gif', 'logo.webp'] as $f) {
            if (is_file(App::rootDir() . '/var/uploads/' . $f)) {
                return App::baseUrl() . '/uploads/' . $f;
            }
        }
        foreach ([self::themeDir(), self::fallbackDir()] as $dir) {
            foreach (['logo.svg', 'logo.jpg'] as $f) {
                if (is_file($dir . '/images/' . $f)) {
                    return App::baseUrl() . '/themes/' . rawurlencode(basename($dir)) . '/images/' . $f;
                }
            }
        }
        return null;
    }

    /**
     * Windowed pagination markup (1 ... 4 [5] 6 ... 20) — never overflows on many pages,
     * however many pages a listing has.
     */
    public static function pagination(int $page, int $pages, string $urlPattern): string
    {
        if ($pages <= 1) {
            return '';
        }
        $page = max(1, min($page, $pages));
        $window = 2;
        // Generate only the window, not every page; avoid integer overflow at PHP_INT_MAX.
        $pagesVisible = array_unique(array_merge(
            [1, $pages],
            range(max(1, $page - $window), $page + min($window, $pages - $page))
        ));
        sort($pagesVisible, SORT_NUMERIC);
        $html = '<nav class="pagination" aria-label="Pagination"><div class="pagination-inner">';
        $prev = null;
        foreach ($pagesVisible as $i) {
            if ($prev !== null && $i - $prev > 1) {
                $html .= '<span class="pagination-gap">…</span>';
            }
            $url = str_replace('{page}', (string)$i, $urlPattern);
            $html .= $i === $page
                ? '<span class="current" aria-current="page">' . $i . '</span>'
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
