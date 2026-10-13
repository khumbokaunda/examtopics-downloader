<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Ids;
use App\Core\Paths;

/**
 * Discussion links remembered from the last complete inventory refresh, one file per exam
 * (storage/inventory/<provider>/<exam>.links) plus meta.json with the newest discussion
 * number seen. A collection hands them to the tool (-links-from, -newer-than), which then
 * reads only the newest listing pages instead of all of them.
 */
final class InventoryLinks
{
    public function __construct(private Paths $paths)
    {
    }

    /**
     * Remember an inventory's links. Only complete refreshes are remembered: a partial one
     * is missing links, so the previous complete set is kept.
     *
     * @param array<string,mixed> $doc the tool's inventory document
     */
    public function remember(string $provider, array $doc): int
    {
        if (!Ids::isProvider($provider) || ($doc['status'] ?? '') !== 'complete') {
            return 0;
        }
        $dir = $this->dir($provider);
        $written = [];
        foreach ((array) ($doc['exams'] ?? []) as $e) {
            $links = is_array($e) ? array_values(array_filter((array) ($e['links'] ?? []), 'is_string')) : [];
            if (Ids::isExam($e['code'] ?? null) && $links !== []) {
                $file = $dir . '/' . $e['code'] . '.links';
                Paths::atomicWrite($file, implode("\n", $links) . "\n");
                $written[$file] = true;
            }
        }
        // Exams the provider no longer lists: their old links would not match the new meta.
        foreach (glob($dir . '/*.links') ?: [] as $stale) {
            if (!isset($written[$stale])) {
                @unlink($stale);
            }
        }
        Paths::atomicWrite($dir . '/meta.json', (string) json_encode([
            'newest_id' => (int) ($doc['newest_id'] ?? 0),
            'generated_at' => (string) ($doc['generated_at'] ?? gmdate('c')),
        ]));
        return count($written);
    }

    /** @return array{path:string,count:int,newest_id:int,generated_at:string}|null */
    public function forExam(string $provider, string $exam): ?array
    {
        if (!Ids::isProvider($provider) || !Ids::isExam($exam)) {
            return null;
        }
        $path = $this->dir($provider) . '/' . $exam . '.links';
        $meta = json_decode((string) @file_get_contents($this->dir($provider) . '/meta.json'), true);
        if (!is_file($path) || !is_array($meta) || (int) ($meta['newest_id'] ?? 0) <= 0) {
            return null;
        }
        return [
            'path' => $path,
            'count' => count(array_filter(explode("\n", (string) file_get_contents($path)), static fn($l) => trim($l) !== '')),
            'newest_id' => (int) $meta['newest_id'],
            'generated_at' => (string) ($meta['generated_at'] ?? ''),
        ];
    }

    /**
     * Links the user added for an exam (Collect from links), as paths. They are kept apart
     * from the inventory's, so a refresh never removes them.
     *
     * @return list<string>
     */
    public function added(string $provider, string $exam): array
    {
        if (!Ids::isProvider($provider) || !Ids::isExam($exam)) {
            return [];
        }
        $path = $this->dir($provider) . '/added/' . $exam . '.links';
        return is_file($path) ? array_values(array_filter(array_map('trim', file($path) ?: []), static fn(string $l) => $l !== '')) : [];
    }

    /**
     * Add links for an exam, keeping one per discussion number.
     *
     * @param list<string> $paths discussion paths, e.g. /discussions/huawei/view/390360-exam-...-discussion/
     * @return int how many links the exam has now
     */
    public function addLinks(string $provider, string $exam, array $paths): int
    {
        if (!Ids::isProvider($provider) || !Ids::isExam($exam)) {
            return 0;
        }
        $byId = [];
        foreach (array_merge($this->added($provider, $exam), $paths) as $p) {
            if (preg_match('#^/discussions/[a-z0-9-]+/view/(\d+)-#', $p, $m) === 1) {
                $byId[$m[1]] = $p; // a later paste replaces an earlier link for the same discussion
            }
        }
        Paths::atomicWrite($this->dir($provider) . '/added/' . $exam . '.links', implode("\n", $byId) . "\n");
        return count($byId);
    }

    public function clearAdded(string $provider, string $exam): void
    {
        if (Ids::isProvider($provider) && Ids::isExam($exam)) {
            @unlink($this->dir($provider) . '/added/' . $exam . '.links');
        }
    }

    private function dir(string $provider): string
    {
        return $this->paths->storage() . '/inventory/' . $provider;
    }
}
