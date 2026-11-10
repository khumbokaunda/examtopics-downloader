<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use RuntimeException;

/** An institution's data, all of it, as a ZIP of CSV files and its uploaded files. */
final class InstitutionExportController extends TenantController
{
    public function show(string $ref): Response
    {
        [$i, $m] = $this->tenant($ref, 'institution.manage', false);
        return $this->render('institutions/export', $this->pageVars($i, $m, 'export') + ['tables' => count($this->s->institutionExport()->tables())]);
    }

    public function download(string $ref): Response
    {
        [$i] = $this->tenant($ref, 'institution.manage', false);
        // Everyone's records leave the system in this file: the same check as downloading a backup.
        if ($r = $this->needsConfirmation('/institutions/' . $i['uuid'] . '/export')) {
            return $r;
        }
        try {
            $out = $this->s->institutionExport()->build($i, (array) $this->auth->user());
        } catch (RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/institutions/' . $i['uuid'] . '/export');
        }
        $r = Response::download($out['path'], $out['name'], 'application/zip');
        $r->deleteAfterSend = true;
        return $r;
    }
}
