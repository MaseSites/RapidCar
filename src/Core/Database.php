<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO-Wrapper mit zwei Treibern:
 *  - mysql  → Produktion (Hosttime / Shared Hosting)
 *  - sqlite → lokale Entwicklung und Demo ohne MySQL-Server
 *
 * Ausschliesslich Prepared Statements, keine Query-Interpolation.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return (string) Config::get('db.driver', 'mysql');
    }

    private static function connect(): PDO
    {
        $driver = self::driver();

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Antwortet der Datenbankserver nicht, soll die Seite nach zehn
            // Sekunden mit einem Fehler enden statt endlos zu haengen.
            PDO::ATTR_TIMEOUT            => 10,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = (string) Config::get('db.sqlite_path', BASE_PATH . '/storage/database.sqlite');
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA journal_mode = WAL');
                return $pdo;
            }

            $host = (string) Config::get('db.host', 'localhost');
            $port = (string) Config::get('db.port', '3306');
            $name = (string) Config::get('db.name', '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            return new PDO(
                $dsn,
                (string) Config::get('db.user', ''),
                (string) Config::get('db.password', ''),
                $options
            );
        } catch (PDOException $e) {
            Logger::error('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen.', 0, $e);
        }
    }

    /**
     * Prepared Statement ausführen.
     *
     * @param array<int|string, mixed> $params
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string, mixed> $params */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        return self::run($sql, $params)->fetchColumn();
    }

    /**
     * INSERT mit benannten Spalten; gibt die neue ID zurück.
     *
     * @param array<string, mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        self::run($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * UPDATE per Primärschlüssel.
     *
     * @param array<string, mixed> $data
     */
    public static function update(string $table, int $id, array $data): void
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = :' . $column;
        }
        $data['_id'] = $id;
        $sql = sprintf('UPDATE %s SET %s WHERE id = :_id', $table, implode(', ', $sets));
        self::run($sql, $data);
    }

    /** Aktueller Zeitstempel als SQL-kompatible Zeichenkette. */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }
}
