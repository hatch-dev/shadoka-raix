/**
 * The one place the browser talks to the server.
 *
 * This replaces the reference build's sget/sset/sdel storage layer, which wrote
 * whole scorecards into sessionStorage under `raixsov-v2-records` and lost every
 * one of them when the tab closed. Everything now round-trips through the
 * database, and a token is all a respondent needs to come back to their result.
 */

const BASE = document.documentElement.dataset.apiBase || '';

/** The platform admin console, which is mounted outside this app's prefix. */
function adminBase() {
  return document.documentElement.dataset.adminBase || '/admin';
}

async function request(method, path, body, base = BASE) {
  let response;

  const headers = {
    /*
     * Stated explicitly. The server decides between an HTML page and JSON by
     * looking at Accept (or a path containing /api/). Every route this file
     * calls qualifies by path, so the header is belt and braces — but it costs
     * nothing and it is what stops a future endpoint outside /api/ answering a
     * fetch with a whole HTML page.
     */
    Accept: 'application/json',
  };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  try {
    response = await fetch(base + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch (cause) {
    // A dropped connection must not look like a validation failure.
    throw new ApiError(
      'The server could not be reached. Your answers are still on this page.',
      0,
      {},
      cause,
    );
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    /* A non-JSON body (a proxy error page, say) leaves payload null. */
  }

  if (!response.ok || !payload || payload.success === false) {
    throw new ApiError(
      payload?.message || 'Something went wrong (' + response.status + ').',
      response.status,
      payload?.errors || {},
    );
  }

  return payload.data;
}

export class ApiError extends Error {
  constructor(message, status, errors, cause) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors || {};
    this.cause = cause;
  }
}

export const api = {
  /** The published instrument: dimensions, questions with both anchors, levels. */
  instrument: () => request('GET', '/api/instrument'),

  /** Open a scorecard. Returns { token, status }. */
  start: (profile) => request('POST', '/api/submissions', profile),

  /** Reopen one — progress while in flight, the full result once complete. */
  show: (token) => request('GET', '/api/submissions/' + encodeURIComponent(token)),

  /** Save marks. Partial by design; called as the respondent answers. */
  save: (token, responses) =>
    request('PATCH', '/api/submissions/' + encodeURIComponent(token), { responses }),

  /**
   * Rewrite the profile of a scorecard already open — what Back to step 0 and
   * Continue does. Deliberately NOT start(): that would open a second record.
   */
  saveProfile: (token, profile) =>
    request('PATCH', '/api/submissions/' + encodeURIComponent(token), { profile }),

  /**
   * "Start over": delete the unfinished draft (the server refuses a finished
   * one). Never throws and never waits more than a moment — removing the draft
   * is housekeeping, and the respondent's blank scorecard must not depend on it.
   */
  discardQuietly: (token) =>
    Promise.race([
      request('DELETE', '/api/submissions/' + encodeURIComponent(token)).catch(() => null),
      new Promise((resolve) => setTimeout(resolve, 2500, null)),
    ]),

  /** Finalise. The server scores it; the browser never computes a level. */
  submit: (token) => request('POST', '/api/submissions/' + encodeURIComponent(token) + '/submit'),

  /*
   * The three admin-gate calls below are the only requests that leave this app's
   * own prefix — the console is the platform's, mounted at /admin. They build
   * their path from `data-admin-base` for the same reason everything else uses
   * `data-api-base`: nothing here may hard-code where the site is installed.
   */

  /**
   * Does this visitor already have a session? If not, a CSRF token to sign in
   * with.
   *
   * `next` is where to land afterwards. Without it the console's own rule
   * applies — the first section of the DEFAULT app — and signing in from this
   * app's Admin dialog would drop you into the Director Scorecard's analytics,
   * which is a different instrument's dataset. The platform validates it
   * (`safeNext()`: inside /admin, no scheme-relative escape) before it is used,
   * so this is the parameter to use rather than a redirect of our own.
   */
  adminGate: (next) =>
    request('GET', '/login' + (next ? '?next=' + encodeURIComponent(next) : ''), undefined, adminBase()),

  /** Sign in from the dialog with the shared PIN. Verified on the server. */
  adminPin: (csrf, pin, next) => request('POST', '/pin', { _token: csrf, pin, next }, adminBase()),

  /** Sign in from the dialog with an account. Same checks as the page. */
  adminLogin: (csrf, email, password, next) =>
    request('POST', '/login', { _token: csrf, email, password, next }, adminBase()),
};

/**
 * Remember the token for this browser.
 *
 * localStorage is a convenience, not the record: the scorecard lives in the
 * database and the token in the URL. Clearing site data loses the shortcut, not
 * the scorecard — which is the opposite of how the reference build worked, where
 * clearing site data lost everything anyone had ever scored.
 *
 * The key is namespaced away from the Director Scorecard's. Both apps can be
 * served from one origin, and a shared key would have each one reopening the
 * other's scorecard.
 */
const TOKEN_KEY = 'raix-sov-token';

export const tokenStore = {
  get() {
    try {
      return localStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  },
  set(token) {
    try {
      localStorage.setItem(TOKEN_KEY, token);
    } catch {
      /* Private window, or storage disabled. The URL still carries the token. */
    }
  },
  clear() {
    try {
      localStorage.removeItem(TOKEN_KEY);
    } catch {
      /* Nothing to do. */
    }
  },
};

/**
 * That the terms were accepted, carried from wherever the gate was shown to the
 * form that acts on it.
 *
 * The reference kept this in its sessionStorage progress blob
 * (`A.profile.consent = {agreed, at, terms}`) because its gate and its form were
 * two views of one page. Here the gate can be opened on the hero and the form
 * lives at /assess, so the fact has to survive one navigation.
 *
 * It is NOT the record. The record is written server-side when the scorecard is
 * started — the timestamp and the URL of the terms that were shown — and that
 * write refuses to happen without acceptance, so clearing this key loses the
 * convenience and not the gate: /assess simply asks again.
 */
const CONSENT_KEY = 'raix-sov-consent';

export const consentStore = {
  accept() {
    try {
      sessionStorage.setItem(CONSENT_KEY, '1');
    } catch {
      /* Private window. The gate is shown again on /assess, which is correct. */
    }
  },
  accepted() {
    try {
      return sessionStorage.getItem(CONSENT_KEY) === '1';
    } catch {
      return false;
    }
  },
  clear() {
    try {
      sessionStorage.removeItem(CONSENT_KEY);
    } catch {
      /* Nothing to do. */
    }
  },
};

/**
 * Where the scorecard in progress stands — the reference's `K_PROG`.
 *
 * That build kept `{...A, step}` in sessionStorage and `startAssessment()` read
 * it back: a saved step above 0 meant "offer to resume", wherever the button was
 * pressed, and the dialog named the organisation and THAT step ("at step 1 of
 * 5"), not the first unanswered question. Only what the prompt needs is kept
 * here — the token, the step and the organisation's name; the answers are in the
 * database.
 *
 * sessionStorage, as there: a new tab or a new session has nothing to resume,
 * and the prompt does not follow the respondent into it.
 */
const PROGRESS_KEY = 'raix-sov-progress';

export const progressStore = {
  get() {
    try {
      const raw = sessionStorage.getItem(PROGRESS_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  },
  set(progress) {
    try {
      sessionStorage.setItem(PROGRESS_KEY, JSON.stringify(progress));
    } catch {
      /* Private window. There will simply be nothing to offer to resume. */
    }
  },
  clear() {
    try {
      sessionStorage.removeItem(PROGRESS_KEY);
    } catch {
      /* Nothing to do. */
    }
  },
};
