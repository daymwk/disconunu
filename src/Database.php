<?php
declare(strict_types=1);

namespace DN;

use PDO;
use RuntimeException;

/**
 * Couche DB multi-provider : sqlite (défaut), mysql, pgsql.
 * Les modèles n'écrivent que du SQL compatible (typeNormal() traduit les types DDL).
 */
final class Database
{
    private static ?self $instance = null;
    private static ?\PDO $pdo = null;

    /** @param array<string,mixed> $cfg */
    public function __construct(private readonly array $cfg)
    {
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(App::config()['db']);
        }
        return self::$instance;
    }

    /** Test uniquement : réinitialise la connexion (base fraîche entre tests). */
    public static function resetConnection(): void
    {
        self::$pdo = null;
    }

    public function driver(): string
    {
        return (string)$this->cfg['driver'];
    }

    public function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $d   = $this->driver();
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        self::$pdo = match ($d) {
            'sqlite' => (function () use ($opt): PDO {
                $dir = dirname((string)$this->cfg['database']);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                return new PDO('sqlite:' . $this->cfg['database'], null, null, $opt);
            })(),
            'mysql'  => new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $this->cfg['host'], $this->cfg['port'] ?: 3306, $this->cfg['database']),
                (string)$this->cfg['user'], (string)$this->cfg['password'], $opt),
            'pgsql'  => new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s',
                    $this->cfg['host'], $this->cfg['port'] ?: 5432, $this->cfg['database']),
                (string)$this->cfg['user'], (string)$this->cfg['password'], $opt),
            default  => throw new RuntimeException("Driver DB inconnu : {$d}"),
        };

        if ($d === 'sqlite') {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
        }
        return self::$pdo;
    }

    /** Type SQL générique -> dialecte local (migrations). */
    public function type(string $generic): string
    {
        return match (true) {
            $generic === 'pk' => match ($this->driver()) {
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'mysql'  => 'INT AUTO_INCREMENT PRIMARY KEY',
                'pgsql'  => 'BIGSERIAL PRIMARY KEY',
            },
            $generic === 'ts' => match ($this->driver()) {
                'sqlite' => "TEXT",                          // ISO 8601 UTC
                'mysql'  => "DATETIME",
                'pgsql'  => "TIMESTAMPTZ",
            },
            default => $generic,
        };
    }

    /** Horodatage canonique, identique sur les 3 dialectes. */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
