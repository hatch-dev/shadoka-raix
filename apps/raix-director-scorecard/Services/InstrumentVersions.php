<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Services;

use Platform\Core\AppManifest;
use Platform\Core\Database;
use RuntimeException;

/**
 * Editing the instrument, safely.
 *
 * The rule the whole class exists to enforce: **a published version is never
 * edited in place.** Submissions pin to an instrument row, so changing the
 * wording of an item would silently rewrite the meaning of every score already
 * taken against it — and nobody would find out, because old records keep
 * rendering perfectly.
 *
 * So editing means: clone the published version into a draft, change the draft,
 * then publish it. The old version stays exactly as it was, archived, still
 * answering for the submissions attached to it.
 *
 * That also means the two versions must stay structurally comparable. A draft
 * may reword an item; it may not delete one, because `ds_submission_responses`
 * rows point at item ids and a competency that loses an item stops being the
 * same measurement. Adding and removing items is a bigger change than this
 * editor allows, deliberately.
 */
final class InstrumentVersions
{
    public function __construct(private readonly AppManifest $app)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Reading                                                             */
    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> newest first */
    public function all(): array
    {
        return Database::select(sprintf(
            'SELECT i.*,
                    (SELECT COUNT(*) FROM `%s` s WHERE s.`instrument_id` = i.`id`) AS submissions
               FROM `%s` i
           ORDER BY i.`id` DESC',
            $this->app->table('submissions'),
            $this->app->table('instruments')
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ?', $this->app->table('instruments')),
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function published(): ?array
    {
        return Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `published_at` DESC LIMIT 1',
            $this->app->table('instruments')
        ), ['published']);
    }

    /**
     * The draft, if one exists.
     *
     * Only ever one at a time. Two concurrent drafts would race each other to
     * publish and the loser's edits would vanish without explanation.
     */
    public function draft(): ?array
    {
        return Database::selectOne(sprintf(
            'SELECT * FROM `%s` WHERE `status` = ? ORDER BY `id` DESC LIMIT 1',
            $this->app->table('instruments')
        ), ['draft']);
    }

    public function submissionCount(int $instrumentId): int
    {
        return (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `instrument_id` = ?', $this->app->table('submissions')),
            [$instrumentId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Creating a draft                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Clone a version into a new draft.
     *
     * Everything is copied: competencies, items, evidence prompts, rungs with
     * their band cut points, and the reference lists. The clone is a complete,
     * self-contained instrument, so publishing it later changes nothing about
     * the version it came from.
     *
     * @return int the new draft's id
     */
    public function createDraft(int $sourceId, string $version, ?string $notes = null): int
    {
        $source = $this->find($sourceId);

        if ($source === null) {
            throw new RuntimeException('That version does not exist.');
        }

        if ($this->draft() !== null) {
            throw new RuntimeException('A draft already exists. Publish or discard it first.');
        }

        $version = trim($version);

        if ($version === '') {
            throw new RuntimeException('Give the new version a number.');
        }

        if ($this->versionExists($version)) {
            throw new RuntimeException("Version {$version} already exists.");
        }

        return Database::transaction(function () use ($source, $version, $notes): int {
            $draftId = Database::insert($this->app->table('instruments'), [
                'version'      => $version,
                'name'         => $source['name'],
                'subtitle'     => $source['subtitle'],
                'scale'        => $source['scale'],
                'scoring_rule' => $source['scoring_rule'],
                'rule_text'    => $source['rule_text'],
                'status'       => 'draft',
                'notes'        => $notes ?? sprintf('Drafted from version %s.', $source['version']),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            $this->copyRungs((int) $source['id'], $draftId);
            $this->copyReferenceOptions((int) $source['id'], $draftId);
            $this->copyCompetencies((int) $source['id'], $draftId);

            return $draftId;
        });
    }

    private function copyRungs(int $fromId, int $toId): void
    {
        foreach (Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->app->table('rungs')),
            [$fromId]
        ) as $rung) {
            Database::insert($this->app->table('rungs'), [
                'instrument_id' => $toId,
                'value'         => $rung['value'],
                'name'          => $rung['name'],
                'description'   => $rung['description'],
                'colour_hex'    => $rung['colour_hex'],
                'band_min'      => $rung['band_min'],
                'band_max'      => $rung['band_max'],
            ]);
        }
    }

    private function copyReferenceOptions(int $fromId, int $toId): void
    {
        foreach (Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ?', $this->app->table('reference_options')),
            [$fromId]
        ) as $option) {
            Database::insert($this->app->table('reference_options'), [
                'instrument_id' => $toId,
                'group_key'     => $option['group_key'],
                'position'      => $option['position'],
                'value'         => $option['value'],
                'is_active'     => $option['is_active'],
            ]);
        }
    }

    private function copyCompetencies(int $fromId, int $toId): void
    {
        foreach (Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`', $this->app->table('competencies')),
            [$fromId]
        ) as $competency) {
            $newId = Database::insert($this->app->table('competencies'), [
                'instrument_id'   => $toId,
                'position'        => $competency['position'],
                'code'            => $competency['code'],
                'name'            => $competency['name'],
                'description'     => $competency['description'],
                'board_question'  => $competency['board_question'],
                'failure_line'    => $competency['failure_line'],
                'guide_what'      => $competency['guide_what'],
                'guide_why'       => $competency['guide_why'],
                'guide_how'       => $competency['guide_how'],
                'guide_example'   => $competency['guide_example'],
                'floor_narrative' => $competency['floor_narrative'],
            ]);

            foreach (Database::select(
                sprintf('SELECT * FROM `%s` WHERE `competency_id` = ? ORDER BY `position`', $this->app->table('items')),
                [$competency['id']]
            ) as $item) {
                Database::insert($this->app->table('items'), [
                    'competency_id' => $newId,
                    'position'      => $item['position'],
                    'statement'     => $item['statement'],
                ]);
            }

            foreach (Database::select(
                sprintf('SELECT * FROM `%s` WHERE `competency_id` = ? ORDER BY `position`', $this->app->table('evidence_prompts')),
                [$competency['id']]
            ) as $prompt) {
                Database::insert($this->app->table('evidence_prompts'), [
                    'competency_id' => $newId,
                    'position'      => $prompt['position'],
                    'template'      => $prompt['template'],
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Editing a draft                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Save changes to one competency of a draft.
     *
     * @param array<string, mixed> $fields
     * @param array<int, string>   $statements item id => new statement
     * @return list<string> a human-readable list of what changed
     */
    public function updateCompetency(int $draftId, int $competencyId, array $fields, array $statements): array
    {
        $this->assertDraft($draftId);

        $competency = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `id` = ? AND `instrument_id` = ?', $this->app->table('competencies')),
            [$competencyId, $draftId]
        );

        if ($competency === null) {
            throw new RuntimeException('That competency does not belong to this draft.');
        }

        $editable = [
            'name'            => 'Name',
            'description'     => 'Description',
            'board_question'  => 'Board question',
            'failure_line'    => 'Failing signature',
            'guide_what'      => 'Guide: what it is',
            'guide_why'       => 'Guide: why it matters',
            'guide_how'       => 'Guide: how to build it',
            'guide_example'   => 'Guide: what it looks like',
            'floor_narrative' => 'Floor narrative',
        ];

        $changes  = [];
        $updates  = [];
        $bindings = [];

        foreach ($editable as $column => $label) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }

            $value = trim((string) $fields[$column]);
            $value = $value === '' ? null : $value;

            if ($value === $competency[$column]) {
                continue;
            }

            $updates[]  = "`{$column}` = ?";
            $bindings[] = $value;
            $changes[]  = $label;
        }

        // The code is NOT editable. It is how a competency is identified in
        // every export, every analytics query and every codebook already
        // published; renaming it would break comparability across versions for
        // no gain the name field does not already offer.

        if ($updates !== []) {
            $bindings[] = $competencyId;
            Database::statement(
                sprintf('UPDATE `%s` SET %s WHERE `id` = ?', $this->app->table('competencies'), implode(', ', $updates)),
                $bindings
            );
        }

        foreach ($statements as $itemId => $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                throw new RuntimeException('A statement cannot be empty. Reword it rather than clearing it.');
            }

            $item = Database::selectOne(
                sprintf('SELECT * FROM `%s` WHERE `id` = ? AND `competency_id` = ?', $this->app->table('items')),
                [(int) $itemId, $competencyId]
            );

            if ($item === null || $item['statement'] === $statement) {
                continue;
            }

            Database::statement(
                sprintf('UPDATE `%s` SET `statement` = ? WHERE `id` = ?', $this->app->table('items')),
                [$statement, (int) $itemId]
            );

            $changes[] = $competency['code'] . ' ' . $item['position'];
        }

        if ($changes !== []) {
            Database::statement(
                sprintf('UPDATE `%s` SET `updated_at` = ? WHERE `id` = ?', $this->app->table('instruments')),
                [date('Y-m-d H:i:s'), $draftId]
            );
        }

        return $changes;
    }

    /* ------------------------------------------------------------------ */
    /* Publishing                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Publish a draft and archive whatever was published before it.
     *
     * The previous version is archived rather than deleted: its submissions
     * still point at it, and they must keep scoring the way they scored.
     *
     * @return array{published: string, archived: ?string}
     */
    public function publish(int $draftId): array
    {
        $draft = $this->assertDraft($draftId);

        $problems = $this->validate($draftId);

        if ($problems !== []) {
            throw new RuntimeException('This draft is not ready: ' . implode(' ', $problems));
        }

        $previous = $this->published();

        Database::transaction(function () use ($draftId, $previous): void {
            if ($previous !== null) {
                Database::statement(
                    sprintf('UPDATE `%s` SET `status` = ?, `updated_at` = ? WHERE `id` = ?', $this->app->table('instruments')),
                    ['archived', date('Y-m-d H:i:s'), $previous['id']]
                );
            }

            Database::statement(
                sprintf(
                    'UPDATE `%s` SET `status` = ?, `published_at` = ?, `updated_at` = ? WHERE `id` = ?',
                    $this->app->table('instruments')
                ),
                ['published', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $draftId]
            );
        });

        return [
            'published' => (string) $draft['version'],
            'archived'  => $previous === null ? null : (string) $previous['version'],
        ];
    }

    /**
     * Throw away a draft.
     *
     * Only ever a draft, and only one with no submissions — a version someone
     * has already scored against is not a draft, whatever its status column
     * says, and deleting it would orphan real data.
     */
    public function discard(int $draftId): void
    {
        $this->assertDraft($draftId);

        if ($this->submissionCount($draftId) > 0) {
            throw new RuntimeException('That version has submissions against it and cannot be discarded.');
        }

        // Competencies, items, prompts, rungs and reference options all cascade.
        Database::statement(
            sprintf('DELETE FROM `%s` WHERE `id` = ? AND `status` = ?', $this->app->table('instruments')),
            [$draftId, 'draft']
        );
    }

    /**
     * What is stopping this draft being published.
     *
     * @return list<string>
     */
    public function validate(int $draftId): array
    {
        $problems = [];

        $competencies = Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`', $this->app->table('competencies')),
            [$draftId]
        );

        if ($competencies === []) {
            return ['It has no competencies.'];
        }

        foreach ($competencies as $competency) {
            foreach (['name', 'description', 'board_question', 'failure_line'] as $required) {
                if (trim((string) $competency[$required]) === '') {
                    $problems[] = sprintf('%s is missing its %s.', $competency['code'], str_replace('_', ' ', $required));
                }
            }

            $empty = (int) Database::scalar(
                sprintf(
                    'SELECT COUNT(*) FROM `%s` WHERE `competency_id` = ? AND (`statement` IS NULL OR TRIM(`statement`) = \'\')',
                    $this->app->table('items')
                ),
                [$competency['id']]
            );

            if ($empty > 0) {
                $problems[] = sprintf('%s has %d empty statement(s).', $competency['code'], $empty);
            }
        }

        // The bands must still cover the scale, or scores fall through a gap.
        $rungs = Database::select(
            sprintf('SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`', $this->app->table('rungs')),
            [$draftId]
        );

        if (!(new \Platform\Assessment\Bander($rungs))->isContiguous()) {
            $problems[] = 'Its band cut points leave a gap or overlap.';
        }

        return $problems;
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function assertDraft(int $instrumentId): array
    {
        $instrument = $this->find($instrumentId);

        if ($instrument === null) {
            throw new RuntimeException('That version does not exist.');
        }

        if ($instrument['status'] !== 'draft') {
            throw new RuntimeException(
                'Version ' . $instrument['version'] . ' is ' . $instrument['status'] . ' and cannot be edited. '
                . 'Submissions are pinned to it; editing it would change what their scores mean.'
            );
        }

        return $instrument;
    }

    private function versionExists(string $version): bool
    {
        return (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `version` = ?', $this->app->table('instruments')),
            [$version]
        ) > 0;
    }

    /** Suggest the next version number: 1.0 -> 1.1, 2 -> 2.1. */
    public function suggestNextVersion(string $current): string
    {
        if (preg_match('/^(\d+)\.(\d+)$/', $current, $m)) {
            return $m[1] . '.' . ((int) $m[2] + 1);
        }

        if (preg_match('/^(\d+)$/', $current, $m)) {
            return $m[1] . '.1';
        }

        return $current . '-draft';
    }
}
