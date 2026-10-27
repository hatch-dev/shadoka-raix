/**
 * The one place the browser talks to the server.
 *
 * This replaces the legacy build's sget/sset/sdel storage layer, which wrote
 * scorecards into sessionStorage and lost them when the tab closed. Everything
 * now round-trips through the database, and a token is all a director needs to
 * come back to their own result.
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
     * nothing and it is what stops a future endpoint outside /api/ answering
     * a fetch with a whole HTML page.
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
    throw new ApiError('The server could not be reached. Your answers are still on this page.', 0, {}, cause);
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
  /** The published instrument: competencies, items, rungs, reference lists. */
  instrument: () => request('GET', '/api/instrument'),

  /** Open a scorecard. Returns { token, status }. */
  start: (profile) => request('POST', '/api/submissions', profile),

  /** Reopen one — progress while in flight, the full result once complete. */
  show: (token) => request('GET', '/api/submissions/' + encodeURIComponent(token)),

  /** Save marks. Partial by design; called as the director answers. */
  save: (token, responses) =>
    request('PATCH', '/api/submissions/' + encodeURIComponent(token), { responses }),

  /** Finalise. The server scores it; the browser never computes a level. */
  submit: (token) => request('POST', '/api/submissions/' + encodeURIComponent(token) + '/submit'),

  /*
   * The three admin-gate calls below are the only requests that leave this
   * app's own prefix — the console is the platform's, mounted at /admin. They
   * build their path from `data-admin-base` for the same reason everything
   * else uses `data-api-base`: nothing here may hard-code where the site is
   * installed.
   */

  /** Does this visitor already have a session? If not, a CSRF token to sign in with. */
  adminGate: (next) =>
    request('GET', '/login' + (next ? '?next=' + encodeURIComponent(next) : ''), undefined, adminBase()),

  /** Sign in from the dialog with the shared PIN. Verified on the server. */
  adminPin: (csrf, pin, next) => request('POST', '/pin', { _token: csrf, pin, next }, adminBase()),

  /** Sign in from the dialog with an account. Same checks as the page. */
  adminLogin: (csrf, email, password, next) =>
    request('POST', '/login', { _token: csrf, email, password, next }, adminBase()),

  /** Accept the result. Returns the confirmation and the board context to carry forward. */
  confirm: (token) => request('POST', '/api/submissions/' + encodeURIComponent(token) + '/confirm'),

  /** Tick the things that happen before the next board meeting. */
  commitments: (token, commitments) =>
    request('PATCH', '/api/submissions/' + encodeURIComponent(token) + '/commitments', { commitments }),
};

/**
 * Remember the token for this browser.
 *
 * localStorage is a convenience, not the record: the scorecard lives in the
 * database and the token in the URL. Clearing site data loses the shortcut, not
 * the scorecard — which is the opposite of how the legacy build worked.
 */
const TOKEN_KEY = 'raix-ds-token';

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
 * Board context handed from a finished scorecard to the next one.
 *
 * A director scoring a second seat on the same board should not retype the
 * sector, the company size and the year — the single-file build carried those
 * same three forward. It is a hint for one form, not a record: the scorecard it
 * came from is already in the database, and the one it feeds has not started.
 *
 * sessionStorage rather than localStorage, and `take()` removes it as it reads,
 * so an abandoned handoff cannot quietly prefill an unrelated scorecard weeks
 * later.
 */
const CARRY_KEY = 'raix-ds-carry';

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
