/**
 * The one place the browser talks to the server.
 *
 * This replaces the reference build's sget/sset/sdel storage layer, which wrote
 * whole scorecards into sessionStorage under `raixent-v1-records` and lost every
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

  /** Finalise. The server scores it; the browser never computes a level. */
  submit: (token) => request('POST', '/api/submissions/' + encodeURIComponent(token) + '/submit'),

  /** Accept the result. Returns the confirmation and the context to carry forward. */
  confirm: (token) => request('POST', '/api/submissions/' + encodeURIComponent(token) + '/confirm'),

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
const TOKEN_KEY = 'raix-es-token';

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
 * Context handed from a finished scorecard to the next one.
 *
 * Someone scoring a second organisation should not retype the sector, the size
 * band and the year — the reference carried those same three forward in
 * `saveAndNew()`. It is a hint for one form, not a record: the scorecard it came
 * from is already in the database, and the one it feeds has not started.
 *
 * sessionStorage rather than localStorage, and `take()` removes it as it reads,
 * so an abandoned handoff cannot quietly prefill an unrelated scorecard weeks
 * later.
 */
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
const CONSENT_KEY = 'raix-es-consent';

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

const CARRY_KEY = 'raix-es-carry';

export const carryForward = {
  set(context) {
    try {
      sessionStorage.setItem(CARRY_KEY, JSON.stringify(context));
    } catch {
      /* Without it the next form simply starts empty. */
    }
  },
  take() {
    try {
      const raw = sessionStorage.getItem(CARRY_KEY);
      sessionStorage.removeItem(CARRY_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  },
};
