<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Infrastructure\Database;

final class HolidayRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForYear(?int $year): array
    {
        if ($year === null) {
            return $this->db->fetchAll('SELECT id, holiday_date, name FROM holidays ORDER BY holiday_date');
        }

        return $this->db->fetchAll(
            'SELECT id, holiday_date, name FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date',
            ["{$year}-01-01", "{$year}-12-31"],
        );
    }

    /**
     * @return list<string>
     */
    public function datesBetween(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            'SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date',
            [$from, $to],
        );

        return array_map(static fn (array $r): string => (string) $r['holiday_date'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT id, holiday_date, name FROM holidays WHERE id = ?', [$id]);
    }

    public function existsOn(string $date): bool
    {
        return $this->db->fetchOne('SELECT id FROM holidays WHERE holiday_date = ?', [$date]) !== null;
    }

    public function create(string $date, string $name): int
    {
        return $this->db->insert('holidays', ['holiday_date' => $date, 'name' => $name]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM holidays WHERE id = ?', [$id]);
    }
}
