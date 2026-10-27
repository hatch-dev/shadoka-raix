<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

/**
 * The page's statistics, ported line for line.
 *
 * Every Human Edge admin screen is computed by the page's own functions over
 * an array of records: aggregate(), summarize(), describe(), quantile(),
 * levelShares(), pearson(), cronbach(), nextStepPicks() and the formatters
 * fmt(), f2() and pct(). The console's screens call these ports over the same
 * array (Records), so a figure on the console is the figure the page shows.
 *
 * Rounding follows JavaScript, not PHP: `toFixed()` rounds the exact binary
 * value (1.005 → "1.00", where PHP's round() gives 1.01) and breaks an exact
 * tie upwards (0.125 → "0.13", where sprintf gives 0.12), and Math.round() is
 * floor(x + 0.5). toFixed() below reproduces both, so a mean that lands on a
 * band edge falls on the same side of it here as in the browser.
 */
final class Stats
{
    public function __construct(private readonly Instrument $instrument)
    {
    }

    public function instrument(): Instrument
    {
        return $this->instrument;
    }

    /* ------------------------------------------------------------------ */
    /* JavaScript number formatting                                         */
    /* ------------------------------------------------------------------ */

    /** Number.prototype.toFixed($digits), exactly. */
    public static function toFixed(float|int $value, int $digits): string
    {
        $value = (float) $value;

        if (!is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? 'Infinity' : '-Infinity');
        }

        $negative = $value < 0;
        // The exact decimal expansion of the double (PHP prints it exactly).
        $exact = sprintf('%.50F', abs($value));
        [$whole, $fraction] = explode('.', $exact);

        $kept = substr($fraction, 0, $digits);
        $rest = substr($fraction, $digits);

        // Round half up on the exact value: the first dropped digit decides,
        // and only a 5 followed by nothing but zeros is an exact tie (also up).
        $up = $rest !== '' && $rest[0] >= '5';

        $digitsString = $whole . $kept;

        if ($up) {
            $digitsString = self::incrementDecimalString($digitsString);
        }

        $intPart  = $digits > 0 ? substr($digitsString, 0, strlen($digitsString) - $digits) : $digitsString;
        $fracPart = $digits > 0 ? substr($digitsString, -$digits) : '';
        $intPart  = ltrim($intPart, '0') === '' ? '0' : ltrim($intPart, '0');

        $out = $intPart . ($digits > 0 ? '.' . $fracPart : '');

        // As in JavaScript, any negative input keeps its sign (-0.001 gives "-0.00").
        return ($negative ? '-' : '') . $out;
    }

    private static function incrementDecimalString(string $digits): string
    {
        $i = strlen($digits) - 1;

        while ($i >= 0) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
                $i--;
                continue;
            }

            $digits[$i] = (string) ((int) $digits[$i] + 1);

            return $digits;
        }

        return '1' . $digits;
    }

    /** `+v.toFixed(2)`: the page's way of rounding a stored score. */
    public static function round2(float|int $value): float
    {
        return (float) self::toFixed($value, 2);
    }

    /** Math.round(). */
    public static function jsRound(float|int $value): int
    {
        return (int) floor((float) $value + 0.5);
    }

    /** fmt: v==null?'—':v.toFixed(2) */
    public static function fmt(mixed $value): string
    {
        return $value === null ? '—' : self::toFixed((float) $value, 2);
    }

    /** f2: v==null||!isFinite(v)?'—':v.toFixed(2) */
    public static function f2(mixed $value): string
    {
        return $value === null || !is_finite((float) $value) ? '—' : self::toFixed((float) $value, 2);
    }

    /** pct: p==null||!isFinite(p)?'—':Math.round(p*100)+'%' */
    public static function pct(mixed $share): string
    {
        return $share === null || !is_finite((float) $share) ? '—' : self::jsRound((float) $share * 100) . '%';
    }

    /* ------------------------------------------------------------------ */
    /* Descriptive statistics                                               */
    /* ------------------------------------------------------------------ */

    /** nums(a): numbers only. @return list<float> */
    public static function nums(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $out[] = (float) $value;
            }
        }

        return $out;
    }

    /** mean(a): a.length? sum/len : null — summed left to right, as reduce() does. */
    public static function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($values as $value) {
            $sum += (float) $value;
        }

        return $sum / count($values);
    }

    /** sd(a): the population standard deviation; 0 below two values. */
    public static function sd(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $m   = self::mean($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ((float) $value - $m) ** 2;
        }

        return sqrt($sum / count($values));
    }

    /** quantile(a, p), by linear interpolation between order statistics. */
    public static function quantile(array $values, float $p): ?float
    {
        $sorted = self::nums($values);
        sort($sorted);

        if ($sorted === []) {
            return null;
        }

        $h  = (count($sorted) - 1) * $p;
        $lo = (int) floor($h);
        $hi = (int) ceil($h);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($h - $lo);
    }

    /**
     * describe(a): n, mean with a 95% confidence interval (normal
     * approximation), median, quartiles, range.
     *
     * @return array<string, mixed>
     */
    public static function describe(array $values): array
    {
        $v = self::nums($values);
        $n = count($v);

        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'sd' => null, 'lo' => null, 'hi' => null, 'median' => null, 'q1' => null, 'q3' => null, 'min' => null, 'max' => null];
        }

        $m  = self::mean($v);
        $ss = 0.0;
        foreach ($v as $x) {
            $ss += ($x - $m) ** 2;
        }
        $sd = $n > 1 ? sqrt($ss / ($n - 1)) : 0.0;
        $h  = $n > 1 ? 1.96 * $sd / sqrt($n) : null;

        return [
            'n'      => $n,
            'mean'   => $m,
            'sd'     => $sd,
            'lo'     => $h === null ? null : $m - $h,
            'hi'     => $h === null ? null : $m + $h,
            'median' => self::quantile($v, .5),
            'q1'     => self::quantile($v, .25),
            'q3'     => self::quantile($v, .75),
            'min'    => min($v),
            'max'    => max($v),
        ];
    }

    /** meanCI(d): "3.12 (2.98–3.26)" as HTML, or an em dash. */
    public static function meanCiHtml(array $d): string
    {
        if (($d['n'] ?? 0) === 0) {
            return '—';
        }

        return e(self::f2($d['mean']))
            . ($d['lo'] !== null ? ' <span class="muted">(' . e(self::f2($d['lo'])) . '–' . e(self::f2($d['hi'])) . ')</span>' : '');
    }

    /**
     * pearson(xs, ys): r with a Fisher-z 95% interval, each rounded to three
     * places as the page does; null below three pairs or with no variance.
     *
     * @return array{r:float, n:int, lo:float, hi:float, crosses:bool}|null
     */
    public static function pearson(array $xs, array $ys): ?array
    {
        $n = count($xs);

        if ($n < 3) {
            return null;
        }

        $mx = self::mean($xs);
        $my = self::mean($ys);
        $sxy = $sxx = $syy = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $a = (float) $xs[$i] - $mx;
            $b = (float) $ys[$i] - $my;
            $sxy += $a * $b;
            $sxx += $a * $a;
            $syy += $b * $b;
        }

        if ($sxx == 0.0 || $syy == 0.0) {
            return null;
        }

        $r  = $sxy / sqrt($sxx * $syy);
        $z  = 0.5 * log((1 + $r) / (1 - $r));
        $se = 1 / sqrt($n - 3);
        $lo = tanh($z - 1.96 * $se);
        $hi = tanh($z + 1.96 * $se);

        return [
            'r'       => (float) self::toFixed($r, 3),
            'n'       => $n,
            'lo'      => (float) self::toFixed($lo, 3),
            'hi'      => (float) self::toFixed($hi, 3),
            'crosses' => $lo < 0 && $hi > 0,
        ];
    }

    /** cronbach(rows): alpha from complete cases; null below three rows or two items. */
    public static function cronbach(array $rows): ?float
    {
        $k = $rows === [] ? 0 : count($rows[0]);

        if (count($rows) < 3 || $k < 2) {
            return null;
        }

        $variance = static function (array $a): float {
            $m  = array_sum($a) / count($a);
            $ss = 0.0;
            foreach ($a as $x) {
                $ss += ($x - $m) ** 2;
            }

            return $ss / (count($a) - 1);
        };

        $itemVar = 0.0;
        for ($j = 0; $j < $k; $j++) {
            $itemVar += $variance(array_column($rows, $j));
        }

        $totVar = $variance(array_map(static fn (array $r): float => (float) array_sum($r), $rows));

        return $totVar > 0 ? ($k / ($k - 1)) * (1 - $itemVar / $totVar) : null;
    }

    /* ------------------------------------------------------------------ */
    /* Instrument-dependent                                                 */
    /* ------------------------------------------------------------------ */

    /** band(score): the rung a score falls in, or null. @return array<string, mixed>|null */
    public function band(mixed $score): ?array
    {
        if ($score === null || !is_numeric($score) || is_nan((float) $score)) {
            return null;
        }

        $edges = $this->instrument->bandEdges();
        $i     = 0;

        while ($i < count($edges) && (float) $score >= $edges[$i]) {
            $i++;
        }

        return $this->instrument->rungs()[$i] ?? null;
    }

    /** The rung a stored level name refers to (RUNGS.find(r => r.name === level)). */
    public function rungNamed(?string $name): ?array
    {
        foreach ($this->instrument->rungs() as $rung) {
            if ($name !== null && $rung['name'] === $name) {
                return $rung;
            }
        }

        return null;
    }

    /** levelShares(a): count and share of scores at each level. @return array{n:int, counts:list<int>, shares:list<float>} */
    public function levelShares(array $values): array
    {
        $v      = self::nums($values);
        $counts = [];

        foreach ($this->instrument->rungs() as $rung) {
            $counts[] = count(array_filter($v, fn (float $x): bool => ($this->band($x)['v'] ?? null) === $rung['v']));
        }

        $n = count($v);

        return ['n' => $n, 'counts' => $counts, 'shares' => array_map(static fn (int $k): float => $n ? $k / $n : 0.0, $counts)];
    }

    /** bandRange(i): "below 1.80", "1.80 to 2.59", "4.20 and above". */
    public function bandRange(int $i): string
    {
        $edges = $this->instrument->bandEdges();
        $last  = count($this->instrument->rungs()) - 1;

        return match (true) {
            $i === 0     => 'below ' . self::toFixed($edges[0], 2),
            $i === $last => self::toFixed($edges[$i - 1], 2) . ' and above',
            default      => self::toFixed($edges[$i - 1], 2) . ' to ' . self::toFixed($edges[$i] - .01, 2),
        };
    }

    /**
     * aggregate(recs): per-skill n, mean (2dp), min, max, sd (2dp).
     *
     * @param list<array<string, mixed>> $recs
     * @return array<string, array{n:int, mean:?float, min:?float, max:?float, sd:?float}>
     */
    public function aggregate(array $recs): array
    {
        $out = [];

        foreach ($this->instrument->dims() as $dim) {
            $vals = [];
            foreach ($recs as $r) {
                $v = $r['dims'][$dim['code']] ?? null;
                if ($v !== null) {
                    $vals[] = (float) $v;
                }
            }

            $out[$dim['code']] = $vals === []
                ? ['n' => 0, 'mean' => null, 'min' => null, 'max' => null, 'sd' => null]
                : ['n' => count($vals), 'mean' => self::round2(self::mean($vals)), 'min' => min($vals), 'max' => max($vals), 'sd' => self::round2(self::sd($vals))];
        }

        return $out;
    }

    /** aggDims(agg): code => mean. @return array<string, ?float> */
    public static function aggDims(array $agg): array
    {
        return array_map(static fn (array $a): ?float => $a['mean'], $agg);
    }

    /**
     * summarize(dims): the overall mean (2dp) of the scored skills and its level.
     *
     * @param array<string, ?float> $dims
     * @return array{mean:?float, level:?array}
     */
    public function summarize(array $dims): array
    {
        $scored = [];
        foreach ($this->instrument->dims() as $dim) {
            $v = $dims[$dim['code']] ?? null;
            if ($v !== null) {
                $scored[] = (float) $v;
            }
        }

        if ($scored === []) {
            return ['mean' => null, 'level' => null];
        }

        $overall = self::round2(self::mean($scored));

        return ['mean' => $overall, 'level' => $this->band($overall)];
    }

    /**
     * nextStepPicks(rec): the action attached to each of the questions rated
     * lowest. Up to four, lowest rating first, no more than two from any skill.
     *
     * @return list<array{code:string, di:int, qi:int, v:int, d:array, q:array}>
     */
    public function nextStepPicks(array $rec): array
    {
        $items = [];

        foreach ($this->instrument->dims() as $di => $dim) {
            $responses = $rec['responses'][$dim['code']] ?? [];
            foreach ($dim['qs'] as $qi => $q) {
                $v = $responses[$qi] ?? null;
                if ($v !== null) {
                    $items[] = ['code' => $dim['code'], 'di' => $di, 'qi' => $qi, 'v' => (int) $v, 'd' => $dim, 'q' => $q];
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => $a['v'] <=> $b['v'] ?: $a['di'] <=> $b['di'] ?: $a['qi'] <=> $b['qi']);

        $per  = [];
        $pick = [];

        foreach ($items as $item) {
            if (count($pick) >= 4) {
                break;
            }
            if (($per[$item['code']] ?? 0) >= 2) {
                continue;
            }
            $per[$item['code']] = ($per[$item['code']] ?? 0) + 1;
            $pick[] = $item;
        }

        return $pick;
    }

    /* ------------------------------------------------------------------ */
    /* Records                                                              */
    /* ------------------------------------------------------------------ */

    /** byNewest: newest first, stable. @return list<array<string, mixed>> */
    public static function newestFirst(array $recs): array
    {
        usort($recs, static fn (array $a, array $b): int => strtotime((string) $b['timestamp']) <=> strtotime((string) $a['timestamp']));

        return $recs;
    }

    /**
     * orgCodes(recs): O001, O002 … in order of each organization's earliest
     * record, never alphabetically, so a code reveals nothing about a name.
     *
     * @return \Closure(array):string
     */
    public static function orgCodes(array $recs): \Closure
    {
        $first = [];
        foreach ($recs as $r) {
            $name = trim((string) ($r['org']['org'] ?? ''));
            if ($name === '') {
                continue;
            }
            $t = strtotime((string) $r['timestamp']);
            if (!array_key_exists($name, $first) || $t < $first[$name]) {
                $first[$name] = $t;
            }
        }

        // A stable sort on the time, so equal times keep first-seen order.
        $names = array_keys($first);
        usort($names, static fn (string $a, string $b): int => $first[$a] <=> $first[$b]);

        $map = [];
        foreach ($names as $i => $name) {
            $map[$name] = 'O' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
        }

        return static fn (array $r): string => $map[trim((string) ($r['org']['org'] ?? ''))] ?? '';
    }

    /**
     * norm(s): `toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')`.
     *
     * PHP's Normalizer needs the intl extension, which this XAMPP (and many
     * hosts) does not load, so without it the decomposition comes from FOLD:
     * every lower-case letter from U+00C0 to U+1EFF that NFD splits into a
     * base letter and accents, generated from JavaScript's own normalize(), so
     * both paths fold exactly as the page does. A letter NFD leaves whole
     * (ø, ß, æ) stays whole on both.
     */
    public static function norm(mixed $value): string
    {
        $s = mb_strtolower((string) ($value ?? ''), 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $s = (string) \Normalizer::normalize($s, \Normalizer::FORM_D);
        } else {
            $s = strtr($s, self::FOLD);
        }

        return (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
    }

    /** Lower-case letter => its NFD base, accents removed (see norm()). */
    private const FOLD = [        "à" => "a", "á" => "a", "â" => "a", "ã" => "a", "ä" => "a", "å" => "a", "ç" => "c", "è" => "e", "é" => "e", "ê" => "e", "ë" => "e", "ì" => "i",
        "í" => "i", "î" => "i", "ï" => "i", "ñ" => "n", "ò" => "o", "ó" => "o", "ô" => "o", "õ" => "o", "ö" => "o", "ù" => "u", "ú" => "u", "û" => "u",
        "ü" => "u", "ý" => "y", "ÿ" => "y", "ā" => "a", "ă" => "a", "ą" => "a", "ć" => "c", "ĉ" => "c", "ċ" => "c", "č" => "c", "ď" => "d", "ē" => "e",
        "ĕ" => "e", "ė" => "e", "ę" => "e", "ě" => "e", "ĝ" => "g", "ğ" => "g", "ġ" => "g", "ģ" => "g", "ĥ" => "h", "ĩ" => "i", "ī" => "i", "ĭ" => "i",
        "į" => "i", "i̇" => "i", "ĵ" => "j", "ķ" => "k", "ĺ" => "l", "ļ" => "l", "ľ" => "l", "ń" => "n", "ņ" => "n", "ň" => "n", "ō" => "o", "ŏ" => "o",
        "ő" => "o", "ŕ" => "r", "ŗ" => "r", "ř" => "r", "ś" => "s", "ŝ" => "s", "ş" => "s", "š" => "s", "ţ" => "t", "ť" => "t", "ũ" => "u", "ū" => "u",
        "ŭ" => "u", "ů" => "u", "ű" => "u", "ų" => "u", "ŵ" => "w", "ŷ" => "y", "ź" => "z", "ż" => "z", "ž" => "z", "ơ" => "o", "ư" => "u", "ǎ" => "a",
        "ǐ" => "i", "ǒ" => "o", "ǔ" => "u", "ǖ" => "u", "ǘ" => "u", "ǚ" => "u", "ǜ" => "u", "ǟ" => "a", "ǡ" => "a", "ǣ" => "æ", "ǧ" => "g", "ǩ" => "k",
        "ǫ" => "o", "ǭ" => "o", "ǯ" => "ʒ", "ǰ" => "j", "ǵ" => "g", "ǹ" => "n", "ǻ" => "a", "ǽ" => "æ", "ǿ" => "ø", "ȁ" => "a", "ȃ" => "a", "ȅ" => "e",
        "ȇ" => "e", "ȉ" => "i", "ȋ" => "i", "ȍ" => "o", "ȏ" => "o", "ȑ" => "r", "ȓ" => "r", "ȕ" => "u", "ȗ" => "u", "ș" => "s", "ț" => "t", "ȟ" => "h",
        "ȧ" => "a", "ȩ" => "e", "ȫ" => "o", "ȭ" => "o", "ȯ" => "o", "ȱ" => "o", "ȳ" => "y", "ʹ" => "ʹ", ";" => ";", "΅" => "¨", "ά" => "α", "·" => "·",
        "έ" => "ε", "ή" => "η", "ί" => "ι", "ό" => "ο", "ύ" => "υ", "ώ" => "ω", "ΐ" => "ι", "ϊ" => "ι", "ϋ" => "υ", "ΰ" => "υ", "ϓ" => "ϒ", "ϔ" => "ϒ",
        "ѐ" => "е", "ё" => "е", "ѓ" => "г", "ї" => "і", "ќ" => "к", "ѝ" => "и", "ў" => "у", "й" => "и", "ѷ" => "ѵ", "ӂ" => "ж", "ӑ" => "а", "ӓ" => "а",
        "ӗ" => "е", "ӛ" => "ә", "ӝ" => "ж", "ӟ" => "з", "ӣ" => "и", "ӥ" => "и", "ӧ" => "о", "ӫ" => "ө", "ӭ" => "э", "ӯ" => "у", "ӱ" => "у", "ӳ" => "у",
        "ӵ" => "ч", "ӹ" => "ы", "آ" => "آ", "أ" => "أ", "ؤ" => "ؤ", "إ" => "إ", "ئ" => "ئ", "ۀ" => "ۀ", "ۂ" => "ۂ", "ۓ" => "ۓ", "ऩ" => "ऩ", "ऱ" => "ऱ",
        "ऴ" => "ऴ", "क़" => "क़", "ख़" => "ख़", "ग़" => "ग़", "ज़" => "ज़", "ड़" => "ड़", "ढ़" => "ढ़", "फ़" => "फ़", "य़" => "य़", "ো" => "ো", "ৌ" => "ৌ", "ড়" => "ড়",
        "ঢ়" => "ঢ়", "য়" => "য়", "ਲ਼" => "ਲ਼", "ਸ਼" => "ਸ਼", "ਖ਼" => "ਖ਼", "ਗ਼" => "ਗ਼", "ਜ਼" => "ਜ਼", "ਫ਼" => "ਫ਼", "ୈ" => "ୈ", "ୋ" => "ୋ", "ୌ" => "ୌ", "ଡ଼" => "ଡ଼",
        "ଢ଼" => "ଢ଼", "ஔ" => "ஔ", "ொ" => "ொ", "ோ" => "ோ", "ௌ" => "ௌ", "ై" => "ై", "ೀ" => "ೀ", "ೇ" => "ೇ", "ೈ" => "ೈ", "ೊ" => "ೊ", "ೋ" => "ೋ", "ൊ" => "ൊ",
        "ോ" => "ോ", "ൌ" => "ൌ", "ේ" => "ේ", "ො" => "ො", "ෝ" => "ෝ", "ෞ" => "ෞ", "གྷ" => "གྷ", "ཌྷ" => "ཌྷ", "དྷ" => "དྷ", "བྷ" => "བྷ", "ཛྷ" => "ཛྷ", "ཀྵ" => "ཀྵ",
        "ཱི" => "ཱི", "ཱུ" => "ཱུ", "ྲྀ" => "ྲྀ", "ླྀ" => "ླྀ", "ཱྀ" => "ཱྀ", "ྒྷ" => "ྒྷ", "ྜྷ" => "ྜྷ", "ྡྷ" => "ྡྷ", "ྦྷ" => "ྦྷ", "ྫྷ" => "ྫྷ", "ྐྵ" => "ྐྵ", "ဦ" => "ဦ",
        "ᬆ" => "ᬆ", "ᬈ" => "ᬈ", "ᬊ" => "ᬊ", "ᬌ" => "ᬌ", "ᬎ" => "ᬎ", "ᬒ" => "ᬒ", "ᬻ" => "ᬻ", "ᬽ" => "ᬽ", "ᭀ" => "ᭀ", "ᭁ" => "ᭁ", "ᭃ" => "ᭃ", "ḁ" => "a",
        "ḃ" => "b", "ḅ" => "b", "ḇ" => "b", "ḉ" => "c", "ḋ" => "d", "ḍ" => "d", "ḏ" => "d", "ḑ" => "d", "ḓ" => "d", "ḕ" => "e", "ḗ" => "e", "ḙ" => "e",
        "ḛ" => "e", "ḝ" => "e", "ḟ" => "f", "ḡ" => "g", "ḣ" => "h", "ḥ" => "h", "ḧ" => "h", "ḩ" => "h", "ḫ" => "h", "ḭ" => "i", "ḯ" => "i", "ḱ" => "k",
        "ḳ" => "k", "ḵ" => "k", "ḷ" => "l", "ḹ" => "l", "ḻ" => "l", "ḽ" => "l", "ḿ" => "m", "ṁ" => "m", "ṃ" => "m", "ṅ" => "n", "ṇ" => "n", "ṉ" => "n",
        "ṋ" => "n", "ṍ" => "o", "ṏ" => "o", "ṑ" => "o", "ṓ" => "o", "ṕ" => "p", "ṗ" => "p", "ṙ" => "r", "ṛ" => "r", "ṝ" => "r", "ṟ" => "r", "ṡ" => "s",
        "ṣ" => "s", "ṥ" => "s", "ṧ" => "s", "ṩ" => "s", "ṫ" => "t", "ṭ" => "t", "ṯ" => "t", "ṱ" => "t", "ṳ" => "u", "ṵ" => "u", "ṷ" => "u", "ṹ" => "u",
        "ṻ" => "u", "ṽ" => "v", "ṿ" => "v", "ẁ" => "w", "ẃ" => "w", "ẅ" => "w", "ẇ" => "w", "ẉ" => "w", "ẋ" => "x", "ẍ" => "x", "ẏ" => "y", "ẑ" => "z",
        "ẓ" => "z", "ẕ" => "z", "ẖ" => "h", "ẗ" => "t", "ẘ" => "w", "ẙ" => "y", "ẛ" => "ſ", "ạ" => "a", "ả" => "a", "ấ" => "a", "ầ" => "a", "ẩ" => "a",
        "ẫ" => "a", "ậ" => "a", "ắ" => "a", "ằ" => "a", "ẳ" => "a", "ẵ" => "a", "ặ" => "a", "ẹ" => "e", "ẻ" => "e", "ẽ" => "e", "ế" => "e", "ề" => "e",
        "ể" => "e", "ễ" => "e", "ệ" => "e", "ỉ" => "i", "ị" => "i", "ọ" => "o", "ỏ" => "o", "ố" => "o", "ồ" => "o", "ổ" => "o", "ỗ" => "o", "ộ" => "o",
        "ớ" => "o", "ờ" => "o", "ở" => "o", "ỡ" => "o", "ợ" => "o", "ụ" => "u", "ủ" => "u", "ứ" => "u", "ừ" => "u", "ử" => "u", "ữ" => "u", "ự" => "u",
        "ỳ" => "y", "ỵ" => "y", "ỷ" => "y", "ỹ" => "y",
    ];

    /** recHaystack(r): what the search matches against. */
    public static function haystack(array $r): string
    {
        $org = $r['org'] ?? [];

        return self::norm(implode(' ', array_filter([
            $r['userName'] ?? null, $org['name'] ?? null, $r['userEmail'] ?? null, $org['email'] ?? null,
            $org['org'] ?? null, $org['role'] ?? null, $org['sector'] ?? null, $r['level'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== '')))
            . ' ' . preg_replace('/\D/', '', (string) (($r['userPhone'] ?? '') ?: ($org['phone'] ?? '')));
    }

    /** matchRec(r, q): every word of the query appears. */
    public static function matches(array $r, string $query): bool
    {
        $needle = trim(self::norm($query));

        if ($needle === '') {
            return true;
        }

        $hay = self::haystack($r);

        foreach (preg_split('/\s+/', $needle) ?: [] as $word) {
            if (!str_contains($hay, $word)) {
                return false;
            }
        }

        return true;
    }
}
