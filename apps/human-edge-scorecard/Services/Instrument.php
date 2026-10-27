<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * The published instrument, read from he_instruments and its tables, in the
 * page's own shapes: DIMS, RUNGS, BAND_EDGES and the reference lists.
 *
 * The console's screens are ports of the page's own admin functions, which
 * are written against those constants. Giving them the same shapes — from the
 * database, never retyped — is what lets each port stay a line-for-line
 * reading of the original.
 *
 * Internal keys a port needs but the page's constants did not carry (row ids)
 * start with an underscore.
 */
final class Instrument
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public function __construct(private readonly AppManifest $app)
    {
    }

    /** Forget what this request has read. Tests call it after seeding. */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array<string, mixed> the instrument row */
    public function row(): array
    {
        return $this->load()['row'];
    }

    public function id(): int
    {
        return (int) $this->row()['id'];
    }

    /**
     * DIMS: [{code, name, sub, line, desc, ask, fail, guide:{what,why,how},
     *         qs:[{t, q, l1, a1, l5, a5, next, _id}], _id}]
     *
     * @return list<array<string, mixed>>
     */
    public function dims(): array
    {
        return $this->load()['dims'];
    }

    /** DIM_BY: code => dimension. @return array<string, array<string, mixed>> */
    public function dimBy(): array
    {
        $by = [];
        foreach ($this->dims() as $dim) {
            $by[$dim['code']] = $dim;
        }

        return $by;
    }

    /** RUNGS: [{v, label, name, desc, c, hex}]. @return list<array<string, mixed>> */
    public function rungs(): array
    {
        return $this->load()['rungs'];
    }

    /** BAND_EDGES: the lower bound of levels 2 to 5. @return list<float> */
    public function bandEdges(): array
    {
        return $this->load()['edges'];
    }

    /** SECTORS, SIZES, ROLES, TENURES. @return list<string> */
    public function options(string $group): array
    {
        return $this->load()['options'][$group] ?? [];
    }

    /** item id => [code, zero-based index]. @return array<int, array{0:string, 1:int}> */
    public function itemIndex(): array
    {
        $index = [];
        foreach ($this->dims() as $dim) {
            foreach ($dim['qs'] as $i => $q) {
                $index[$q['_id']] = [$dim['code'], $i];
            }
        }

        return $index;
    }

    /**
     * The constants a browser-side port (PDFs, charts) needs, without the
     * internal keys.
     *
     * @return array<string, mixed>
     */
    public function forBrowser(): array
    {
        $strip = static function (array $value) use (&$strip): array {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && str_starts_with($key, '_')) {
                    continue;
                }
                $out[$key] = is_array($item) ? $strip($item) : $item;
            }

            return $out;
        };

        return [
            'DIMS'       => $strip($this->dims()),
            'RUNGS'      => $this->rungs(),
            'BAND_EDGES' => $this->bandEdges(),
        ];
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $key = $this->app->slug;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $row = Database::selectOne(sprintf(
            "SELECT * FROM `%s` WHERE `status` = 'published' ORDER BY `published_at` DESC, `id` DESC LIMIT 1",
            $this->app->table('instruments')
        ));

        if ($row === null) {
            throw new RuntimeException('The Human Edge Scorecard has no published instrument. Run: php scripts/seed.php --app=' . $this->app->slug);
        }

        $id = (int) $row['id'];

        $items = [];
        foreach (Database::select(sprintf(
            'SELECT i.* FROM `%s` i JOIN `%s` d ON d.`id` = i.`dimension_id` WHERE d.`instrument_id` = ? ORDER BY i.`position`',
            $this->app->table('items'),
            $this->app->table('dimensions')
        ), [$id]) as $item) {
            $items[(int) $item['dimension_id']][] = [
                't'    => (string) $item['label'],
                'q'    => (string) $item['question'],
                'l1'   => (string) $item['anchor_low_label'],
                'a1'   => (string) $item['anchor_low_text'],
                'l5'   => (string) $item['anchor_high_label'],
                'a5'   => (string) $item['anchor_high_text'],
                'next' => (string) $item['next_step'],
                '_id'  => (int) $item['id'],
            ];
        }

        $dims = [];
        foreach (Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->app->table('dimensions')
        ), [$id]) as $dim) {
            $dims[] = [
                'code'  => (string) $dim['code'],
                'name'  => (string) $dim['name'],
                'sub'   => (string) $dim['subtitle'],
                'line'  => (string) $dim['summary_line'],
                'desc'  => (string) $dim['description'],
                'ask'   => (string) $dim['pressing_question'],
                'fail'  => (string) $dim['failure_line'],
                'guide' => [
                    'what' => (string) $dim['guide_what'],
                    'why'  => (string) $dim['guide_why'],
                    'how'  => (string) $dim['guide_how'],
                ],
                'qs'    => $items[(int) $dim['id']] ?? [],
                '_id'   => (int) $dim['id'],
            ];
        }

        $rungs = [];
        $edges = [];
        foreach (Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`',
            $this->app->table('rungs')
        ), [$id]) as $rung) {
            $rungs[] = [
                'v'     => (int) $rung['value'],
                'label' => (string) $rung['name'],
                'name'  => (string) $rung['name'],
                'desc'  => (string) $rung['description'],
                'c'     => 'var(--r' . (int) $rung['value'] . ')',
                'hex'   => (string) $rung['colour_hex'],
            ];

            if ($rung['band_min'] !== null) {
                $edges[] = (float) $rung['band_min'];
            }
        }

        $options = [];
        foreach (Database::select(sprintf(
            'SELECT `group_key`, `value` FROM `%s` WHERE `instrument_id` = ? AND `is_active` = 1 ORDER BY `group_key`, `position`',
            $this->app->table('reference_options')
        ), [$id]) as $option) {
            $options[(string) $option['group_key']][] = (string) $option['value'];
        }

        return self::$cache[$key] = [
            'row'     => $row,
            'dims'    => $dims,
            'rungs'   => $rungs,
            'edges'   => $edges,
            'options' => $options,
        ];
    }
}
