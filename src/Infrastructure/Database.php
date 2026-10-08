<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Lazily connected PDO wrapper. transactional() can be nested: only the
 * outermost call opens and commits the transaction, so a service can call
 * another transactional service without breaking atomicity.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config->dbHost,
                $config->dbPort,
                $config->dbName,
            ),
            $config->dbUser,
            $config->dbPassword,
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->pdo->exec(
                "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'"
            );
        }

        return $this->pdo;
    }

    /**
     * @param array<string|int, scalar|null> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string|int, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, scalar|null> $params
     */
    public function fetchInt(string $sql, array $params = []): int
    {
        return (int) $this->run($sql, $params)->fetchColumn();
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );
        $this->run($sql, $values);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function ping(): bool
    {
        return $this->fetchInt('SELECT 1') === 1;
    }
}
