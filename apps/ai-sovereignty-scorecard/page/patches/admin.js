/* The admin's Requests and Settings views, INSIDE the v6 scorecard page, under
   the page's own admin header. Served-page patch: inlined before </body> by
   Services/ScorecardPage, never loaded as a separate file.

   Requests: requests from individuals (directive v6, C4), through
   {API_BASE}/admin/requests/... (Controllers/Admin/RequestsApiController).
   Settings: the admin password change, the same screen as the admin
   console's Settings, through {API_BASE}/admin/settings
   (Controllers/Admin/SettingsApiController).
   Both need the admin sign-in the Admin dialog performs. Uses the page's own
   helpers and classes: go, esc, toast, fmtDate, download, .rec, .tag, .btn,
   .ferr.

   RULES FOR THIS FILE: it is joined onto one line when served, so every
   statement ends with a semicolon and comments are block comments only. */
(function () {
  var ADMIN = API_BASE + '/admin';
  var state = { email: '', list: null, current: null };

  function root(id) { return document.getElementById(id); }

  function showErr(id, msg) {
    var el = document.getElementById(id);
    if (!el) { return; }
    el.textContent = msg || '';
    el.classList.toggle('show', !!msg);
  }

  async function call(path, method, body) {
    var opt = { method: method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    var res;
    try { res = await fetch(ADMIN + path, opt); } catch (e) { return { ok: false, status: 0, data: {}, error: 'The server could not be reached. Try again.' }; }
    var data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    var error = null;
    if (res.status === 401) { error = 'Your admin sign-in has ended. Choose Exit, then Admin, and sign in again.'; }
    else if (!res.ok) { error = data.error || ('Something went wrong (' + res.status + ').'); }
    return { ok: res.ok, status: res.status, data: data, error: error };
  }

  /* A short screen (Requests before a search, Settings) left the page footer
     half way up the window. The active view gets a minimum height of "window
     minus header minus footer", so the footer sits at the bottom; a long
     screen simply grows past it. It depends only on the window, never on the
     content, so views that fill in later need no second pass. Runs on every
     view change (go() is wrapped below) and on resize, for every view. */
  function fitToWindow() {
    var view = document.querySelector('.view.active');
    if (!view) { return; }
    var foot = document.querySelector('footer');
    var footH = 0;
    if (foot) {
      var cs = getComputedStyle(foot);
      footH = foot.getBoundingClientRect().height + parseFloat(cs.marginTop || 0) + parseFloat(cs.marginBottom || 0);
    }
    var top = view.getBoundingClientRect().top + window.scrollY;
    view.style.minHeight = Math.max(0, Math.floor(window.innerHeight - top - footH)) + 'px';
  }
  window.addEventListener('resize', fitToWindow);

  var pageGo = go;
  go = function (id) { pageGo(id); fitToWindow(); };
  fitToWindow();

  /* ---------------------------------------------------------------- Requests */

  function rqHeader(extra) {
    return '<div class="eyebrow">Privacy</div>'
      + '<h2 class="title">' + (extra || 'Requests from individuals') + '</h2>';
  }

  function paintSearch() {
    var list = state.list;
    var rows = '';
    if (list !== null) {
      if (!list.length) {
        rows = '<div class="empty"><b>No scorecards for that address.</b><br>The search is exact and ignores case. It covers real scorecards only.</div>';
      } else {
        rows = list.map(function (r) {
          return '<div class="rec" style="cursor:pointer" onclick="raixRqOpen(\'' + esc(r.id) + '\')">'
            + '<div><div class="t">' + (r.organization ? esc(r.organization) : '<span class="muted">Organization not given</span>')
            + (r.restricted ? ' <span class="tag gold">Restricted</span>' : '') + '</div>'
            + '<div class="m">' + esc(r.name) + ' &middot; ' + esc(r.id) + ' &middot; completed ' + esc(fmtDate(r.completed_at))
            + ' &middot; deleted automatically by ' + esc(fmtDate(r.delete_by)) + '</div></div>'
            + '<div style="align-self:center"><button class="btn quiet sm" type="button">Open</button></div></div>';
        }).join('');
      }
    }
    root('rqRoot').innerHTML = rqHeader()
      + '<p class="lede">Find a person&rsquo;s scorecards by the email address they gave, then export, correct, restrict or delete one. Withdrawing consent deletes the record. Finish every request within one month.</p>'
      + '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:26px 0 6px">'
      + '<div style="flex:1;min-width:240px"><label class="f" for="rq-email">Email address</label>'
      + '<input class="f" id="rq-email" type="email" autocomplete="off" value="' + esc(state.email) + '" onkeydown="if(event.key===\'Enter\')raixRqFind()"></div>'
      + '<button class="btn gold" type="button" onclick="raixRqFind()">Find</button></div>'
      + '<div class="ferr" id="rq-err"></div>'
      + '<div style="margin-top:22px">' + rows + '</div>';
  }

  function field(id, label, value, type) {
    return '<div><label class="f" for="' + id + '">' + label + '</label>'
      + '<input class="f" id="' + id + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '"></div>';
  }

  function paintRecord() {
    var c = state.current;
    var m = c.meta;
    var r = c.record || {};
    var org = r.org || {};
    root('rqRoot').innerHTML = '<button class="btn quiet sm" type="button" onclick="raixRqBack()" style="margin-bottom:22px">&larr; All results</button>'
      + rqHeader(esc(m.name || 'Respondent') + (m.restricted ? ' <span class="tag gold" style="vertical-align:middle">Restricted</span>' : ''))
      + '<p class="muted small" style="margin-bottom:6px">' + esc(m.id) + ' &middot; ' + (m.organization ? esc(m.organization) : 'Organization not given')
      + ' &middot; completed ' + esc(fmtDate(m.completed_at)) + ' &middot; deleted automatically by ' + esc(fmtDate(m.delete_by))
      + ' &middot; notice ' + (m.notice ? esc(m.notice) : '&mdash;') + '</p>'
      + '<div class="toolbar">'
      + '<button class="btn quiet sm" type="button" onclick="raixRqExport()">Export as JSON</button>'
      + '<button class="btn quiet sm" type="button" onclick="raixRqRestrict(' + (m.restricted ? 'false' : 'true') + ')">' + (m.restricted ? 'Lift restriction' : 'Restrict use') + '</button></div>'
      + (m.restricted ? '<p class="small muted">Restricted: kept, but left out of the admin views and every export.</p>' : '')
      + '<hr class="rule">'
      + '<h3 class="sub">Correct contact details</h3>'
      + '<p class="muted small" style="margin-bottom:14px">Each is stored twice in the record (at the top and inside org); both copies change together.</p>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px 20px">'
      + field('rq-name', 'Full name', r.userName || org.name)
      + field('rq-mail', 'Email', r.userEmail || org.email, 'email')
      + field('rq-phone', 'Phone', r.userPhone || org.phone)
      + field('rq-org', 'Organization', org.org)
      + '</div>'
      + '<div style="margin-top:14px"><button class="btn gold sm" type="button" onclick="raixRqSaveIdentity()">Save corrections</button></div>'
      + '<div class="ferr" id="rq-err-identity"></div>'
      + '<hr class="rule">'
      + '<h3 class="sub">Correct any other field</h3>'
      + '<p class="muted small" style="margin-bottom:10px">The whole record as stored. It must stay one JSON object with the same id, synthetic: false and research consent.</p>'
      + '<textarea class="f" id="rq-json" rows="16" spellcheck="false" style="font-family:var(--mono);font-size:13px;line-height:1.45">' + esc(c.pretty || '') + '</textarea>'
      + '<div style="margin-top:12px"><button class="btn quiet sm" type="button" onclick="raixRqSaveRecord()">Save record</button></div>'
      + '<div class="ferr" id="rq-err-json"></div>'
      + '<hr class="rule">'
      + '<h3 class="sub">Delete</h3>'
      + '<p class="muted small" style="margin-bottom:14px">Removes the whole record. Use this for a deletion request and when consent is withdrawn. It cannot be undone.</p>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px 20px">'
      + '<div><label class="f" for="rq-reason">Reason</label><select class="f" id="rq-reason"><option value="deletion">Deletion request</option><option value="withdrawal">Consent withdrawn</option></select></div>'
      + '<div><label class="f" for="rq-confirm">Type DELETE to confirm</label><input class="f" id="rq-confirm" autocomplete="off"></div>'
      + '</div>'
      + '<div style="margin-top:14px"><button class="btn sm" type="button" style="border-color:var(--r1);color:var(--r1)" onclick="raixRqDelete()">Delete record</button></div>'
      + '<div class="ferr" id="rq-err-delete"></div>';
  }

  function paintRequests() {
    if (state.current) { paintRecord(); } else { paintSearch(); }
    window.scrollTo({ top: 0 });
    fitToWindow();
  }

  async function search(email) {
    state.email = email;
    var out = await call('/requests/search', 'POST', { email: email });
    if (!out.ok) { state.list = null; paintSearch(); showErr('rq-err', out.error); return; }
    state.list = out.data.records || [];
    paintSearch();
    fitToWindow();
  }

  window.raixRequests = function () {
    go('requests');
    state.current = null;
    paintRequests();
  };

  window.raixRqFind = function () {
    var v = (document.getElementById('rq-email').value || '').trim();
    if (!v) { showErr('rq-err', 'Enter an email address.'); return; }
    search(v);
  };

  window.raixRqOpen = async function (id) {
    var out = await call('/requests/' + encodeURIComponent(id));
    if (!out.ok) { toast(out.error); return; }
    state.current = out.data;
    paintRequests();
  };

  window.raixRqBack = function () {
    state.current = null;
    if (state.email) { search(state.email); } else { paintRequests(); }
    window.scrollTo({ top: 0 });
  };

  window.raixRqExport = async function () {
    var id = state.current.meta.id;
    var res;
    try { res = await fetch(ADMIN + '/requests/' + encodeURIComponent(id) + '/export', { credentials: 'same-origin' }); } catch (e) { res = null; }
    if (!res || !res.ok) { toast(res && res.status === 401 ? 'Your admin sign-in has ended.' : 'The record could not be exported.'); return; }
    download('ai-sovereignty-scorecard-' + id + '.json', await res.text(), 'application/json');
  };

  window.raixRqRestrict = async function (on) {
    var out = await call('/requests/' + encodeURIComponent(state.current.meta.id) + '/restrict', 'POST', { restricted: on });
    if (!out.ok) { toast(out.error); return; }
    state.current = out.data;
    paintRequests();
    toast(on ? 'Restricted' : 'Restriction lifted');
  };

  window.raixRqSaveIdentity = async function () {
    var val = function (id) { return document.getElementById(id).value; };
    var out = await call('/requests/' + encodeURIComponent(state.current.meta.id) + '/identity', 'POST', {
      name: val('rq-name'), email: val('rq-mail'), phone: val('rq-phone'), organization: val('rq-org')
    });
    if (!out.ok) { showErr('rq-err-identity', out.error); return; }
    state.current = out.data;
    paintRequests();
    toast('Contact details corrected');
  };

  window.raixRqSaveRecord = async function () {
    var out = await call('/requests/' + encodeURIComponent(state.current.meta.id) + '/record', 'POST', {
      payload: document.getElementById('rq-json').value
    });
    if (!out.ok) { showErr('rq-err-json', out.error); return; }
    state.current = out.data;
    paintRequests();
    toast('Record corrected');
  };

  window.raixRqDelete = async function () {
    var reason = document.getElementById('rq-reason').value;
    var out = await call('/requests/' + encodeURIComponent(state.current.meta.id) + '/delete', 'POST', {
      reason: reason, confirm: document.getElementById('rq-confirm').value.trim()
    });
    if (!out.ok) { showErr('rq-err-delete', out.error); return; }
    toast(reason === 'withdrawal' ? 'Consent withdrawn: the record has been deleted' : 'The record has been deleted');
    raixRqBack();
  };

  /* ---------------------------------------------------------------- Settings */

  /* The same screen as the admin console's Settings
     (platform/Admin/Views/settings.php): one centred column, "Admin password",
     a card with the show/hide fields, the strength meter, the rule checklist
     and the match line. Its styles are that view's, scoped to #stRoot. Field
     ids are st-* because the page's Admin dialog already uses id="pin". */
  var settings = null;
  var ST_CSS = [
    '#stRoot .st-head{display:flex;justify-content:center;text-align:center;align-items:flex-end;gap:24px;margin-bottom:26px}',
    '#stRoot h1.title{font-family:var(--serif);font-weight:700;font-size:clamp(28px,3.1vw,40px);line-height:1.15;letter-spacing:-.015em;margin-bottom:12px}',
    '#stRoot .st-notice{background:rgba(232,147,63,.1);border:1px solid rgba(232,147,63,.35);border-radius:4px;padding:12px 14px;font-size:14px;margin-bottom:20px}',
    '#stRoot .card{background:var(--ink-2);border:1px solid var(--rule-soft);border-radius:4px;padding:24px}',
    '#stRoot .card h3{font-family:var(--serif);font-weight:600;font-size:17px;margin-bottom:4px}',
    '#stRoot .card .cap{color:var(--stone-2);font-size:12.5px;margin-bottom:12px}',
    '#stRoot .err{color:var(--r1);font-size:14px;font-weight:500;margin-top:6px}',
    '#stRoot .st-col{max-width:560px;margin:-6px auto 0}',
    '#stRoot .card .btn{font-size:16px;font-weight:500;padding:12px 22px;line-height:1.5}',
    '#stRoot .pw-field{margin-bottom:18px}',
    '#stRoot .pw-wrap{position:relative}',
    '#stRoot .pw-wrap input.f{padding-right:72px}',
    '#stRoot .pw-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--blue-2);font:600 13px var(--sans);padding:6px 8px;cursor:pointer;border-radius:3px}',
    '#stRoot .pw-toggle:hover{color:var(--blue-3)}',
    '#stRoot .pw-field.has-error input.f{border-color:var(--r1)}',
    '#stRoot .pw-error{font-size:13.5px}',
    '#stRoot .pw-meter{display:flex;gap:6px;margin:12px 0 6px}',
    '#stRoot .pw-meter span{flex:1;height:5px;border-radius:3px;background:var(--rule);transition:background .2s}',
    '#stRoot .pw-meter[data-score="1"] span:nth-child(-n+1){background:var(--r1)}',
    '#stRoot .pw-meter[data-score="2"] span:nth-child(-n+2){background:var(--gold)}',
    '#stRoot .pw-meter[data-score="3"] span:nth-child(-n+3){background:var(--blue-2)}',
    '#stRoot .pw-meter[data-score="4"] span{background:var(--blue-3)}',
    '#stRoot .pw-strength{font-size:13px;color:var(--stone-2);min-height:18px}',
    '#stRoot .pw-strength b{color:var(--paper);font-weight:600}',
    '#stRoot .pw-rules{list-style:none;margin:10px 0 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px 14px}',
    '#stRoot .pw-rules li{font-size:13.5px;color:var(--stone-2);display:flex;align-items:center;gap:8px;line-height:1.35}',
    '#stRoot .pw-rules li::before{content:"";flex:none;width:14px;height:14px;border-radius:50%;border:1.5px solid var(--stone-2);box-sizing:border-box}',
    '#stRoot .pw-rules li.met{color:var(--paper)}',
    '#stRoot .pw-rules li.met::before{border-color:var(--blue-3);background:var(--blue-3) no-repeat center/10px url("data:image/svg+xml,%3Csvg xmlns=%27http:%2F%2Fwww.w3.org%2F2000%2Fsvg%27 viewBox=%270 0 10 10%27%3E%3Cpath d=%27M1.5 5.2l2.2 2.2L8.5 2.6%27 fill=%27none%27 stroke=%27%23060D18%27 stroke-width=%271.8%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27/%3E%3C/svg%3E")}',
    '#stRoot .pw-match{font-size:13px;margin-top:6px;min-height:18px;color:var(--stone-2)}',
    '#stRoot .pw-match.ok{color:var(--blue-3)}',
    '#stRoot .pw-match.bad{color:var(--r1)}',
    '@media (max-width:520px){#stRoot .pw-rules{grid-template-columns:1fr}}'
  ].join('');

  function pwField(id, label, autocomplete, extra) {
    return '<div class="pw-field">'
      + '<label class="f" for="' + id + '">' + label + '</label>'
      + '<div class="pw-wrap">'
      + '<input class="f" id="' + id + '" type="password" autocomplete="' + autocomplete + '" spellcheck="false" oninput="raixStInput(this)">'
      + '<button type="button" class="pw-toggle" onclick="raixStToggle(\'' + id + '\', this)" aria-label="Show password" aria-pressed="false">Show</button>'
      + '</div>' + (extra || '') + '</div>';
  }

  function stVal(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function stClearError(input) {
    var wrap = input.closest('.pw-field');
    var err = wrap && wrap.querySelector('.pw-error');
    if (wrap) { wrap.classList.remove('has-error'); }
    if (err) { err.remove(); }
    input.removeAttribute('aria-invalid');
  }

  function stShowError(input, message) {
    stClearError(input);
    var wrap = input.closest('.pw-field');
    var err = document.createElement('div');
    err.className = 'err pw-error';
    err.setAttribute('role', 'alert');
    err.textContent = message;
    wrap.querySelector('.pw-wrap').insertAdjacentElement('afterend', err);
    wrap.classList.add('has-error');
    input.setAttribute('aria-invalid', 'true');
  }

  function stFormError(message) {
    var el = document.getElementById('st-form-err');
    if (!el) { return; }
    el.textContent = message || '';
    el.style.display = message ? '' : 'none';
  }

  function stRuleMet(li, v) {
    if (li.getAttribute('data-rule') === 'length') {
      return v.length >= +li.getAttribute('data-min') && v.length <= +li.getAttribute('data-max');
    }
    return new RegExp(li.getAttribute('data-pattern')).test(v);
  }

  /* The console's check(): ticks the rules, sets the meter, reports the match. */
  function stCheck() {
    var rules = Array.prototype.slice.call(document.querySelectorAll('#stRoot .pw-rules li'));
    var meter = document.getElementById('st-meter');
    var strength = document.getElementById('st-strength');
    var match = document.getElementById('st-match');
    if (!meter) { return false; }
    var v = stVal('st-pin');
    var met = 0;
    rules.forEach(function (li) { var ok = stRuleMet(li, v); li.classList.toggle('met', ok); if (ok) { met++; } });
    var allMet = met === rules.length;
    var score = v === '' ? 0 : allMet ? (v.length >= 12 ? 4 : 3) : met >= 3 ? 2 : 1;
    meter.setAttribute('data-score', String(score));
    strength.innerHTML = v === '' ? '' : 'Strength: <b>' + ['', 'Weak', 'Fair', 'Good', 'Strong'][score] + '</b>'
      + (allMet ? '' : ' — ' + (rules.length - met) + ' requirement' + (rules.length - met === 1 ? '' : 's') + ' left');
    var c = stVal('st-confirm');
    match.className = 'pw-match';
    if (c === '') { match.textContent = ''; }
    else if (c === v) { match.className = 'pw-match ok'; match.textContent = 'Passwords match.'; }
    else { match.className = 'pw-match bad'; match.textContent = 'Passwords do not match yet.'; }
    var current = document.getElementById('st-current');
    return allMet && c === v && (!current || stVal('st-current') !== '');
  }

  function paintSettings(note) {
    var cap = !settings ? '&nbsp;' : settings.changed_at ? 'Last changed ' + esc(fmtDate(settings.changed_at)) : 'No password has been set yet.';
    var rules = '';
    if (settings) {
      rules = '<li data-rule="length" data-min="' + settings.min + '" data-max="' + settings.max + '">At least ' + settings.min + ' characters</li>'
        + settings.rules.map(function (r) { return '<li data-rule="' + esc(r.key) + '" data-pattern="' + esc(r.pattern) + '">' + esc(r.label) + '</li>'; }).join('');
    }
    root('stRoot').innerHTML = '<style>' + ST_CSS + '</style>'
      + '<div class="st-col">'
      + '<div class="st-head"><div><div class="eyebrow">Settings</div><h1 class="title">Admin password</h1>'
      + '<p class="lede">Controls access to the admin console for The AI Sovereignty Scorecard.</p></div></div>'
      + (note ? '<div class="st-notice" role="status">' + esc(note) + '</div>' : '')
      + '<div class="card"><h3>Change password</h3><p class="cap">' + cap + '</p>'
      + '<div class="err" id="st-form-err" role="alert" style="margin-bottom:14px;display:none"></div>'
      + (settings && !settings.pin_enabled ? '' : pwField('st-current', 'Current password', 'current-password'))
      + pwField('st-pin', 'New password', 'new-password',
          '<div class="pw-meter" id="st-meter" data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>'
          + '<div class="pw-strength" id="st-strength" aria-live="polite"></div>'
          + '<ul class="pw-rules">' + rules + '</ul>')
      + pwField('st-confirm', 'Confirm new password', 'new-password', '<div class="pw-match" id="st-match" aria-live="polite"></div>')
      + '<button class="btn gold" type="button" onclick="raixStSave()">Change password</button>'
      + '</div></div>';
    stCheck();
    fitToWindow();
  }

  /* Typing in a field clears the server's error for it; it is stale now. */
  window.raixStInput = function (input) { stClearError(input); stCheck(); };

  window.raixStToggle = function (id, btn) {
    var input = document.getElementById(id);
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  };

  window.raixSettings = async function (note) {
    go('settings');
    paintSettings(note);
    var out = await call('/settings');
    if (!out.ok) { stFormError(out.error); return; }
    settings = out.data;
    paintSettings(note);
  };

  window.raixStSave = async function () {
    stFormError('');
    var current = document.getElementById('st-current');
    var pin = document.getElementById('st-pin');
    var confirm = document.getElementById('st-confirm');

    /* As the console: not a disabled button, which cannot say WHY; an early
       press points at the first thing still missing. */
    if (!stCheck()) {
      var rulesLeft = Array.prototype.some.call(document.querySelectorAll('#stRoot .pw-rules li'), function (li) { return !li.classList.contains('met'); });
      var target = current && stVal('st-current') === '' ? current : rulesLeft ? pin : confirm;
      if (target === current) { stShowError(current, 'Enter the current password.'); }
      else if (target === pin) { stShowError(pin, 'The new password does not meet every requirement below.'); }
      else { stShowError(confirm, stVal('st-confirm') === '' ? 'Confirm the new password.' : 'The two passwords do not match.'); }
      target.focus();
      return;
    }

    var out = await call('/settings/password', 'POST', {
      current: current ? current.value : '', pin: pin.value, pin_confirm: confirm.value
    });
    if (out.ok) {
      raixSettings('Password changed. The AI Sovereignty Scorecard admin now opens with the new one.');
      return;
    }
    var errors = (out.data && out.data.errors) || {};
    var fields = { current: current, pin: pin, pin_confirm: confirm };
    var shown = false;
    Object.keys(errors).forEach(function (k) {
      if (fields[k]) { stShowError(fields[k], errors[k]); shown = true; }
      else if (k === 'form') { stFormError(errors[k]); shown = true; }
    });
    if (!shown) { stFormError(out.error); }
  };
})();

/* The phone header menu (screens 900px and narrower; owner's request,
   1 Oct 2026). The served page adds a menu button after the logo (hidden
   on wider screens, where nothing here has any effect); it opens and closes
   the header's buttons as a list below the header. A choice from the list,
   a tap outside the header, Escape, or widening the window closes it. */
(function () {
  var nav = document.querySelector('nav');
  var btn = document.querySelector('nav .raix-menu');
  var links = document.getElementById('navLinks');
  if (!nav || !btn || !links) { return; }

  function setOpen(open) {
    nav.classList.toggle('raix-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }

  window.raixMenu = function () { setOpen(!nav.classList.contains('raix-open')); };
  links.addEventListener('click', function (e) { if (e.target.closest('button')) { setOpen(false); } });
  document.addEventListener('click', function (e) { if (nav.classList.contains('raix-open') && !nav.contains(e.target)) { setOpen(false); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && nav.classList.contains('raix-open')) { setOpen(false); btn.focus(); } });
  window.addEventListener('resize', function () { if (window.innerWidth > 900) { setOpen(false); } });
})();
