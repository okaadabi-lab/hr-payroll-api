<?php

declare(strict_types=1);

namespace HrPayroll\Http;

use HrPayroll\Domain\Exception\ValidationFailed;

/**
 * Parsed page, sort and filter parameters for list endpoints.
 * Sort fields are mapped through a whitelist to SQL column expressions, so no
 * client-supplied text ever reaches an ORDER BY clause.
 */
final class ListQuery
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /**
     * @param list<array{0: string, 1: 'ASC'|'DESC'}> $orderBy SQL expressions from the whitelist
     * @param array<string, string>                   $filters
     */
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $orderBy,
        public readonly array $filters,
    ) {
    }

    /**
     * @param array<string, mixed>  $params        raw query parameters
     * @param array<string, string> $sortable      public sort name => SQL expression
     * @param list<string>          $filterNames   accepted filter parameters
     */
    public static function fromParams(array $params, array $sortable, string $defaultSort, array $filterNames): self
    {
        $errors = [];
        $page = self::positiveInt($params['page'] ?? '1', 'page', PHP_INT_MAX, $errors);
        $perPageParam = $params['per_page'] ?? (string) self::DEFAULT_PER_PAGE;
        $perPage = self::positiveInt($perPageParam, 'per_page', self::MAX_PER_PAGE, $errors);

        $orderBy = [];
        $sortParam = $params['sort'] ?? $defaultSort;
        foreach (explode(',', is_string($sortParam) ? $sortParam : '') as $part) {
            $descending = str_starts_with($part, '-');
            $name = ltrim($part, '-');
            if (!isset($sortable[$name])) {
                $allowed = implode(', ', array_keys($sortable));
                $errors[] = ['field' => 'sort', 'message' => "Sortable fields: {$allowed}."];
                break;
            }
            $orderBy[] = [$sortable[$name], $descending ? 'DESC' : 'ASC'];
        }

        $filters = [];
        foreach ($filterNames as $name) {
            $value = $params[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $filters[$name] = trim($value);
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return new self($page, $perPage, $orderBy, $filters);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function filter(string $name): ?string
    {
        return $this->filters[$name] ?? null;
    }

    public function orderBySql(string $tieBreaker): string
    {
        $parts = array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], $this->orderBy);
        $parts[] = $tieBreaker;

        return 'ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private static function positiveInt(mixed $value, string $field, int $max, array &$errors): int
    {
        if (!is_string($value) || preg_match('/^[1-9]\d{0,8}$/', $value) !== 1 || (int) $value > $max) {
            $errors[] = ['field' => $field, 'message' => "Must be an integer between 1 and {$max}."];

            return 1;
        }

        return (int) $value;
    }
}
