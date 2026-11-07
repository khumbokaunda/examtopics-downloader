<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use RuntimeException;

/** An institution's departments and programmes (departments.manage). */
final class InstitutionStructureController extends TenantController
{
    public function index(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'departments.manage');
        $repo = $this->s->structure()->repo();
        return $this->render('institutions/structure', $this->pageVars($i, $m, 'structure') + [
            'departments' => $repo->departments((int) $i['id']), 'programmes' => $repo->programmes((int) $i['id']),
        ]);
    }

    public function addDepartment(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'departments.manage');
        return $this->attempt($i, fn() => $this->s->structure()->addDepartment($i, (string) $this->request->input('name', '')), 'Department added.');
    }

    public function renameDepartment(string $ref, string $department): Response
    {
        [$i] = $this->tenant($ref, 'departments.manage');
        $d = $this->s->structure()->repo()->department((int) $i['id'], $department) ?? throw HttpException::notFound('Department');
        return $this->attempt($i, fn() => $this->s->structure()->renameDepartment($i, $d, (string) $this->request->input('name', '')), 'Department saved.');
    }

    public function deleteDepartment(string $ref, string $department): Response
    {
        [$i] = $this->tenant($ref, 'departments.manage');
        $d = $this->s->structure()->repo()->department((int) $i['id'], $department) ?? throw HttpException::notFound('Department');
        return $this->attempt($i, fn() => $this->s->structure()->deleteDepartment($i, $d), 'Department removed.');
    }

    public function saveProgramme(string $ref, ?string $programme = null): Response
    {
        [$i] = $this->tenant($ref, 'departments.manage');
        $p = $programme === null ? null : ($this->s->structure()->repo()->programme((int) $i['id'], $programme) ?? throw HttpException::notFound('Programme'));
        return $this->attempt($i, fn() => $this->s->structure()->saveProgramme($i, $p, (string) $this->request->input('name', ''),
            (string) $this->request->input('code', ''), (string) $this->request->input('department', '')), $p === null ? 'Programme added.' : 'Programme saved.');
    }

    public function deleteProgramme(string $ref, string $programme): Response
    {
        [$i] = $this->tenant($ref, 'departments.manage');
        $p = $this->s->structure()->repo()->programme((int) $i['id'], $programme) ?? throw HttpException::notFound('Programme');
        return $this->attempt($i, fn() => $this->s->structure()->deleteProgramme($i, $p), 'Programme removed.');
    }

    /** @param array<string,mixed> $i */
    private function attempt(array $i, \Closure $fn, string $ok): Response
    {
        try {
            $fn();
            $this->flash('success', $ok);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/structure');
    }
}
