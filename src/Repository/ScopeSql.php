<?php

declare(strict_types=1);

namespace HrPayroll\Repository;

use HrPayroll\Auth\EmployeeScope;

final class ScopeSql
{
    /**
     * SQL condition restricting rows to the employees a scope may see.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function condition(EmployeeScope $scope, string $employeeAlias): array
    {
        return match ($scope->kind) {
            EmployeeScope::ALL => ['1 = 1', []],
            EmployeeScope::TEAM => [
                "({$employeeAlias}.id = :scope_self OR {$employeeAlias}.manager_id = :scope_manager)",
                ['scope_self' => (int) $scope->employeeId, 'scope_manager' => (int) $scope->employeeId],
            ],
            default => ["{$employeeAlias}.id = :scope_self", ['scope_self' => (int) $scope->employeeId]],
        };
    }

    /**
     * Named placeholders for an IN (...) list.
     *
     * @param list<int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    public static function in(string $prefix, array $values): array
    {
        $params = [];
        foreach ($values as $i => $value) {
            $params["{$prefix}{$i}"] = $value;
        }

        return [implode(', ', array_map(static fn (string $k): string => ':' . $k, array_keys($params))), $params];
    }
}
