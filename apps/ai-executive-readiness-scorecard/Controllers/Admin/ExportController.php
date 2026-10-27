<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Controllers\Admin;

use Apps\AiExecutiveReadinessScorecard\Services\Analytics;
use Apps\AiExecutiveReadinessScorecard\Services\RecordMirror;
use Apps\AiExecutiveReadinessScorecard\Services\Export\Exports;
use Platform\Admin\AdminController;
use Platform\Core\Response;

/**
 * The downloads: CSV, JSON, the codebook, and the figures behind the research
 * report PDF. Every one respects the "include synthetic" setting, as the
 * reference's did, and every one is audited — the CSV and JSON carry every
 * respondent's name, email address and phone number.
 */
final class ExportController extends AdminController
{
    /** GET /admin/ai-executive-readiness-scorecard/export/{csv|json|codebook} */
    public function download(string $format): Response
    {
        if ($denied = $this->guard('exr.export')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $exports = new Exports($this->app);

        if ($format === 'codebook') {
            $this->audit('exr.export.codebook');

            return $this->attachment($exports->codebook(), 'ai-executive-readiness-codebook.json', 'application/json');
        }

        if (!in_array($format, ['csv', 'json'], true)) {
            return $this->fail('Unknown export format.', 404);
        }

        $included = (new Analytics($this->app))->includeSynthetic($this->request->query);
        $records  = $exports->records($included);

        if ($records === []) {
            // The reference's toast, as a page: a download of nothing is not a file.
            return $this->redirect('admin/' . $this->app->slug . '/research-data?' . http_build_query([
                'synthetic' => $included ? '1' : '0',
                'empty'     => '1',
            ]));
        }

        $this->audit('exr.export.' . $format, 'dataset', 'all', [
            'records'   => count($records),
            'synthetic' => $included,
        ]);

        return $format === 'csv'
            ? $this->attachment($exports->csv($records), $exports->filename('csv'), 'text/csv; charset=UTF-8')
            : $this->attachment($exports->json($records, $included), $exports->filename('json'), 'application/json');
    }

    /**
     * GET /admin/ai-executive-readiness-scorecard/report/data
     *
     * exportFullPDF(source): the research report's figures. From Analytics
     * (source=analytics) it is the filtered records, described by the filters;
     * otherwise every year and sector, with synthetic records as the dataset's
     * toggle says.
     */
    public function reportData(): Response
    {
        if ($denied = $this->guard('exr.export')) {
            return $denied;
        }

        // The v4 page's records, in step before anything is read (RecordMirror).
        (new RecordMirror($this->app))->backfill();

        $query     = $this->request->query;
        $analytics = new Analytics($this->app);

        if (($query['source'] ?? 'data') === 'analytics') {
            $filters     = $analytics->filters($query);
            $records     = $analytics->filtered($filters);
            $description = $analytics->filterDescription($filters);
        } else {
            $included    = $analytics->includeSynthetic($query);
            $records     = array_values(array_filter($analytics->all(), static fn (array $r): bool => $included || !$r['synthetic']));
            $description = 'All years and sectors. Synthetic records ' . ($included ? 'included' : 'excluded') . '.';
        }

        if ($records === []) {
            return $this->fail('Nothing to export', 422);
        }

        $this->audit('exr.export.report', 'dataset', 'all', ['records' => count($records)]);

        return $this->ok($analytics->report(
            $records,
            'Research report',
            $description,
            'AI_Executive_Readiness_Research.pdf'
        ));
    }

    private function attachment(string $body, string $name, string $contentType): Response
    {
        return Response::make($body)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
