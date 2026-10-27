<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

/**
 * The page's small template functions, as PHP: dimRows(), recRow(),
 * dataRow(), levelKey(), shareBar(), fmtDateTime() and sinceLabel().
 *
 * Every console view draws these the way the page draws them — same classes,
 * same wording, same inline styles — so the console's CSS (the page's own,
 * in assets/css/admin.css) renders them identically. Each opens the record or
 * organization dialog where the page called openRecord()/openOrg(); the href
 * is the page, for a new tab or a browser without scripting.
 */
final class Html
{
    /** dimRows(dims, spreadMap): one bar per skill, coloured by level, with the range overlaid. */
    public static function dimRows(Stats $stats, array $dims, ?array $spread = null): string
    {
        $out = '<div class="bars">';

        foreach ($stats->instrument()->dims() as $d) {
            $v  = $dims[$d['code']] ?? null;
            $b  = $stats->band($v);
            $sp = $spread[$d['code']] ?? null;

            if ($v === null || $b === null) {
                $out .= '<div class="brow unscored"><div class="k"><span class="code">' . e($d['code']) . '</span>' . e($d['name'])
                    . '</div><div class="track"></div><div class="v">—</div><div class="b muted">Not scored</div></div>';
                continue;
            }

            $spHtml = $sp !== null && $sp['n'] > 1
                ? '<div class="sp" style="left:' . self::num(($sp['min'] - 1) / 4 * 100) . '%;width:' . self::num(($sp['max'] - $sp['min']) / 4 * 100) . '%"></div>'
                : '';

            $out .= '<div class="brow" style="--c:' . e($b['c']) . '"><div class="k"><span class="code">' . e($d['code']) . '</span>' . e($d['name'])
                . '</div><div class="track"><i style="width:' . self::num(($v - 1) / 4 * 100) . '%"></i>' . $spHtml . '</div><div class="v">'
                . e(Stats::fmt($v)) . '</div><div class="b">' . e($b['label']) . '</div></div>';
        }

        return $out . '</div>';
    }

    /** levelKey(): the five colours with their score ranges. */
    public static function levelKey(Stats $stats): string
    {
        $out = '<div class="lvkey">';
        foreach ($stats->instrument()->rungs() as $i => $r) {
            $out .= '<span><i style="background:' . e($r['hex']) . '"></i>' . e($r['name']) . ' <span class="muted">(' . e($stats->bandRange($i)) . ')</span></span>';
        }

        return $out . '</div>';
    }

    /** shareBar(sh): one stacked bar of level shares. */
    public static function shareBar(Stats $stats, array $sh): string
    {
        $rungs = $stats->instrument()->rungs();
        $out   = '<div class="lvstack">';

        foreach ($sh['shares'] as $i => $p) {
            if ($p) {
                $out .= '<i style="width:' . Stats::toFixed($p * 100, 2) . '%;background:' . e($rungs[$i]['hex']) . '" title="'
                    . e($rungs[$i]['name'] . ': ' . $sh['counts'][$i] . ' (' . Stats::pct($p) . ')') . '"></i>';
            }
        }

        return $out . '</div>';
    }

    /** fmtDateTime(iso): "Oct 9, 2026, 10:05 AM". */
    public static function dateTime(string $iso): string
    {
        $time = strtotime($iso);

        return $time === false ? '—' : date('M j, Y, g:i A', $time);
    }

    /** fmtDate(iso): "Oct 9, 2026". */
    public static function date(string $iso): string
    {
        $time = strtotime($iso);

        return $time === false ? '—' : date('M j, Y', $time);
    }

    /** sinceLabel(iso): minutes, hours, days, then the date. */
    public static function since(string $iso): string
    {
        $time = strtotime($iso);

        if ($time === false || $time > time()) {
            return self::date($iso);
        }

        $m  = intdiv(time() - $time, 60);
        $h  = intdiv($m, 60);
        $dy = intdiv($h, 24);

        return match (true) {
            $m < 1   => 'just now',
            $m < 60  => $m . ' minute' . ($m === 1 ? '' : 's') . ' ago',
            $h < 24  => $h . ' hour' . ($h === 1 ? '' : 's') . ' ago',
            $dy < 45 => $dy . ' day' . ($dy === 1 ? '' : 's') . ' ago',
            default  => self::date($iso),
        };
    }

    /** The dialog link attributes for a record: href to its page, data-modal-src to its fragment. */
    public static function recordLink(string $slug, array $r): string
    {
        $href = url('admin/' . $slug . '/research-data/' . $r['_id']);

        return 'href="' . e($href) . '" data-modal-src="' . e($href . '?fragment=1') . '" data-modal-wide';
    }

    /** The dialog link attributes for an organization. */
    public static function orgLink(string $slug, string $name): string
    {
        $href = url('admin/' . $slug . '/analytics/organisation') . '?' . http_build_query(['name' => $name]);

        return 'href="' . e($href) . '" data-modal-src="' . e($href . '&fragment=1') . '" data-modal-wide';
    }

    /** recRow(r): the Console's Latest and Find rows and the latest strip. */
    public static function recRow(Stats $stats, string $slug, array $r): string
    {
        $lv      = $stats->rungNamed($r['level'] ?? null);
        $org     = $r['org'];
        $contact = implode(' · ', array_map('e', array_values(array_filter(
            [($r['userEmail'] ?? '') ?: ($org['email'] ?? ''), ($r['userPhone'] ?? '') ?: ($org['phone'] ?? '')],
            static fn ($v): bool => $v !== null && $v !== ''
        ))));
        $orgRole = implode(' · ', array_map('e', array_values(array_filter([$org['org'] ?? '', $org['role'] ?? ''], static fn ($v): bool => $v !== null && $v !== ''))));

        return '<a class="rec" ' . self::recordLink($slug, $r) . ' style="--c:' . e($lv['c'] ?? 'var(--stone)') . ';cursor:pointer;color:inherit;text-decoration:none" role="button">'
            . '<div><div class="t">' . e(($r['userName'] ?? '') ?: (($org['name'] ?? '') ?: 'Unnamed')) . ' ' . ($r['synthetic'] ? '<span class="tag syn">SYNTHETIC</span>' : '') . '</div>'
            . '<div class="m">' . ($orgRole !== '' ? $orgRole : '<span class="muted">no organization recorded</span>') . '</div>'
            . ($contact !== '' ? '<div class="m">' . $contact . '</div>' : '')
            . '<div class="m">' . e(self::dateTime((string) $r['timestamp'])) . ' · ' . e(self::since((string) $r['timestamp'])) . '</div></div>'
            . '<div class="s">' . e($lv['v'] ?? '—') . '<small>Overall ' . e(Stats::fmt($r['mean'])) . '</small></div></a>';
    }

    /** dataRow(r): one row of Research data's Records list, with data-hay for the live search. */
    public static function dataRow(Stats $stats, string $slug, array $r): string
    {
        $lv      = $stats->rungNamed($r['level'] ?? null);
        $org     = $r['org'];
        $contact = implode(' · ', array_map('e', array_values(array_filter(
            [($r['userEmail'] ?? '') ?: ($org['email'] ?? ''), ($r['userPhone'] ?? '') ?: ($org['phone'] ?? '')],
            static fn ($v): bool => $v !== null && $v !== ''
        ))));
        $meta = implode(' · ', array_map('e', array_values(array_filter(
            [$org['sector'] ?? '', $org['size'] ?? '', $org['role'] ?? '', (string) ($r['year'] ?? '')],
            static fn ($v): bool => $v !== null && $v !== ''
        ))));

        $pills = '';
        foreach ($stats->instrument()->dims() as $d) {
            $v      = $r['dims'][$d['code']] ?? null;
            $pills .= '<span class="pill">' . e($d['code']) . ' ' . e($v === null ? '—' : Stats::fmt($v)) . '</span>';
        }

        return '<a class="rec" ' . self::recordLink($slug, $r) . ' data-hay="' . e(Stats::haystack($r)) . '" style="--c:' . e($lv['c'] ?? 'var(--stone)') . ';cursor:pointer;color:inherit;text-decoration:none">'
            . '<div><div class="t">' . e(($r['userName'] ?? '') ?: (($org['name'] ?? '') ?: 'Unnamed')) . ' <span class="muted" style="font-weight:400">' . e($org['org'] ?? '') . '</span> '
            . ($r['synthetic'] ? '<span class="tag syn">SYNTHETIC</span>' : '') . '</div>'
            . '<div class="m">' . ($contact !== '' ? $contact : '<span class="muted">no contact details on this record</span>') . '</div>'
            . '<div class="m">' . $meta . ' · ' . e(self::dateTime((string) $r['timestamp'])) . '</div>'
            . '<div class="pills">' . $pills . '</div></div>'
            . '<div class="s">' . e($lv['v'] ?? '—') . '<small>Overall ' . e(Stats::fmt($r['mean'])) . '</small></div></a>';
    }

    /** A percentage for a style attribute, as JavaScript's template would print it. */
    private static function num(float $v): string
    {
        return Exports::jsString($v);
    }
}
