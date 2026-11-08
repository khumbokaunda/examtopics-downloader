<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use RuntimeException;

/** One programme in detail: its description, duration, objectives, completion requirements and modules (departments.manage). */
final class InstitutionProgrammeController extends TenantController
{
    public function show(string $ref, string $programme): Response
    {
        [$i, $m, $p] = $this->programme($ref, $programme);
        $modules = $this->s->records()->modules((int) $i['id'], (int) $p['id']);
        $assessments = [];
        foreach ($this->app->db()->all('SELECT a.uuid, a.title, a.status, a.module_id FROM assessments a JOIN programme_modules pm ON pm.id = a.module_id
                                        WHERE a.institution_id = ? AND pm.programme_id = ? ORDER BY a.title', [(int) $i['id'], (int) $p['id']]) as $a) {
            $assessments[(int) $a['module_id']][] = $a;
        }
        return $this->render('institutions/programme', $this->pageVars($i, $m, 'structure') + ['programme' => $p, 'modules' => $modules, 'assessments' => $assessments]);
    }

    public function saveDetails(string $ref, string $programme): Response
    {
        [$i, , $p] = $this->programme($ref, $programme);
        return $this->attempt($i, $p, fn() => $this->s->records()->saveDetails($i, $p, array_map(static fn($v): string => is_string($v) ? $v : '',
            array_intersect_key($this->request->post, array_flip(['description', 'duration', 'objectives', 'completion_requirements', 'status'])))), 'Programme saved.');
    }

    public function saveModule(string $ref, string $programme, ?string $module = null): Response
    {
        [$i, , $p] = $this->programme($ref, $programme);
        $mod = $module !== null ? ($this->s->records()->module((int) $i['id'], $module) ?? throw HttpException::notFound('Module')) : null;
        if ($mod !== null && (int) $mod['programme_id'] !== (int) $p['id']) {
            throw HttpException::notFound('Module');
        }
        return $this->attempt($i, $p, fn() => $this->s->records()->saveModule($i, $p, $mod, (string) $this->request->input('name', ''), (string) $this->request->input('code', ''),
            (string) $this->request->input('description', ''), $this->request->input('required') === '1'), $mod === null ? 'Module added.' : 'Module saved.');
    }

    public function retireModule(string $ref, string $programme, string $module): Response
    {
        [$i, , $p] = $this->programme($ref, $programme);
        $mod = $this->s->records()->module((int) $i['id'], $module);
        if ($mod === null || (int) $mod['programme_id'] !== (int) $p['id']) {
            throw HttpException::notFound('Module');
        }
        $retire = $mod['status'] === 'active';
        return $this->attempt($i, $p, fn() => $this->s->records()->setRetired($i, $mod, $retire), $retire ? 'Module retired. Students who completed it keep it.' : 'Module offered again.');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} */
    private function programme(string $ref, string $uuid): array
    {
        [$i, $m] = $this->tenant($ref, 'departments.manage');
        $p = $this->s->structure()->repo()->programme((int) $i['id'], $uuid) ?? throw HttpException::notFound('Programme');
        return [$i, $m, $p];
    }

    /** @param array<string,mixed> $i @param array<string,mixed> $p */
    private function attempt(array $i, array $p, \Closure $fn, string $ok): Response
    {
        try {
            $fn();
            $this->flash('success', $ok);
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/structure/programmes/' . $p['uuid']);
    }
}
