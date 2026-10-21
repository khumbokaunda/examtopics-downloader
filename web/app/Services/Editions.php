<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How the library is shown on shelves: a short code for each exam, and editions of the same
 * exam on one card.
 *
 * Short code: ExamTopics addresses spell some exams out in full
 * ("aws-certified-solutions-architect-associate-saa-c03"). The code people know is the tail:
 * the parts with digits and the short letter group just before them ("SAA-C03"). Codes of 20
 * characters or fewer, and long ones with no digits, are shown as they are.
 *
 * Editions: two exams of one provider whose names are the same once version numbers and
 * years are taken out ("... Associate SAA-C02" and "... Associate SAA-C03", or "Veeam
 * Certified Engineer v12" and "Veeam Certified Engineer 2021") are editions of one exam. An
 * exam with no name of its own is never grouped. An administrator can keep an exam separate,
 * group it with another, or choose which edition a card shows first (exams.edition_group and
 * exams.edition_first); otherwise the edition whose code sorts highest comes first.
 */
final class Editions
{
    public static function shortCode(string $code): string
    {
        $code = strtoupper($code);
        if (strlen($code) <= 20) {
            return $code;
        }
        $parts = explode('-', $code);
        $tail = [];
        while ($parts !== []) {
            $part = (string) end($parts);
            $hasDigit = preg_match('/\d/', $part) === 1;
            $shortLetters = preg_match('/^[A-Z]{1,4}$/', $part) === 1 && $tail !== [];
            if (!$hasDigit && !$shortLetters) {
                break;
            }
            array_unshift($tail, array_pop($parts));
        }
        $short = implode('-', $tail);
        return $tail !== [] && preg_match('/\d/', $short) === 1 && $parts !== [] ? $short : $code;
    }

    /** The automatic key editions share, or a key of the exam's own when it has no usable name. */
    public static function autoKey(string $provider, string $code, ?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '' || strcasecmp($name, $code) === 0) {
            return $provider . '/code:' . strtolower($code);
        }
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, static fn(string $w): bool => preg_match('/\d/', $w) !== 1 && $w !== 'v'));
        return $words === [] ? $provider . '/code:' . strtolower($code) : $provider . '/' . implode(' ', $words);
    }

    /** @param array<string,mixed> $row a library row (provider_slug, code, name, edition_group) */
    public static function key(array $row): string
    {
        $override = (string) ($row['edition_group'] ?? '');
        if ($override === 'solo') {
            return (string) $row['provider_slug'] . '/code:' . strtolower((string) $row['code']);
        }
        return $override !== '' ? $override : self::autoKey((string) $row['provider_slug'], (string) $row['code'], $row['name'] ?? null);
    }

    /**
     * Library rows on shelves: one shelf per provider (in name order), one card per exam, with
     * its editions newest first.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{slug:string,name:string,cards:list<list<array<string,mixed>>>}>
     */
    public static function shelves(array $rows): array
    {
        $shelves = [];
        foreach ($rows as $r) {
            $slug = (string) $r['provider_slug'];
            $shelves[$slug] ??= ['slug' => $slug, 'name' => provider_name($r, 'provider_name', 'provider_slug'), 'cards' => []];
            $shelves[$slug]['cards'][self::key($r)][] = $r;
        }
        foreach ($shelves as &$shelf) {
            foreach ($shelf['cards'] as &$card) {
                usort($card, [self::class, 'compare']);
            }
            unset($card);
            $cards = array_values($shelf['cards']);
            usort($cards, static fn(array $a, array $b): int => strcasecmp(self::title($a[0]), self::title($b[0])));
            $shelf['cards'] = $cards;
        }
        unset($shelf);
        $out = array_values($shelves);
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** The order of editions on a card: the chosen first, then the highest code. @param array<string,mixed> $a @param array<string,mixed> $b */
    public static function compare(array $a, array $b): int
    {
        $first = (int) ($b['edition_first'] ?? 0) <=> (int) ($a['edition_first'] ?? 0);
        if ($first !== 0) {
            return $first;
        }
        $plain = static fn(array $r): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $r['code']));
        return strnatcmp($plain($b), $plain($a));
    }

    /**
     * The code to show under a card's title, or null when the title already says it: the
     * name is the code ("AZ-500"), or the code is the name spelled out
     * ("aws-certified-cloud-practitioner").
     *
     * @param array<string,mixed> $row
     */
    public static function codeLabel(array $row): ?string
    {
        $name = trim((string) ($row['name'] ?? ''));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $short = self::shortCode((string) $row['code']);
        return $slug === strtolower((string) $row['code']) || self::title($row) === $short ? null : $short;
    }

    /** What a card is called: the exam's name, or its code when it has none. @param array<string,mixed> $row */
    public static function title(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        return $name !== '' && strcasecmp($name, (string) $row['code']) !== 0 ? $name : self::shortCode((string) $row['code']);
    }
}
