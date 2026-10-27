<?php

declare(strict_types=1);

use Platform\Core\Database;
use Platform\Core\Seeder;

/**
 * Loads instrument v1.0 into the database.
 *
 * The source of truth is seeds/data/instrument-v1.json, which was dumped
 * verbatim out of the legacy reference/RAIX_Director_Scorecard/index.html rather than
 * retyped — 7 competencies, 35 statements, 35 evidence prompts, 4 rungs and
 * their band cut points. Nothing in this file contains instrument wording, so
 * there is no opportunity for a transcription error to enter the dataset.
 *
 * Idempotent: if version 1.0 already exists it is left alone. A published
 * instrument is immutable by design — changing the wording means publishing a
 * new version, so that submissions keep scoring the way they were scored.
 */
return new class extends Seeder {
    public function name(): string
    {
        return 'Director Scorecard instrument v1.0';
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
                'scale'        => $data['scale'] ?? '4 levels',
                'scoring_rule' => $data['scoring_rule'] ?? 'floor',
                'rule_text'    => $data['rule_text'] ?? null,
                'status'       => 'published',
                'published_at' => $this->now(),
                'notes'        => 'Imported from ' . ($data['source'] ?? 'the legacy single-file build') . '.',
                'created_at'   => $this->now(),
            ]);

            $this->seedRungs($instrumentId, $data);
            $this->seedCompetencies($instrumentId, $data['competencies']);
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

    private function seedCompetencies(int $instrumentId, array $competencies): void
    {
        $items = 0;
        $prompts = 0;

        foreach ($competencies as $competency) {
            $competencyId = Database::insert($this->table('competencies'), [
                'instrument_id'   => $instrumentId,
                'position'        => $competency['position'],
                'code'            => $competency['code'],
                'name'            => $competency['name'],
                'description'     => $competency['description'],
                'board_question'  => $competency['board_question'],
                'failure_line'    => $competency['failure_line'],
                'guide_what'      => $competency['guide']['what']    ?? null,
                'guide_why'       => $competency['guide']['why']     ?? null,
                'guide_how'       => $competency['guide']['how']     ?? null,
                'guide_example'   => $competency['guide']['example'] ?? null,
                'floor_narrative' => $competency['floor_narrative']  ?? null,
            ]);

            foreach ($competency['items'] as $item) {
                Database::insert($this->table('items'), [
                    'competency_id' => $competencyId,
                    'position'      => $item['position'],
                    'statement'     => $item['statement'],
                ]);
                $items++;
            }

            foreach ($competency['evidence_prompts'] as $prompt) {
                Database::insert($this->table('evidence_prompts'), [
                    'competency_id' => $competencyId,
                    'position'      => $prompt['position'],
                    'template'      => $prompt['template'],
                ]);
                $prompts++;
            }
        }

        $this->say(sprintf('  competencies       %d', count($competencies)));
        $this->say(sprintf('  items              %d', $items));
        $this->say(sprintf('  evidence prompts   %d', $prompts));
    }

    /** Sector / size / role / tenure dropdowns, editable without a deploy. */
    private function seedReferenceOptions(int $instrumentId, array $reference): void
    {
        $groups = [
            'sectors'       => $reference['sectors']       ?? [],
            'company_sizes' => $reference['company_sizes'] ?? [],
            'roles'         => $reference['roles']         ?? [],
            'tenures'       => $reference['tenures']       ?? [],
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
