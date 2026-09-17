<?php
declare(strict_types=1);

namespace DN\Model;

use DN\App;
use DN\Database;

/** Site settings stored in DB, layered over config defaults. */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public const DEFAULTS = [
        'site_title'    => '',        // empty => config app.name
        'site_tagline'  => '',        // empty => config app.subtitle
        'footer_text'   => '',        // empty => default
        'posts_per_page' => '5',
        'rss_enabled'   => '1',
        'theme'         => '',        // empty => config theme
    ];

    /** @return array<string,string> */
    private static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                $rows = Database::get()->pdo()->query('SELECT k, v FROM dn_setting')->fetchAll();
                foreach ($rows as $r) {
                    self::$cache[(string)$r['k']] = (string)$r['v'];
                }
            } catch (\Throwable) {
                // table not migrated yet: keep the defaults
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $all = self::all();
        if (isset($all[$key]) && $all[$key] !== '') {
            return $all[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function int(string $key, int $min, int $max): int
    {
        $v = (int)self::get($key);
        if ($v < $min) {
            $v = $min;
        }
        if ($v > $max) {
            $v = $max;
        }
        return $v;
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function set(string $key, string $value): void
    {
        $db  = Database::get();
        $pdo = $db->pdo();
        $st = $pdo->prepare('SELECT 1 FROM dn_setting WHERE k = ?');
        $st->execute([$key]);
        if ($st->fetch() !== false) {
            $pdo->prepare('UPDATE dn_setting SET v = ? WHERE k = ?')->execute([$value, $key]);
        } else {
            $pdo->prepare('INSERT INTO dn_setting (k, v) VALUES (?,?)')->execute([$key, $value]);
        }
        self::$cache = null;
    }

    // ----- effective values (DB over config) -----

    public static function siteTitle(): string
    {
        return self::get('site_title') !== '' ? self::get('site_title') : (string)App::config()['app']['name'];
    }

    public static function siteTagline(): string
    {
        return self::get('site_tagline') !== '' ? self::get('site_tagline') : (string)App::config()['app']['subtitle'];
    }

    public static function footerText(): string
    {
        $t = self::get('footer_text');
        return $t !== '' ? $t : self::siteTitle() . ' — a minimal CMS, revived in 2026.';
    }

    public static function postsPerPage(): int
    {
        $cfg = (int)App::config()['blog']['posts_per_page'];
        return self::int('posts_per_page', 1, 50) ?: $cfg;
    }

    public static function rssEnabled(): bool
    {
        return self::bool('rss_enabled');
    }

    public static function theme(): string
    {
        $t = self::get('theme');
        return $t !== '' ? $t : (string)App::config()['app']['theme'];
    }
}
