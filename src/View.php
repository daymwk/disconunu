<?php
declare(strict_types=1);

namespace DN;

/** Rendu de vues PHP dans le thème actif (fallback : thème "teal"). */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): void
    {
        $theme = self::themeDir();
        $file  = $theme . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vue introuvable : {$template} (thème " . App::config()['app']['theme'] . ')');
        }

        extract($data, EXTR_SKIP);
        /** @var array<string,mixed> $__ */
        $__ = [
            'appName'    => App::config()['app']['name'],
            'subtitle'   => App::config()['app']['subtitle'],
            'baseUrl'    => App::baseUrl(),
            'csrf'       => Security::csrfToken(),
            'flashes'    => Http::takeFlash(),
            'user'       => Auth::user(),
            'themeUrl'   => App::baseUrl() . '/themes/' . rawurlencode(App::config()['app']['theme']),
        ];
        extract($__);

        // le template produit $content, le layout l'habille
        ob_start();
        require $file;
        $content = (string)ob_get_clean();
        require $theme . '/layout.php';
    }

    /** @return array<string,string> titre/url pour la vue interne (via capture) */
    public static function partial(string $template, array $data = []): string
    {
        $theme = self::themeDir();
        $file  = $theme . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Partiel introuvable : {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    private static function themeDir(): string
    {
        $theme = (string)App::config()['app']['theme'];
        $dir   = realpath(__DIR__ . '/../themes/' . $theme);
        $root  = realpath(__DIR__ . '/../themes');
        // anti traversal : le thème doit vivre sous themes/
        if ($dir === false || $root === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            $dir = (string)realpath(__DIR__ . '/../themes/teal');
        }
        return $dir;
    }
}
