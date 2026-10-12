<?php

declare(strict_types=1);

namespace App\Services\Documents\Pdf;

use Dompdf\Cpdf;

/**
 * dompdf's PDF writer with bookmarks. Cpdf creates an empty outline root (object 2) and has
 * no way to add entries, so this adds one level of entries and opens the bookmarks panel.
 */
class OutlineCpdf extends Cpdf
{
    /** @var list<int> outline entry object ids, in order */
    private array $entries = [];

    /** Add a bookmark to a page, at a height in PDF coordinates (from the bottom). */
    public function addBookmark(string $title, int $pageObjectId, float $y): void
    {
        $id = ++$this->numObj;
        $this->objects[$id] = ['t' => 'outlineEntry', 'info' => ['title' => $title, 'page' => $pageObjectId, 'y' => $y]];
        $this->entries[] = $id;
    }

    protected function o_outlines($id, $action, $options = '')
    {
        if ($action !== 'out' || $this->entries === []) {
            return parent::o_outlines($id, $action, $options);
        }
        return "\n$id 0 obj\n<< /Type /Outlines /First " . $this->entries[0] . ' 0 R /Last '
            . $this->entries[count($this->entries) - 1] . ' 0 R /Count ' . count($this->entries) . " >>\nendobj";
    }

    protected function o_outlineEntry($id, $action, $options = '')
    {
        if ($action !== 'out') {
            return null;
        }
        $info = $this->objects[$id]['info'];
        $at = array_search($id, $this->entries, true);
        $res = "\n$id 0 obj\n<< /Title " . self::textString((string) $info['title'])
            . ' /Parent ' . $this->outlinesId() . ' 0 R'
            . ' /Dest [' . (int) $info['page'] . ' 0 R /XYZ 0 ' . sprintf('%.2F', (float) $info['y']) . ' 0]';
        if ($at > 0) {
            $res .= ' /Prev ' . $this->entries[$at - 1] . ' 0 R';
        }
        if ($at < count($this->entries) - 1) {
            $res .= ' /Next ' . $this->entries[$at + 1] . ' 0 R';
        }
        return $res . " >>\nendobj";
    }

    protected function o_catalog($id, $action, $options = '')
    {
        $res = parent::o_catalog($id, $action, $options);
        if ($action === 'out' && $this->entries !== [] && is_string($res)) {
            $res = str_replace('<< /Type /Catalog', '<< /Type /Catalog /PageMode /UseOutlines', $res);
        }
        return $res;
    }

    private function outlinesId(): int
    {
        foreach ($this->objects as $k => $o) {
            if (($o['t'] ?? '') === 'outlines') {
                return (int) $k;
            }
        }
        return 2;
    }

    /** A PDF text string in UTF-16BE, so any title is shown as written. */
    private static function textString(string $s): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
