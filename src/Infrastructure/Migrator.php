<?php

declare(strict_types=1);

namespace HrPayroll\Infrastructure;

use RuntimeException;

/**
 * Applies plain .sql files in filename order and records them in
 * schema_migrations. MySQL commits DDL implicitly, so each file is applied
 * statement by statement and a failed file must be fixed by hand.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /**
     * @return list<string> files applied in this run
     */
    public function migrate(): array
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $applied = array_column($this->db->fetchAll('SELECT version FROM schema_migrations'), 'version');

        $files = glob($this->directory . '/*.sql');
        if ($files === false) {
            throw new RuntimeException("Cannot read migrations from {$this->directory}.");
        }
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach ($this->statements((string) file_get_contents($file)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
            $ran[] = $version;
        }

        return $ran;
    }

    public function dropAllTables(): void
    {
        $tables = $this->db->fetchAll(
            'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db->pdo()->exec(sprintf('DROP TABLE `%s`', str_replace('`', '', (string) $table['name'])));
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Migration files contain no semicolons inside string literals, so a
     * plain split is sufficient.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        return array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $statement): bool => $statement !== '',
        ));
    }
}
