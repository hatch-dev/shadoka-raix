<?php

declare(strict_types=1);

use Platform\Core\Database;
use Platform\Core\Seeder;

/**
 * Loads the Human Edge Scorecard instrument v1.0 into the database.
 *
 * The source of truth is seeds/data/instrument-v1.json, which was dumped out of
 * Human_Edge_Scorecard/index.html by seeds/data/extract-instrument.mjs rather
 * than retyped — 6 skills, 18 questions with both of their written anchors and
 * their next-step advice, 5 rungs and their band cut points, and every
 * reference list. Nothing in this file contains instrument wording, so there is
 * no opportunity for a transcription error to enter the dataset.
 * instrument_integrity_test.php checks the loaded rows against the same JSON.
 *
 * Idempotent: if version 1.0 already exists it is left alone. A published
 * instrument is immutable by design.
 */
return new class extends Seeder {
    public function name(): string
    {
        return 'Human Edge Scorecard instrument v1.0';
    }

    public function run(): void
    {
        $file = __DIR__ . '/data/instrument-v1.json';

        if (!is_file($file)) {
            $this->say('SKIPPED: data/instrument-v1.json is missing.');
            return;
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        $existing = Database::selectOne(
            sprintf('SELECT `id`, `status` FROM `%s` WHERE `version` = ?', $this->table('instruments')),
            [$data['version']]
        );

        if ($existing !== null) {
            $this->say(sprintf(
                'instrument v%s already present (id %d, %s) — left untouched',
                $data['version'],
                $existing['id'],
                $existing['status']
            ));
            return;
        }

        Database::transaction(function () use ($data): void {
            $instrumentId = Database::insert($this->table('instruments'), [
                'version'      => $data['version'],
                'name'         => $data['name'],
                'subtitle'     => $data['subtitle'] ?? null,
                'scale'        => $data['scale'] ?? '5 levels',
                'scoring_rule' => $data['scoring_rule'] ?? 'mean',
                'rule_text'    => $data['rule_text'] ?? null,
                'status'       => 'published',
                'published_at' => $this->now(),
                'notes'        => 'Imported from ' . ($data['source'] ?? 'the supplied page') . '.',
                'created_at'   => $this->now(),
            ]);

            $this->seedRungs($instrumentId, $data);
            $this->seedDimensions($instrumentId, $data['dimensions']);
            $this->seedReferenceOptions($instrumentId, $data['reference'] ?? []);

            $this->say(sprintf('instrument v%s published as id %d', $data['version'], $instrumentId));
        });
    }

    /** Rungs carry their own band cut points so a version scores consistently forever. */
    private function seedRungs(int $instrumentId, array $data): void
    {
        $bands = [];
        foreach ($data['bands'] ?? [] as $band) {
            $bands[(int) $band['rung_value']] = $band;
        }

        foreach ($data['rungs'] as $rung) {
            $value = (int) $rung['value'];
            $band  = $bands[$value] ?? ['min' => null, 'max' => null];

            Database::insert($this->table('rungs'), [
                'instrument_id' => $instrumentId,
                'value'         => $value,
                'name'          => $rung['name'],
                'description'   => $rung['description'],
                'colour_hex'    => $rung['colour_hex'],
                'band_min'      => $band['min'],
                'band_max'      => $band['max'],
            ]);
        }

        $this->say(sprintf('  rungs              %d', count($data['rungs'])));
    }

    private function seedDimensions(int $instrumentId, array $dimensions): void
    {
        $items = 0;

        foreach ($dimensions as $dimension) {
            $dimensionId = Database::insert($this->table('dimensions'), [
                'instrument_id'     => $instrumentId,
                'position'          => $dimension['position'],
                'code'              => $dimension['code'],
                'name'              => $dimension['name'],
                'subtitle'          => $dimension['subtitle'],
                'description'       => $dimension['description'],
                'pressing_question' => $dimension['pressing_question'],
                'summary_line'      => $dimension['summary_line'],
                'failure_line'      => $dimension['failure_line'],
                'guide_what'        => $dimension['guide']['what'] ?? null,
                'guide_why'         => $dimension['guide']['why']  ?? null,
                'guide_how'         => $dimension['guide']['how']  ?? null,
            ]);

            foreach ($dimension['items'] as $item) {
                Database::insert($this->table('items'), [
                    'dimension_id'      => $dimensionId,
                    'position'          => $item['position'],
                    'label'             => $item['label'],
                    'question'          => $item['question'],
                    'anchor_low_label'  => $item['anchor_low_label'],
                    'anchor_low_text'   => $item['anchor_low_text'],
                    'anchor_high_label' => $item['anchor_high_label'],
                    'anchor_high_text'  => $item['anchor_high_text'],
                    'next_step'         => $item['next_step'],
                ]);
                $items++;
            }
        }

        $this->say(sprintf('  skills             %d', count($dimensions)));
        $this->say(sprintf('  questions          %d', $items));
    }

    /** Sector / size / role / tenure dropdowns. */
    private function seedReferenceOptions(int $instrumentId, array $reference): void
    {
        $groups = [
            'sectors'            => $reference['sectors']            ?? [],
            'organisation_sizes' => $reference['organisation_sizes'] ?? [],
            'roles'              => $reference['roles']              ?? [],
            'tenures'            => $reference['tenures']            ?? [],
        ];

        $total = 0;
        foreach ($groups as $group => $values) {
            foreach (array_values($values) as $index => $value) {
                Database::insert($this->table('reference_options'), [
                    'instrument_id' => $instrumentId,
                    'group_key'     => $group,
                    'position'      => $index + 1,
                    'value'         => $value,
                    'is_active'     => 1,
                ]);
                $total++;
            }
        }

        $this->say(sprintf('  reference options  %d', $total));
    }
};
