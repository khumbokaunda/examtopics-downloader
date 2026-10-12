<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Thin PDO wrapper: prepared statements only, exceptions on error, UTC session time. */
final class Database
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function connect(Config $config): self
    {
        $pdo = new PDO(
            (string) $config->get('db.dsn'),
            (string) $config->get('db.user'),
            (string) $config->get('db.password'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
        // All DATETIME columns are UTC; PHP writes gmdate() values.
        $pdo->exec("SET time_zone = '+00:00'");
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** True when the exception is a duplicate-key violation (MySQL 1062). */
    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof \PDOException
            && (($e->errorInfo[1] ?? null) === 1062 || str_contains($e->getMessage(), 'Duplicate entry'));
    }
}
