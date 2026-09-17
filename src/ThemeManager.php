<?php
declare(strict_types=1);

namespace DN;

/**
 * Theme manager: a theme = a folder under themes/ containing layout.php (+ templates + css).
 * Themes can be installed from a .zip upload (superadmin), validated against traversal bombs
 * and layout.php presence before landing in themes/.
 */
final class ThemeManager
{
    /** @return list<string> installed theme names (contain layout.php) */
    public static function available(): array
    {
        $root = App::rootDir() . '/themes';
        $out  = [];
        foreach (glob($root . '/*/layout.php') ?: [] as $f) {
            $out[] = basename(dirname($f));
        }
        sort($out);
        return $out;
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::available(), true);
    }

    /**
     * Install a theme from an uploaded zip. Safe against zip-slip (path traversal).
     * @return string theme name on success
     * @throws \RuntimeException on invalid archive
     */
    public static function installFromZip(string $tmpZipPath): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is not available on this server.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpZipPath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Not a valid zip archive.');
        }

        // 1) find layout.php at depth <= 1 (theme root, or wrapped in exactly one folder)
        $layoutEntry = null;
        $prefix = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'layout.php') {
                $layoutEntry = $name;
                $prefix = '';
                break;
            }
            if (preg_match('#^([A-Za-z0-9._-]+)/layout\.php$#', $name, $m)) {
                $layoutEntry = $name;
                $prefix = $m[1] . '/';
                break;
            }
        }
        if ($layoutEntry === null) {
            $zip->close();
            throw new \RuntimeException('No layout.php found: not a Disco nunu theme.');
        }

        // Name = the zip's single top-level folder, else the archive file name.
        if ($prefix !== '') {
            $name = basename(rtrim($prefix, '/'));
        } else {
            $name = pathinfo(basename($tmpZipPath), PATHINFO_FILENAME); // e.g. "mytheme.zip" -> "mytheme"
        }
        if ($name === '' || $name === '.' || $name === '/') {
            $name = 'theme-' . substr(hash('sha256', (string)file_get_contents($tmpZipPath)), 0, 8);
        }
        // sanitize + collision
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name) ?? 'theme';
        $base = $name;
        for ($i = 2; self::exists($name); $i++) {
            $name = $base . '-' . $i;
        }

        $destRoot = App::rootDir() . '/themes/' . $name;
        if (!mkdir($destRoot, 0775, true)) {
            $zip->close();
            throw new \RuntimeException('Could not create theme directory.');
        }

        // 2) extract whitelisted files only, no traversal, size caps
        $allowed = ['php', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'ico'];
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (str_ends_with($entry, '/')) {
                continue; // directory entry
            }
            if (str_contains($entry, '..') || str_starts_with($entry, '/') || preg_match('#^[a-zA-Z]:#', $entry)) {
                continue; // zip-slip attempt: skip silently
            }
            if ($prefix !== '' && !str_starts_with($entry, $prefix)) {
                continue; // outside the theme root folder
            }
            $rel  = $prefix !== '' ? substr($entry, strlen($prefix)) : $entry;
            $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }
            $stat = $zip->statName($entry);
            if (is_array($stat) && ($stat['size'] ?? 0) > 2 * 1024 * 1024) {
                continue; // >2 Mo par fichier : skip
            }
            $dest = $destRoot . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                file_put_contents($dest, $data);
                $count++;
            }
        }
        $zip->close();

        if ($count === 0 || !is_file($destRoot . '/layout.php')) {
            // rollback: empty or unusable theme
            self::removeTheme($name);
            throw new \RuntimeException('Theme archive contained no usable files.');
        }
        return $name;
    }

    public static function removeTheme(string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return false;
        }
        $dir = realpath(App::rootDir() . '/themes/' . $name);
        $root = realpath(App::rootDir() . '/themes');
        if ($dir === false || $root === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            return false;
        }
        self::rrmdir($dir);
        return true;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
