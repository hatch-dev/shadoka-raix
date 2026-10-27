<?php

declare(strict_types=1);

namespace Apps\AiSovereigntyScorecard\Controllers\Admin;

use Apps\AiSovereigntyScorecard\Services\RecordStore;
use Platform\Admin\AdminController;
use Platform\Core\Response;
use Platform\Core\Session;

/**
 * /admin/{slug}/requests — requests from individuals (directive v6, C4).
 *
 * Find a person's scorecards in sov_records by email address, then export,
 * correct, restrict or delete one. Withdrawing consent is a deletion: the
 * notice promises the record goes, not that it is flagged.
 *
 * In the admin nav as "Requests", after the reference's own items.
 *
 * The audit log records the action and the record id, never the address, so
 * the log of a deletion does not keep the personal data it deleted.
 */
final class RequestsController extends AdminController
{
    private const FLASH = 'sov_requests_notice';

    /** The last address searched for, kept in the session rather than a URL. */
    private const SEARCH = 'sov_requests_email';

    private function store(): RecordStore
    {
        return new RecordStore($this->app);
    }

    private function base(): string
    {
        return 'admin/' . $this->app->slug . '/requests';
    }

    /**
     * GET /requests — the last search's results, from the session.
     *
     * The address is never put in a URL (no ?email=): a URL lands in the web
     * server's access log, and C6 says no log may hold a person's email.
     * Searching is a POST (search() below); this GET shows what it found.
     */
    public function index(): Response
    {
        if ($denied = $this->guard('sov.view')) {
            return $denied;
        }

        $email   = trim((string) Session::get(self::SEARCH, ''));
        $results = $email === '' ? null : $this->store()->findByEmail($email);

        return $this->view()->response('admin/requests', [
            'title'   => 'Requests · ' . $this->app->name,
            'email'   => $email,
            'results' => $results,
            'notice'  => $this->takeFlash(),
        ]);
    }

    /** POST /requests — search by email, then show the results at GET /requests. */
    public function search(): Response
    {
        if ($denied = $this->guard('sov.view')) {
            return $denied;
        }

        Session::put(self::SEARCH, mb_substr(trim((string) ($this->request->body['email'] ?? '')), 0, 254));

        return $this->redirect($this->base());
    }

    /** GET /requests/{id} */
    public function show(string $id): Response
    {
        if ($denied = $this->guard('sov.view')) {
            return $denied;
        }

        $row = $this->store()->findReal($id);

        if ($row === null) {
            return $this->redirect($this->base());
        }

        return $this->page($row);
    }

    /** @param array<string, string> $errors */
    private function page(array $row, array $errors = [], ?string $draft = null, int $status = 200): Response
    {
        $record = RecordStore::decodeObject((string) $row['payload']);

        return $this->view()->response('admin/request-record', [
            'title'  => 'Record ' . $row['id'] . ' · ' . $this->app->name,
            'row'    => $row,
            'record' => $record,
            'pretty' => $draft ?? json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'errors' => $errors,
            'notice' => $this->takeFlash(),
            'base'   => $this->base(),
        ], $status);
    }

    /** GET /requests/{id}/export — the person's record as a JSON file (a copy of their data). */
    public function export(string $id): Response
    {
        if ($denied = $this->guard('sov.export')) {
            return $denied;
        }

        $row = $this->store()->findReal($id);

        if ($row === null) {
            return $this->redirect($this->base());
        }

        $this->audit('sov.request.export', 'record', $id);

        $pretty = json_encode(
            RecordStore::decodeObject((string) $row['payload']),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return Response::make((string) $pretty, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ai-sovereignty-scorecard-' . preg_replace('/[^A-Za-z0-9._-]/', '', $id) . '.json"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** POST /requests/{id}/identity — correct name, email, phone or organization. */
    public function correctIdentity(string $id): Response
    {
        if ($denied = $this->guard('sov.submission.delete')) {
            return $denied;
        }

        $error = $this->store()->correctIdentity($id, [
            'name'         => (string) ($this->request->body['name'] ?? ''),
            'email'        => (string) ($this->request->body['email'] ?? ''),
            'phone'        => (string) ($this->request->body['phone'] ?? ''),
            'organization' => (string) ($this->request->body['organization'] ?? ''),
        ]);

        return $this->afterEdit($id, $error, 'identity', 'Contact details corrected.');
    }

    /** POST /requests/{id}/json — correct any other field by editing the record. */
    public function correctJson(string $id): Response
    {
        if ($denied = $this->guard('sov.submission.delete')) {
            return $denied;
        }

        $json = (string) ($this->request->body['payload'] ?? '');

        // Stored compact, as the page sends it; the editor shows it indented.
        $decoded = RecordStore::decodeObject($json);
        $compact = $decoded === null
            ? $json
            : (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        $error = $this->store()->replacePayload($id, $compact);

        return $this->afterEdit($id, $error, 'json', 'Record corrected.', $error === null ? null : $json);
    }

    private function afterEdit(string $id, ?string $error, string $form, string $done, ?string $draft = null): Response
    {
        if ($error !== null) {
            $row = $this->store()->findReal($id);

            return $row === null ? $this->redirect($this->base()) : $this->page($row, [$form => $error], $draft, 422);
        }

        $this->audit('sov.request.correct', 'record', $id, ['form' => $form]);
        Session::put(self::FLASH, $done);

        return $this->redirect($this->base() . '/' . rawurlencode($id));
    }

    /** POST /requests/{id}/restrict — restrict, or lift a restriction. */
    public function restrict(string $id): Response
    {
        if ($denied = $this->guard('sov.submission.delete')) {
            return $denied;
        }

        $on = ($this->request->body['restricted'] ?? '1') === '1';

        if (!$this->store()->setRestricted($id, $on)) {
            return $this->redirect($this->base());
        }

        $this->audit($on ? 'sov.request.restrict' : 'sov.request.unrestrict', 'record', $id);
        Session::put(self::FLASH, $on
            ? 'Restricted. It is kept, but left out of /records and every export.'
            : 'Restriction lifted.');

        return $this->redirect($this->base() . '/' . rawurlencode($id));
    }

    /** POST /requests/{id}/delete — a deletion request, or consent withdrawn. */
    public function destroy(string $id): Response
    {
        if ($denied = $this->guard('sov.submission.delete')) {
            return $denied;
        }

        $reason = ($this->request->body['reason'] ?? '') === 'withdrawal' ? 'withdrawal' : 'deletion';

        if (($this->request->body['confirm'] ?? '') !== 'DELETE') {
            $row = $this->store()->findReal($id);

            return $row === null
                ? $this->redirect($this->base())
                : $this->page($row, ['delete' => 'Type DELETE to confirm. This cannot be undone.'], null, 422);
        }

        if ($this->store()->deleteReal($id)) {
            $this->audit('sov.request.delete', 'record', $id, ['reason' => $reason]);
            Session::put(self::FLASH, $reason === 'withdrawal'
                ? 'Consent withdrawn: the record has been deleted.'
                : 'The record has been deleted.');
        }

        return $this->redirect($this->base());
    }

    private function takeFlash(): ?string
    {
        $notice = Session::get(self::FLASH);
        Session::forget(self::FLASH);

        return is_string($notice) ? $notice : null;
    }
}
