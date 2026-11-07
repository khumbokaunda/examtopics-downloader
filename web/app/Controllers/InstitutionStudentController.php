<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\InstitutionAccess;
use App\Services\StudentImport;
use RuntimeException;

/**
 * An institution's students: the list (students.manage, or classes.view to read it), one
 * student's record and class history, and importing students from a CSV file.
 */
final class InstitutionStudentController extends TenantController
{
    private const IMPORT = '_student_import';
    private const RESULTS = '_student_import_results';

    public function index(string $ref): Response
    {
        [$i, $m] = $this->readable($ref);
        return $this->render('institutions/students', $this->pageVars($i, $m, 'students') + [
            'students' => $this->s->structure()->repo()->students((int) $i['id']),
            'manage' => InstitutionAccess::can($m, 'students.manage'),
        ]);
    }

    public function show(string $ref, string $member): Response
    {
        [$i, $m] = $this->readable($ref);
        $repo = $this->s->structure()->repo();
        $st = $repo->student((int) $i['id'], $member) ?? throw HttpException::notFound('Student');
        return $this->render('institutions/student', $this->pageVars($i, $m, 'students') + [
            'student' => $st, 'history' => $repo->enrolmentsOf((int) $i['id'], (int) $st['id']),
            'programmes' => $repo->programmes((int) $i['id']), 'manage' => InstitutionAccess::can($m, 'students.manage'),
        ]);
    }

    public function save(string $ref, string $member): Response
    {
        [$i] = $this->tenant($ref, 'students.manage');
        $st = $this->s->structure()->repo()->student((int) $i['id'], $member) ?? throw HttpException::notFound('Student');
        if ($st['status'] !== 'active') {
            throw HttpException::notFound('Student');
        }
        try {
            $this->s->structure()->saveRecord($i, $st, (string) $this->request->input('student_number', ''), (string) $this->request->input('programme', ''),
                (string) $this->request->input('status', ''), (string) $this->request->input('enrolled_on', ''));
            $this->flash('success', 'Record saved.');
        } catch (RuntimeException $e) {
            $this->flash('warning', $e->getMessage());
        }
        return $this->redirect('/institutions/' . $i['uuid'] . '/students/' . $st['uuid']);
    }

    public function importPage(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'students.manage');
        return $this->render('institutions/import', $this->pageVars($i, $m, 'students') + ['rows' => null, 'error' => null,
            'results' => $this->sessionFor($i, self::RESULTS)]);
    }

    public function template(string $ref): Response
    {
        $this->tenant($ref, 'students.manage');
        return new Response(StudentImport::template(), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="students-template.csv"']);
    }

    public function preview(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'students.manage');
        $file = $this->request->file('file');
        $vars = $this->pageVars($i, $m, 'students') + ['rows' => null, 'error' => null, 'results' => null];
        try {
            if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a CSV file to upload.');
            }
            $rows = $this->s->studentImport()->check($i, StudentImport::parse((string) file_get_contents($file['tmp_name'])));
        } catch (RuntimeException $e) {
            return $this->render('institutions/import', ['error' => $e->getMessage()] + $vars, 422);
        }
        $this->session->set(self::IMPORT, ['institution' => $i['uuid'], 'rows' => $rows, 'at' => time()]);
        return $this->render('institutions/import', ['rows' => $rows] + $vars);
    }

    public function confirm(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'students.manage');
        $pending = $this->session->get(self::IMPORT);
        $this->session->remove(self::IMPORT);
        if (!is_array($pending) || ($pending['institution'] ?? '') !== $i['uuid'] || (int) ($pending['at'] ?? 0) < time() - 3600) {
            $this->flash('warning', 'That preview has expired. Upload the file again.');
            return $this->redirect('/institutions/' . $i['uuid'] . '/students/import');
        }
        $done = $this->s->studentImport()->apply($i, $m, $this->auth->user(), (array) $pending['rows'], $this->s->institutions(), $this->s->structure());
        $this->session->set(self::RESULTS, ['institution' => $i['uuid'], 'rows' => $done['rows'], 'at' => time()]);
        $this->flash($done['failed'] > 0 ? 'warning' : 'success', sprintf('%d students updated and put in their classes, %d invited, %d not imported. Download the results to see every row.',
            $done['enrolled'], $done['invited'], $done['failed']));
        return $this->redirect('/institutions/' . $i['uuid'] . '/students/import');
    }

    public function results(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'students.manage');
        $results = $this->sessionFor($i, self::RESULTS) ?? throw HttpException::notFound('Import results');
        return new Response(StudentImport::resultsCsv($results['rows']), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="student-import-results.csv"']);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function readable(string $ref): array
    {
        [$i, $m] = $this->tenant($ref, null);
        if (!InstitutionAccess::can($m, 'students.manage') && !InstitutionAccess::can($m, 'classes.view')) {
            throw new HttpException(403, 'Your role in ' . $i['name'] . ' does not include the student list.');
        }
        return [$i, $m];
    }

    /** @param array<string,mixed> $i @return array<string,mixed>|null */
    private function sessionFor(array $i, string $key): ?array
    {
        $v = $this->session->get($key);
        return is_array($v) && ($v['institution'] ?? '') === $i['uuid'] ? $v : null;
    }

}
