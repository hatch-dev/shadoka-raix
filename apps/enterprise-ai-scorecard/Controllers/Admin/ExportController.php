<?php

declare(strict_types=1);

namespace Apps\EnterpriseAiScorecard\Controllers\Admin;

use Apps\EnterpriseAiScorecard\Services\Analytics;
use Apps\EnterpriseAiScorecard\Services\Export\CodebookExporter;
use Apps\EnterpriseAiScorecard\Services\Export\DatasetExporter;
use Apps\EnterpriseAiScorecard\Services\RecordMirror;
use Apps\EnterpriseAiScorecard\Services\SubmissionService;
use Platform\Admin\AdminController;
use Platform\Core\Database;
use Platform\Core\Response;

/**
 * Downloads.
 *
 * Every export is audited, because a dataset leaving the building is exactly the
 * event you want a record of afterwards — including whether the copy that left
 * contained synthetic records, and on this instrument, that every row in it
 * names a contactable person.
 */
final class ExportController extends AdminController
{
    /**
     * GET /admin/enterprise-ai-scorecard/report
     *
     * The full research report, as a print-first page.
     *
     * The console's PDF button does not come here: it draws the reference's own
     * jsPDF document from the figures at /report/data. This page is what that
     * button falls back to with scripting off, and it is the fuller document of
     * the two — sectors, year over year, the item breakdown and the correlations,
     * none of which the reference's PDF carried.
     */
    public function report(): Response
    {
        if ($denied = $this->guard('es.export')) {
            return $denied;
        }

        // The v32 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $exporter         = new DatasetExporter($this->app, $includeSynthetic);

        $overview   = $analytics->overview();
        $dimensions = $analytics->dimensions();

        // Per-question detail for every dimension, so the report carries the
        // item-level picture a CSV gives and a summary table does not.
        $items = [];
        foreach ($dimensions as $dimension) {
            $detail = $analytics->dimension($dimension['code']);

            if ($detail !== null) {
                $items[$dimension['code']] = $detail;
            }
        }

        $this->audit('es.report.viewed', 'report', $includeSynthetic ? 'with-synthetic' : 'real-only', [
            'n' => $overview['n'],
        ]);

        /*
         * $this->view(), not a bare View: the report extends the admin layout,
         * and the layout wants the nav, the acting identity and the CSRF token.
         * Building a View by hand here gives it `slug` and nothing else, and the
         * page renders with an empty navigation bar.
         */
        return $this->view()
            ->response('admin/report', [
                'title'            => 'Research report · The Enterprise Scorecard',
                'includeSynthetic' => $includeSynthetic,
                'instrument'       => (new SubmissionService($this->app))->publishedInstrument(),
                'overview'         => $overview,
                'counts'           => $analytics->counts(),
                'levels'           => $analytics->levelDistribution(),
                'dimensions'       => $dimensions,
                'aggregateFloor'   => $analytics->aggregateFloor($dimensions),
                'bander'           => $analytics->bander(),
                'sectors'          => $analytics->bySector(),
                'yearSeries'       => $analytics->yearOverYear(),
                'rescored'         => $analytics->rescoredOrganisations(),
                'items'            => $items,
                'systems'          => $analytics->systemsInventory(),
                'reading'          => $analytics->datasetReading(),
                'correlations'     => $analytics->correlations(),
                'caveats'          => $exporter->caveats(),
                'records'          => $this->records($includeSynthetic),
            ]);
    }

    /**
     * GET /admin/enterprise-ai-scorecard/report/data
     *
     * What the reference's `exportFullPDF()` had in memory, assembled here.
     *
     * The drawing is in the browser because that is where jsPDF is and where that
     * build drew it; the arithmetic is here because everywhere else in this
     * application the server owns the figures.
     *
     * Two things the reference got subtly wrong in this document, and which are
     * corrected rather than reproduced:
     *
     *  - Its summary line printed "mean overall score" and "mean floor" over the
     *    DIMENSION MEANS, while the same words on the analytics screen meant the
     *    average across records. Both are defensible; having them differ between
     *    two documents of the same dataset is not. Both figures here are the
     *    per-record averages, which is what the screen shows.
     *  - It counted organisations with `new Set(recs.map(r => r.org?.org)).size`,
     *    so three spellings of one company counted as three. This counts distinct
     *    platform organisation rows, which is what that table is for.
     */
    public function reportData(): Response
    {
        if ($denied = $this->guard('es.export')) {
            return $denied;
        }

        // The v32 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $includeSynthetic = ($this->request->query['synthetic'] ?? '') === '1';
        $analytics        = new Analytics($this->app, $includeSynthetic);
        $bander           = $analytics->bander();

        $overview   = $analytics->overview();
        $dimensions = $analytics->dimensions();
        $floor      = $analytics->aggregateFloor($dimensions);
        $records    = $this->records($includeSynthetic);

        $this->audit('es.report.viewed', 'report-pdf', $includeSynthetic ? 'with-synthetic' : 'real-only', [
            'n' => count($records),
        ]);

        return Response::json(['success' => true, 'data' => [
            'include_synthetic' => $includeSynthetic,
            // "September 23, 2026" — the reference's header subtitle, which it
            // built with toLocaleDateString('en-US', {year, month:'long', day}).
            'generated_on'      => date('F j, Y'),
            'n'                 => $overview['n'],
            'organisations'     => $overview['organisations'],
            // Per-record averages, matching the analytics screen exactly.
            'mean'              => $overview['mean_average'],
            'mean_floor'        => $overview['mean_floor'],
            'gap'               => $overview['gap'],
            // Which dimension the dataset as a whole is weakest on, which is what
            // the "Floor" tag marks on the bar rows.
            'floor_code'        => $floor['code'],
            'floor_mean'        => $floor['mean'],
            'footnote'          => $this->footnote(),
            'dimensions'        => array_map(
                static function (array $dimension) use ($bander): array {
                    $band = $bander->band($dimension['mean']);

                    return [
                        'code'   => $dimension['code'],
                        'name'   => $dimension['name'],
                        'mean'   => $dimension['mean'],
                        'min'    => $dimension['min'],
                        'max'    => $dimension['max'],
                        'n'      => $dimension['n'],
                        'colour' => $band['colour'] ?? null,
                        'rung'   => $band['name'] ?? null,
                    ];
                },
                $dimensions
            ),
            'levels'  => $analytics->levelDistribution(),
            'records' => array_map(
                static fn (array $r): array => [
                    'organisation' => $r['organisation_name'],
                    'sector'       => $r['sector'],
                    'year'         => (int) $r['assessment_year'],
                    'level'        => $r['level_name'],
                    'mean'         => $r['mean_score'] === null ? null : (float) $r['mean_score'],
                    'floor'        => $r['floor_score'] === null ? null : (float) $r['floor_score'],
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
            'SELECT s.`assessment_year`, s.`respondent_name`, s.`role`, s.`organisation_name`,
                    s.`organisation_size`, s.`sector`,
                    s.`mean_score`, s.`floor_score`, s.`level_value`, s.`is_synthetic`,
                    fd.`code` AS floor_code, rg.`name` AS level_name
               FROM `%s` s
          LEFT JOIN `%s` fd ON fd.`id` = s.`floor_dimension_id`
          LEFT JOIN `%s` rg ON rg.`instrument_id` = s.`instrument_id` AND rg.`value` = s.`level_value`
              WHERE %s
           ORDER BY s.`organisation_name`, s.`assessment_year`',
            $this->table('submissions'),
            $this->table('dimensions'),
            $this->table('rungs'),
            $where
        ));
    }

    /** GET /admin/enterprise-ai-scorecard/export/{format} */
    public function download(string $format): Response
    {
        if ($denied = $this->guard('es.export')) {
            return $denied;
        }

        // The v32 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

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

        /*
         * `contains_contact_details` is recorded because it is true of every
         * export of this dataset and of no export of the sibling app's: name,
         * email and phone are required fields here. If a copy of this file has
         * to be accounted for later, that is the fact that will matter.
         */
        $this->audit('es.export', 'dataset', $format, [
            'includes_synthetic'       => $includeSynthetic,
            'contains_contact_details' => true,
            'records'                  => $exporter->count(),
            'bytes'                    => strlen($body),
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

        // The codebook describes the instrument and holds no respondent data,
        // which is why it is the one export with nothing to say about contacts.
        $this->audit('es.export', 'codebook', 'json', ['bytes' => strlen($body)]);

        return $this->attachment($body, $exporter->filename(), 'application/json; charset=utf-8');
    }

    /** The level legend printed on a generated PDF, built from the instrument. */
    private function footnote(): string
    {
        $bander = (new Analytics($this->app))->bander();

        $levels = implode(' · ', array_map(
            static fn (array $rung): string => sprintf(
                '%d %s (%s)',
                $rung['value'],
                $rung['name'],
                strtolower(rtrim($rung['description'], '.'))
            ),
            $bander->rungs()
        ));

        return strtr((string) $this->appConfig('result.pdf_footnote'), [
            '{copyright}' => (string) config('app.copyright'),
            '{levels}'    => $levels,
        ]);
    }

    /**
     * Send a file rather than render it.
     *
     * The filename is stripped of anything that could break out of the header — a
     * Content-Disposition built from unvalidated text is a response-splitting
     * hole.
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
