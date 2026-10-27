<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecardPublic\Controllers\Admin;

use Apps\RaixDirectorScorecardPublic\Services\InstrumentVersions;
use Platform\Admin\AdminController;
use Platform\Core\Auth;
use Platform\Core\Database;
use Platform\Core\Response;
use RuntimeException;

/**
 * Viewing and editing the instrument.
 *
 * Reading is `dsp.view`; anything that writes is `dsp.instrument.edit`. The
 * service refuses to touch a published version at all, so the guard here is the
 * outer of two locks rather than the only one.
 */
final class InstrumentController extends AdminController
{
    private function versions(): InstrumentVersions
    {
        return new InstrumentVersions($this->app);
    }

    /* ------------------------------------------------------------------ */

    /** GET /admin/raix-director-scorecard-public/instrument */
    public function index(): Response
    {
        if ($denied = $this->guard('dsp.view')) {
            return $denied;
        }

        $service  = $this->versions();
        $versions = $service->all();

        $requested = (int) ($this->request->query['version'] ?? 0);
        $current   = null;

        foreach ($versions as $version) {
            if ($requested > 0 ? (int) $version['id'] === $requested : $version['status'] === 'published') {
                $current = $version;
                break;
            }
        }

        $current ??= $versions[0] ?? null;

        if ($current === null) {
            return $this->view()->response('admin/instrument', [
                'title'        => 'Instrument · Director Scorecard',
                'versions'     => [],
                'instrument'   => null,
                'rungs'        => [],
                'competencies' => [],
                'usage'        => 0,
                'draft'        => null,
                'canEdit'      => Auth::can('dsp.instrument.edit'),
                'problems'     => [],
            ]);
        }

        $instrumentId = (int) $current['id'];

        return $this->view()->response('admin/instrument', [
            'title'        => 'Instrument · Director Scorecard',
            'versions'     => $versions,
            'instrument'   => $current,
            'rungs'        => $this->rungs($instrumentId),
            'competencies' => $this->competenciesWithItems($instrumentId),
            'usage'        => $service->submissionCount($instrumentId),
            'draft'        => $service->draft(),
            'canEdit'      => Auth::can('dsp.instrument.edit'),
            'problems'     => $current['status'] === 'draft' ? $service->validate($instrumentId) : [],
            'nextVersion'  => $service->suggestNextVersion((string) $current['version']),
            'flash'        => $this->request->query['msg'] ?? null,
            'error'        => $this->request->query['error'] ?? null,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** POST /admin/raix-director-scorecard-public/instrument/draft */
    public function createDraft(): Response
    {
        if ($denied = $this->guard('dsp.instrument.edit')) {
            return $denied;
        }

        try {
            $draftId = $this->versions()->createDraft(
                (int) $this->request->input('source_id'),
                (string) $this->request->input('version'),
                $this->request->input('notes') === '' ? null : (string) $this->request->input('notes')
            );
        } catch (RuntimeException $e) {
            return $this->back(['error' => $e->getMessage()]);
        }

        $this->audit('dsp.instrument.draft_created', 'instrument', (string) $draftId, [
            'from_version' => (int) $this->request->input('source_id'),
            'version'      => (string) $this->request->input('version'),
        ]);

        return $this->redirect($this->base() . '/instrument/' . $draftId . '/edit?msg=' . urlencode('Draft created.'));
    }

    /* ------------------------------------------------------------------ */

    /** GET /admin/raix-director-scorecard-public/instrument/{id}/edit */
    public function edit(string $id): Response
    {
        if ($denied = $this->guard('dsp.instrument.edit')) {
            return $denied;
        }

        $service    = $this->versions();
        $instrument = $service->find((int) $id);

        if ($instrument === null) {
            return $this->back(['error' => 'That version does not exist.']);
        }

        if ($instrument['status'] !== 'draft') {
            return $this->back([
                'error' => 'Version ' . $instrument['version'] . ' is ' . $instrument['status']
                         . ' and cannot be edited. Create a draft from it instead.',
            ]);
        }

        return $this->view()->response('admin/instrument-edit', [
            'title'        => 'Editing v' . $instrument['version'] . ' · Director Scorecard',
            'instrument'   => $instrument,
            'competencies' => $this->competenciesWithItems((int) $id),
            'problems'     => $service->validate((int) $id),
            'flash'        => $this->request->query['msg'] ?? null,
            'error'        => $this->request->query['error'] ?? null,
        ]);
    }

    /** POST /admin/raix-director-scorecard-public/instrument/{id}/competency/{competencyId} */
    public function updateCompetency(string $id, string $competencyId): Response
    {
        if ($denied = $this->guard('dsp.instrument.edit')) {
            return $denied;
        }

        $statements = $this->request->input('statements');
        $statements = is_array($statements) ? array_map('strval', $statements) : [];

        try {
            $changes = $this->versions()->updateCompetency(
                (int) $id,
                (int) $competencyId,
                (array) $this->request->body,
                $statements
            );
        } catch (RuntimeException $e) {
            return $this->redirect($this->base() . '/instrument/' . (int) $id . '/edit?error=' . urlencode($e->getMessage()));
        }

        if ($changes !== []) {
            $this->audit('dsp.instrument.competency_edited', 'competency', $competencyId, [
                'instrument_id' => (int) $id,
                'changed'       => $changes,
            ]);
        }

        $message = $changes === []
            ? 'No changes to save.'
            : 'Saved: ' . implode(', ', $changes) . '.';

        return $this->redirect(
            $this->base() . '/instrument/' . (int) $id . '/edit?msg=' . urlencode($message)
            . '#competency-' . (int) $competencyId
        );
    }

    /* ------------------------------------------------------------------ */

    /** POST /admin/raix-director-scorecard-public/instrument/{id}/publish */
    public function publish(string $id): Response
    {
        if ($denied = $this->guard('dsp.instrument.edit')) {
            return $denied;
        }

        try {
            $result = $this->versions()->publish((int) $id);
        } catch (RuntimeException $e) {
            return $this->redirect($this->base() . '/instrument/' . (int) $id . '/edit?error=' . urlencode($e->getMessage()));
        }

        $this->audit('dsp.instrument.published', 'instrument', $id, $result);

        $message = $result['archived'] === null
            ? sprintf('Version %s published.', $result['published'])
            : sprintf(
                'Version %s published. Version %s is archived and still answers for its own submissions.',
                $result['published'],
                $result['archived']
            );

        return $this->redirect($this->base() . '/instrument?msg=' . urlencode($message));
    }

    /** POST /admin/raix-director-scorecard-public/instrument/{id}/discard */
    public function discard(string $id): Response
    {
        if ($denied = $this->guard('dsp.instrument.edit')) {
            return $denied;
        }

        $instrument = $this->versions()->find((int) $id);

        // Audited before the delete: afterwards there is no row to describe.
        $this->audit('dsp.instrument.discarded', 'instrument', $id, [
            'version' => $instrument['version'] ?? null,
        ]);

        try {
            $this->versions()->discard((int) $id);
        } catch (RuntimeException $e) {
            return $this->back(['error' => $e->getMessage()]);
        }

        return $this->back(['msg' => 'Draft discarded.']);
    }

    /* ------------------------------------------------------------------ */

    private function base(): string
    {
        return 'admin/' . $this->app->slug;
    }

    private function back(array $query): Response
    {
        return $this->redirect($this->base() . '/instrument?' . http_build_query($query));
    }

    /** @return list<array<string, mixed>> */
    private function rungs(int $instrumentId): array
    {
        return Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `value`',
            $this->table('rungs')
        ), [$instrumentId]);
    }

    /** @return list<array<string, mixed>> */
    private function competenciesWithItems(int $instrumentId): array
    {
        $competencies = Database::select(sprintf(
            'SELECT * FROM `%s` WHERE `instrument_id` = ? ORDER BY `position`',
            $this->table('competencies')
        ), [$instrumentId]);

        foreach ($competencies as $index => $competency) {
            $competencies[$index]['items'] = Database::select(sprintf(
                'SELECT `id`, `position`, `statement` FROM `%s` WHERE `competency_id` = ? ORDER BY `position`',
                $this->table('items')
            ), [$competency['id']]);
        }

        return $competencies;
    }
}
