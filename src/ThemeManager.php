<?php
declare(strict_types=1);

namespace DN;

/** PHP themes are trusted executable code, not sandboxed presentation assets. */
final class ThemeManager
{
    /** @return list<string> installed theme names (contain layout.php) */
    public static function available(): array
    {
        $root = App::rootDir() . '/themes';
        $out = [];
        foreach (glob($root . '/*/layout.php') ?: [] as $f) {
            $name = basename(dirname($f));
            if (preg_match('/^[a-zA-Z0-9_-]+$/D', $name) && !is_link(dirname($f)) && !is_link($f) && is_file($f)) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    public static function exists(string $name): bool
    {
        if (!in_array($name, self::available(), true)) return false;
        try {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                App::rootDir() . '/themes/' . $name, \FilesystemIterator::SKIP_DOTS
            ));
            foreach ($files as $file) {
                if ($file->isLink()) return false;
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') self::lintPhp($file->getPathname());
            }
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /** @throws \RuntimeException on invalid archives; returns the installed name. */
    public static function installFromZip(string $tmpZipPath): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is not available on this server.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmpZipPath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Not a valid zip archive.');
        }
        $stage = null;
        $reserved = null;
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 1000) {
                throw new \RuntimeException('Theme archives must contain 1–1000 entries.');
            }
            $entries = [];
            $layouts = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new \RuntimeException('Could not inspect archive entry.');
                }
                $entry = $stat['name'];
                $path = rtrim($entry, '/');
                $parts = explode('/', $path);
                if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\:]/', $entry)
                    || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)
                    || isset($entries[$path])) {
                    throw new \RuntimeException('Unsafe or duplicate archive path.');
                }
                $opsys = $attrs = 0;
                if (!$zip->getExternalAttributesIndex($i, $opsys, $attrs)) {
                    throw new \RuntimeException('Could not inspect archive file type.');
                }
                $type = ($attrs >> 16) & 0170000;
                if ($opsys === \ZipArchive::OPSYS_UNIX && !in_array($type, [0, 0100000, 0040000], true)) {
                    throw new \RuntimeException('Symlinks and special files are not allowed in themes.');
                }
                $size = $stat['size'];
                $total += $size;
                if ($size < 0 || $size > 2 * 1024 * 1024 || $total > 20 * 1024 * 1024) {
                    throw new \RuntimeException('Theme exceeds the 2 MiB file or 20 MiB total size limit.');
                }
                $directory = str_ends_with($entry, '/');
                if ($type === 0040000 && !$directory) {
                    throw new \RuntimeException('Inconsistent archive directory entry.');
                }
                $entries[$path] = ['index' => $i, 'size' => $size, 'directory' => $directory];
                if (!$directory && ($path === 'layout.php' || preg_match('#^[A-Za-z0-9._-]+/layout\.php$#D', $path))) {
                    $layouts[] = $path;
                }
            }
            if (count($layouts) !== 1) {
                throw new \RuntimeException('Exactly one theme root containing layout.php is required.');
            }
            $prefix = $layouts[0] === 'layout.php' ? '' : dirname($layouts[0]) . '/';
            foreach ($entries as $path => $meta) {
                $path = (string)$path;
                if ($prefix !== '' && !str_starts_with($path, $prefix)
                    && !($meta['directory'] && $path === rtrim($prefix, '/'))) {
                    throw new \RuntimeException('Archive contains files outside its theme root.');
                }
                for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                    if (isset($entries[$parent]) && !$entries[$parent]['directory']) {
                        throw new \RuntimeException('Archive contains conflicting file and directory paths.');
                    }
                }
            }

            $themes = App::rootDir() . '/themes';
            $stage = App::rootDir() . '/var/.theme-install-' . bin2hex(random_bytes(12));
            if (!mkdir($stage, 0700, true)) {
                throw new \RuntimeException('Could not create theme staging directory.');
            }
            $allowed = ['php', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'ico'];
            foreach ($entries as $path => $meta) {
                $path = (string)$path;
                $rel = substr($path, strlen($prefix));
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if ($meta['directory'] || !in_array($ext, $allowed, true)) {
                    continue;
                }
                $dest = $stage . '/' . $rel;
                if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) {
                    throw new \RuntimeException('Could not create template directory.');
                }
                // Cap decompression too, rather than trusting ZIP metadata alone.
                $data = $zip->getFromIndex($meta['index'], 2 * 1024 * 1024 + 1);
                if ($data === false || strlen($data) !== $meta['size'] || file_put_contents($dest, $data) !== strlen($data)) {
                    throw new \RuntimeException('Could not extract complete theme file.');
                }
                if ($ext === 'php') self::lintPhp($dest);
            }
            if (!is_file($stage . '/layout.php')) {
                throw new \RuntimeException('Theme archive contained no usable layout.php.');
            }
            $base = $prefix !== '' ? rtrim($prefix, '/') : pathinfo(basename($tmpZipPath), PATHINFO_FILENAME);
            $base = preg_replace('/[^a-zA-Z0-9_-]/', '-', $base) ?: 'theme';
            $name = $base;
            for ($suffix = 2; ; $suffix++) {
                $dest = $themes . '/' . $name;
                // Reserve atomically: never overwrite even an empty or invalid existing directory.
                if (@mkdir($dest, 0755)) {
                    $reserved = $dest;
                    break;
                }
                if (!file_exists($dest) && !is_link($dest)) {
                    throw new \RuntimeException('Could not reserve theme directory.');
                }
                $name = $base . '-' . $suffix;
            }
            if (!chmod($stage, 0755) || !rename($stage, $reserved)) {
                throw new \RuntimeException('Could not publish validated theme.');
            }
            $stage = $reserved = null;
            return $name;
        } finally {
            $zip->close();
            if ($stage !== null && is_dir($stage)) self::rrmdir($stage);
            if ($reserved !== null && is_dir($reserved)) self::rrmdir($reserved);
        }
    }

    private static function phpCliBinary(string $sapi): string
    {
        // Under FPM PHP_BINARY points to php-fpm, which cannot lint source files.
        $binary = in_array($sapi, ['cli', 'cli-server'], true) ? PHP_BINARY : PHP_BINDIR . '/php';
        if (!is_executable($binary)) {
            throw new \RuntimeException('A PHP CLI executable is required for theme syntax validation.');
        }
        return $binary;
    }

    /** Syntax checking never executes the template or its includes. */
    private static function lintPhp(string $file): void
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('PHP syntax validation requires proc_open.');
        }
        $process = proc_open([self::phpCliBinary(PHP_SAPI), '-n', '-l', $file], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start PHP syntax validation.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Invalid PHP syntax in theme file: ' . basename($file));
        }
    }

    public static function removeTheme(string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return false;
        }
        $path = App::rootDir() . '/themes/' . $name;
        if (is_link($path)) return false;
        $dir = realpath($path);
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
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
