<?php

declare(strict_types=1);

namespace Apps\HumanEdgeScorecard\Controllers\Admin;

use Apps\HumanEdgeScorecard\Services\Analytics;
use Apps\HumanEdgeScorecard\Services\Exports;
use Apps\HumanEdgeScorecard\Services\Instrument;
use Apps\HumanEdgeScorecard\Services\RecordMirror;
use Apps\HumanEdgeScorecard\Services\Records;
use Platform\Admin\AdminController;
use Platform\Core\Response;

/**
 * The console's Export tab and the Research data toolbar's downloads: the
 * page's exportCSV(), exportJSON(), the identified pair, exportCodebook(),
 * and the figures exportFullPDF() draws.
 */
final class ExportController extends AdminController
{
    /** The formats, and whether each names people. */
    private const FORMATS = [
        'csv'             => false,
        'json'            => false,
        'csv-identified'  => true,
        'json-identified' => true,
    ];

    /** GET /admin/human-edge-scorecard/export/{format} */
    public function download(string $format): Response
    {
        if ($denied = $this->guard('he.export')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $exports = new Exports($this->app);

        if ($format === 'codebook') {
            $this->audit('he.export.codebook');

            return $this->attachment($exports->codebook(), 'human-edge-scorecard-codebook.json', 'application/json');
        }

        if (!array_key_exists($format, self::FORMATS)) {
            return $this->fail('Unknown export format.', 404);
        }

        $included = (new Analytics($this->app))->includeSynthetic($this->request->query);
        $records  = $exports->records($included);

        if ($records === []) {
            // The page's toast, as a page: a download of nothing is not a file.
            return $this->redirect('admin/' . $this->app->slug . '/research-data?' . http_build_query([
                'synthetic' => $included ? '1' : '0',
                'empty'     => '1',
            ]));
        }

        $identified = self::FORMATS[$format];

        $this->audit('he.export.' . $format, 'dataset', 'all', [
            'records'                  => count($records),
            'synthetic'                => $included,
            'contains_contact_details' => $identified,
        ]);

        return match ($format) {
            'csv'             => $this->attachment($exports->csv($records), Exports::filename('research', 'csv'), 'text/csv; charset=UTF-8'),
            'json'            => $this->attachment($exports->json($records, $included), Exports::filename('research', 'json'), 'application/json'),
            'csv-identified'  => $this->attachment($exports->csvIdentified($records), Exports::filename('identified', 'csv'), 'text/csv; charset=UTF-8'),
            'json-identified' => $this->attachment($exports->jsonIdentified($records, $included), Exports::filename('identified', 'json'), 'application/json'),
        };
    }

    /** GET …/report/data — the records exportFullPDF() draws, with the toggle applied. */
    public function reportData(): Response
    {
        if ($denied = $this->guard('he.export')) {
            return $denied;
        }

        (new RecordMirror($this->app))->backfill();

        $included = (new Analytics($this->app))->includeSynthetic($this->request->query);
        $records  = (new Exports($this->app))->records($included);

        if ($records === []) {
            return $this->fail('Nothing to export', 422);
        }

        $this->audit('he.export.report', 'dataset', 'all', ['records' => count($records), 'synthetic' => $included]);

        return $this->ok((new Instrument($this->app))->forBrowser() + [
            'includeSynthetic' => $included,
            'records'          => array_map([Records::class, 'export'], $records),
        ]);
    }

    private function attachment(string $body, string $name, string $contentType): Response
    {
        return Response::make($body)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
