<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Response;
use App\Services\DocumentStore;
use App\Services\Documents\Variant;
use RuntimeException;

final class DocumentController extends Controller
{
    /** Generate (or reuse) a document, then download it. */
    public function generate(string $provider, string $exam, string $version): Response
    {
        $p = $this->findProvider($provider);
        $e = $this->findExam($p, $exam);
        $v = $this->findVersion($e, $version);
        $format = (string) $this->request->input('format', '');
        $variant = (string) $this->request->input('variant', '');
        if (!isset(DocumentStore::FORMATS[$format]) || !isset(Variant::ALL[$variant])) {
            throw HttpException::badRequest('Unknown document format or variant.');
        }
        $back = '/library/' . rawurlencode($p['slug']) . '/' . rawurlencode($e['code']);
        try {
            $doc = $this->s->documents()->obtain($v, $format, $variant, $this->request->input('images') === '1');
        } catch (RuntimeException $ex) {
            $this->flash('danger', $ex->getMessage() . ' The cached data and other documents are unaffected.');
            return $this->redirect($back, ['version' => $v['version']]);
        }
        $this->flash('success', $doc['filename'] . ' is ready (' . fmt_bytes($doc['size_bytes']) . '). Use its Download button below.');
        return $this->redirect($back, ['version' => $v['version']]);
    }

    public function download(string $uuid): Response
    {
        if (!\App\Core\Ids::isUuid($uuid)) {
            throw HttpException::notFound('Document');
        }
        $doc = $this->s->documentRepo()->findByUuid($uuid) ?? throw HttpException::notFound('Document');
        return $this->sendDocument($doc);
    }

    public function delete(string $uuid): Response
    {
        if (!\App\Core\Ids::isUuid($uuid)) {
            throw HttpException::notFound('Document');
        }
        $doc = $this->s->documentRepo()->findByUuid($uuid) ?? throw HttpException::notFound('Document');
        $this->s->documents()->delete($doc);
        $this->flash('success', 'Document deleted. The cached exam data is unchanged and the document can be generated again.');
        return $this->redirect('/library/' . rawurlencode((string) $doc['provider_slug']) . '/' . rawurlencode((string) $doc['exam_code']),
            ['version' => (string) $doc['version']]);
    }

    /** @param array<string,mixed> $doc */
    private function sendDocument(array $doc): Response
    {
        // The path comes only from the database record and must resolve inside the documents
        // root and match the recorded checksum.
        $path = $this->s->documents()->verifiedPath($doc);
        if ($path === null) {
            throw new HttpException(409, 'This document is missing or failed its integrity check. Generate it again from the library page.');
        }
        return Response::download($path, (string) $doc['filename'], DocumentStore::FORMATS[(string) $doc['format']]);
    }
}
