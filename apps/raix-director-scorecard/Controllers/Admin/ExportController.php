<?php

declare(strict_types=1);

namespace Apps\RaixDirectorScorecard\Controllers\Admin;

use Apps\RaixDirectorScorecard\Services\Analytics;
use Apps\RaixDirectorScorecard\Services\Export\CodebookExporter;
use Apps\RaixDirectorScorecard\Services\Export\DatasetExporter;
use Apps\RaixDirectorScorecard\Services\SubmissionService;
use Platform\Admin\AdminController;
use Platform\Core\Database;
use Platform\Core\Response;
use Platform\Core\View;

/**
 * Downloads.
 *
 * Every export is audited, because a dataset leaving the building is exactly
 * the event you want a record of afterwards — including whether the copy that
 * left contained synthetic records.
 */
final class ExportController extends AdminController
{
    /**
     * GET /admin/raix-director-scorecard/report
     *
     * The full research report, as a print-first page.
     *
     * The console's PDF button no longer comes here: it draws the reference's
     * own jsPDF document from the figures at /report/data. This page is what
     * that button falls back to with scripting off, and it is the fuller
     * document of the two — sectors, year-over-year, boards, statements and
     * correlations, none of which the reference's PDF carried.
     */
    public function report(): Response
    {
        if ($denied = $this->guard('ds.export')) {
            return $denied;
        }

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $exporter         = new DatasetExporter($this->app, $includeSynthetic);

        $overview = $analytics->overview();

        // Per-statement detail for every competency, so the report carries the
        // item-level picture a CSV gives and a summary table does not.
        $competencies = $analytics->competencies();
        $statements   = [];

        foreach ($competencies as $competency) {
            $breakdown = $analytics->items($competency['code']);

            if ($breakdown !== null) {
                $statements[$competency['code']] = $breakdown;
            }
        }

        $this->audit('ds.report.viewed', 'report', $includeSynthetic ? 'with-synthetic' : 'real-only', [
            'n' => $overview['n'],
        ]);

        return (new View($this->app))
            ->share('slug', $this->app->slug)
            ->response('admin/report', [
                'title'            => 'Research report · RAI-X Director Scorecard',
                'includeSynthetic' => $includeSynthetic,
                'instrument'       => (new SubmissionService($this->app))->publishedInstrument(),
                'overview'         => $overview,
                'counts'           => $analytics->counts(),
                'levels'           => $analytics->levelDistribution(),
                'competencies'     => $competencies,
                'sectors'          => $analytics->bySector(),
                'years'            => $analytics->yearOverYear(),
                'boards'           => $analytics->boards(200),
                'statements'       => $statements,
                'correlations'     => $analytics->correlations(),
                'caveats'          => $exporter->caveats(),
                'records'          => $this->records($includeSynthetic),
            ]);
    }

    /**
     * GET /admin/raix-director-scorecard/report/data
     *
     * What the reference's `exportFullPDF()` had in memory, assembled here.
     *
     * The drawing is in the browser because that is where jsPDF is and where
     * that build drew it; the arithmetic is here because everywhere else in
     * this application the server owns the figures. The two rules it would be
     * easy to get subtly wrong, and which are the reference's:
     *
     *  - The summary line's "mean floor" and "mean average" are taken over the
     *    COMPETENCY MEANS, not over directors. Its floor is the weakest of the
     *    seven means and its average is the mean of those seven.
     *  - Boards are counted by distinct board NAME, and every anonymous
     *    scorecard counts as one board between them, because its Set held a
     *    single `undefined`.
     */
    public function reportData(): Response
    {
        if ($denied = $this->guard('ds.export')) {
            return $denied;
        }

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $bander           = $analytics->bander();

        $competencies = $analytics->competencies();
        $means        = array_values(array_filter(
            array_column($competencies, 'mean'),
            static fn (?float $m): bool => $m !== null
        ));

        $floor     = $means === [] ? null : round(min($means), 2);
        $floorCode = null;

        foreach ($competencies as $competency) {
            if ($competency['mean'] !== null && $competency['mean'] === $floor) {
                // First in instrument order wins a tie, as the floor rule does.
                $floorCode = $competency['code'];
                break;
            }
        }

        $records = $this->records($includeSynthetic);

        $names     = array_column($records, 'board');
        $anonymous = count(array_filter($names, static fn ($n): bool => $n === null || $n === ''));
        $boards    = count(array_unique(array_filter($names))) + ($anonymous > 0 ? 1 : 0);

        $this->audit('ds.report.viewed', 'report-pdf', $includeSynthetic ? 'with-synthetic' : 'real-only', [
            'n' => count($records),
        ]);

        return Response::json(['success' => true, 'data' => [
            'include_synthetic' => $includeSynthetic,
            // "September 22, 2026" — the reference's header subtitle, which it
            // built with toLocaleDateString('en-US', {year, month:'long', day}).
            'generated_on'      => date('F j, Y'),
            'n'                 => count($records),
            'boards'            => $boards,
            'floor'             => $floor,
            'floor_code'        => $floorCode,
            'mean'              => $means === [] ? null : round(array_sum($means) / count($means), 2),
            'competencies'      => array_map(
                static function (array $competency) use ($bander): array {
                    $band = $bander->band($competency['mean']);

                    return [
                        'code'   => $competency['code'],
                        'name'   => $competency['name'],
                        'mean'   => $competency['mean'],
                        'min'    => $competency['min'],
                        'max'    => $competency['max'],
                        'n'      => $competency['n'],
                        'colour' => $band['colour'] ?? null,
                        'rung'   => $band['name'] ?? null,
                    ];
                },
                $competencies
            ),
            'levels'            => $analytics->levelDistribution(),
            'records'           => array_map(
                static fn (array $r): array => [
                    'board'  => $r['board'],
                    'sector' => $r['sector'],
                    'year'   => $r['assessment_year'],
                    'level'  => $r['level_name'],
                    'floor'  => $r['floor_score'] === null ? null : (float) $r['floor_score'],
                    'mean'   => $r['mean_score'] === null ? null : (float) $r['mean_score'],
                ],
                $records
            ),
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function records(bool $includeSynthetic): array
    {
        $where = "s.`status` = 'complete'";

        if (!$includeSynthetic) {
            $where .= ' AND s.`is_synthetic` = 0';
        }

        return Database::select(sprintf(
            'SELECT s.`assessment_year`, s.`director_name`, s.`seat_role`,
                    s.`floor_score`, s.`mean_score`, s.`level_value`, s.`is_synthetic`,
                    o.`name` AS board,
                    COALESCE(s.`sector`, o.`sector`) AS `sector`,
                    fc.`code` AS floor_code, rg.`name` AS level_name
               FROM `%s` s
          LEFT JOIN `organisations` o ON o.`id` = s.`organisation_id`
          LEFT JOIN `%s` fc ON fc.`id` = s.`floor_competency_id`
          LEFT JOIN `%s` rg ON rg.`instrument_id` = s.`instrument_id`
                           AND rg.`value` = s.`level_value`
              WHERE %s
           ORDER BY o.`name`, s.`assessment_year`',
            $this->table('submissions'),
            $this->table('competencies'),
            $this->table('rungs'),
            $where
        ));
    }

    /** GET /admin/raix-director-scorecard/export/{format} */
    public function download(string $format): Response
    {
        if ($denied = $this->guard('ds.export')) {
            return $denied;
        }

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';

        return match ($format) {
            'csv'      => $this->dataset('csv', $includeSynthetic),
            'json'     => $this->dataset('json', $includeSynthetic),
            'codebook' => $this->codebook(),
            default    => $this->fail('Unknown export format.', 404),
        };
    }

    private function dataset(string $format, bool $includeSynthetic): Response
    {
        $exporter = new DatasetExporter($this->app, $includeSynthetic);

        $body = $format === 'csv' ? $exporter->csv() : $exporter->json();
        $name = $exporter->filename($format);

        $this->audit('ds.export', 'dataset', $format, [
            'includes_synthetic' => $includeSynthetic,
            'bytes'              => strlen($body),
        ]);

        return $this->attachment(
            $body,
            $name,
            $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'
        );
    }

    private function codebook(): Response
    {
        $exporter = new CodebookExporter($this->app);
        $body     = $exporter->json();

        $this->audit('ds.export', 'codebook', 'json', ['bytes' => strlen($body)]);

        return $this->attachment($body, $exporter->filename(), 'application/json; charset=utf-8');
    }

    /**
     * Send a file rather than render it.
     *
     * The filename is quoted and stripped of anything that could break out of
     * the header — a Content-Disposition built from unvalidated text is a
     * response-splitting hole.
     */
    private function attachment(string $body, string $name, string $contentType): Response
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'export';

        return Response::make($body, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
            'Content-Length'      => (string) strlen($body),
            'Cache-Control'       => 'no-store',
        ]);
    }
}
