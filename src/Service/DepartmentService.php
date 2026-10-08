<?php

declare(strict_types=1);

namespace HrPayroll\Service;

use HrPayroll\Auth\Principal;
use HrPayroll\Domain\Exception\Conflict;
use HrPayroll\Domain\Exception\NotFound;
use HrPayroll\Domain\Role;
use HrPayroll\Http\ListQuery;
use HrPayroll\Http\Page;
use HrPayroll\Infrastructure\Database;
use HrPayroll\Repository\DepartmentRepository;

final class DepartmentService
{
    public function __construct(
        private readonly Database $db,
        private readonly DepartmentRepository $departments,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return Page<array<string, mixed>>
     */
    public function list(ListQuery $query): Page
    {
        return $this->departments->list($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        return $this->departments->find($id) ?? throw new NotFound('Department not found.');
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Principal $actor, string $code, string $name): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $code, $name): array {
            if ($this->departments->existsWithCodeOrName($code, $name)) {
                throw new Conflict('A department with this code or name already exists.', 'duplicate_department');
            }
            $id = $this->departments->create($code, $name);
            $after = ['code' => $code, 'name' => $name];
            $this->audit->record($actor, 'department.created', 'department', $id, null, $after);

            return $this->get($id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $id, ?string $code, ?string $name): array
    {
        $actor->requireRole(Role::Admin, Role::Hr);

        return $this->db->transactional(function () use ($actor, $id, $code, $name): array {
            $current = $this->get($id);
            $newCode = $code ?? (string) $current['code'];
            $newName = $name ?? (string) $current['name'];
            if ($this->departments->existsWithCodeOrName($newCode, $newName, $id)) {
                throw new Conflict('A department with this code or name already exists.', 'duplicate_department');
            }
            $this->departments->update($id, $newCode, $newName);
            $this->audit->record(
                $actor,
                'department.updated',
                'department',
                $id,
                ['code' => $current['code'], 'name' => $current['name']],
                ['code' => $newCode, 'name' => $newName],
            );

            return $this->get($id);
        });
    }
}
