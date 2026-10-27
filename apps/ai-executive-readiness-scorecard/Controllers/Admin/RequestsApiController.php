<?php

declare(strict_types=1);

namespace Apps\AiExecutiveReadinessScorecard\Controllers\Admin;

use Apps\AiExecutiveReadinessScorecard\Services\RecordStore;
use Platform\Core\Auth;
use Platform\Core\Response;

/**
 * Requests from individuals (directive v4, C4) for the Requests view INSIDE
 * the scorecard page, so the admin never leaves the page's own header. The
 * same work as the console's RequestsController, through the same RecordStore
 * methods; only the shape differs (JSON in and out).
 *
 *   POST /api/admin/requests/search          {email}                        → {records:[summary…]}
 *   GET  /api/admin/requests/{id}                                           → {record, meta, pretty}
 *   GET  /api/admin/requests/{id}/export                                    → the record, as a JSON file body
 *   POST /api/admin/requests/{id}/identity   {name,email,phone,organization} → {record, meta, pretty}
 *   POST /api/admin/requests/{id}/record     {payload}                      → {record, meta, pretty}
 *   POST /api/admin/requests/{id}/restrict   {restricted}                   → {record, meta, pretty}
 *   POST /api/admin/requests/{id}/delete     {reason, confirm}              → {}
 *
 * The address searched for travels in a POST body, never in a URL, so it
 * cannot land in an access log (C6). The audit log records actions and record
 * ids, never addresses.
 */
final class RequestsApiController extends AdminApiController
{
    private const PRETTY = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private function body(string $key): mixed
    {
        return $this->request->body[$key] ?? null;
    }

    private function audit(string $action, string $id, array $meta = []): void
    {
        Auth::audit($action, $this->app->slug, 'record', $id, $meta, $this->request->ipHash());
    }

    /** What the list shows for one record. @return array<string, mixed> */
    private function summary(array $row): array
    {
        $record = RecordStore::decodeObject((string) $row['payload']);
        $org    = is_object($record->org ?? null) ? $record->org : new \stdClass();

        return [
            'id'           => (string) $row['id'],
            'name'         => (string) ($record->userName ?? $org->name ?? ''),
            'organization' => trim((string) ($org->org ?? '')),
            'notice'       => (string) ($org->consent->notice ?? ''),
            'completed_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($row['completed_at'] . ' UTC')),
            'delete_by'    => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime(RecordStore::deleteBy((string) $row['completed_at']) . ' UTC')),
            'restricted'   => (int) $row['restricted'] === 1,
        ];
    }

    private function one(string $id): Response
    {
        $row = $this->store()->findReal($id);

        if ($row === null) {
            return Response::json(['error' => 'That record no longer exists.'], 404);
        }

        $record = RecordStore::decodeObject((string) $row['payload']);

        return Response::json([
            'meta'   => $this->summary($row),
            'record' => $record,
            'pretty' => json_encode($record, self::PRETTY),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** POST /api/admin/requests/search */
    public function search(): Response
    {
        if ($denied = $this->gate('exr.view', true)) {
            return $denied;
        }

        $email = mb_substr(trim((string) $this->body('email')), 0, 254);
        $rows  = $email === '' ? [] : $this->store()->findByEmail($email);

        return Response::json(['records' => array_map(fn (array $r): array => $this->summary($r), $rows)])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** GET /api/admin/requests/{id} */
    public function show(string $id): Response
    {
        if ($denied = $this->gate('exr.view')) {
            return $denied;
        }

        return $this->one($id);
    }

    /** GET /api/admin/requests/{id}/export */
    public function export(string $id): Response
    {
        if ($denied = $this->gate('exr.export')) {
            return $denied;
        }

        $row = $this->store()->findReal($id);

        if ($row === null) {
            return Response::json(['error' => 'That record no longer exists.'], 404);
        }

        $this->audit('exr.request.export', $id);

        return Response::make((string) json_encode(RecordStore::decodeObject((string) $row['payload']), self::PRETTY), 200, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** POST /api/admin/requests/{id}/identity */
    public function correctIdentity(string $id): Response
    {
        if ($denied = $this->gate('exr.submission.delete', true)) {
            return $denied;
        }

        $error = $this->store()->correctIdentity($id, [
            'name'         => (string) $this->body('name'),
            'email'        => (string) $this->body('email'),
            'phone'        => (string) $this->body('phone'),
            'organization' => (string) $this->body('organization'),
        ]);

        if ($error !== null) {
            return Response::json(['error' => $error], 422);
        }

        $this->audit('exr.request.correct', $id, ['form' => 'identity']);

        return $this->one($id);
    }

    /** POST /api/admin/requests/{id}/record */
    public function correctRecord(string $id): Response
    {
        if ($denied = $this->gate('exr.submission.delete', true)) {
            return $denied;
        }

        $json    = (string) $this->body('payload');
        $decoded = RecordStore::decodeObject($json);

        // Stored compact, as the page sends it; the editor shows it indented.
        $error = $this->store()->replacePayload($id, $decoded === null
            ? $json
            : (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        if ($error !== null) {
            return Response::json(['error' => $error], 422);
        }

        $this->audit('exr.request.correct', $id, ['form' => 'json']);

        return $this->one($id);
    }

    /** POST /api/admin/requests/{id}/restrict */
    public function restrict(string $id): Response
    {
        if ($denied = $this->gate('exr.submission.delete', true)) {
            return $denied;
        }

        $on = $this->body('restricted') === true;

        if (!$this->store()->setRestricted($id, $on)) {
            return Response::json(['error' => 'That record no longer exists.'], 404);
        }

        $this->audit($on ? 'exr.request.restrict' : 'exr.request.unrestrict', $id);

        return $this->one($id);
    }

    /** POST /api/admin/requests/{id}/delete */
    public function destroy(string $id): Response
    {
        if ($denied = $this->gate('exr.submission.delete', true)) {
            return $denied;
        }

        if ($this->body('confirm') !== 'DELETE') {
            return Response::json(['error' => 'Type DELETE to confirm. This cannot be undone.'], 422);
        }

        $reason = $this->body('reason') === 'withdrawal' ? 'withdrawal' : 'deletion';

        if (!$this->store()->deleteReal($id)) {
            return Response::json(['error' => 'That record no longer exists.'], 404);
        }

        $this->audit('exr.request.delete', $id, ['reason' => $reason]);

        return Response::json(new \stdClass());
    }
}
