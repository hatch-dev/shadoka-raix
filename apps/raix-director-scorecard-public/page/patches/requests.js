/* Requests from individuals (directive v13, C4), as a view INSIDE the scorecard
   page, under the page's own admin header. Served-page patch: inlined before
   </body> by Services/ScorecardPage, never loaded as a separate file.

   Talks to {API_BASE}/admin/requests/... (Controllers/Admin/RequestsApiController),
   which needs the admin sign-in the Admin dialog performs. Uses the page's own
   helpers and classes: go, esc, toast, fmtDate, download, .rec, .tag, .btn, .ferr.

   RULES FOR THIS FILE: it is joined onto one line when served, so every
   statement ends with a semicolon and comments are block comments only. */
(function () {
  var RQ = API_BASE + '/admin/requests';
  var state = { email: '', list: null, current: null };

  function root() { return document.getElementById('rqRoot'); }

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
    try { res = await fetch(RQ + path, opt); } catch (e) { return { ok: false, data: {}, error: 'The server could not be reached. Try again.' }; }
    var data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    var error = null;
    if (res.status === 401) { error = 'Your admin sign-in has ended. Choose Exit, then Admin, and sign in again.'; }
    else if (!res.ok) { error = data.error || ('Something went wrong (' + res.status + ').'); }
    return { ok: res.ok, data: data, error: error };
  }

  function header(extra) {
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
            + '<div><div class="t">' + (r.board ? esc(r.board) : '<span class="muted">Board not given</span>')
            + (r.restricted ? ' <span class="tag gold">Restricted</span>' : '') + '</div>'
            + '<div class="m">' + esc(r.name) + ' &middot; ' + esc(r.id) + ' &middot; completed ' + esc(fmtDate(r.completed_at))
            + ' &middot; deleted automatically by ' + esc(fmtDate(r.delete_by)) + '</div></div>'
            + '<div style="align-self:center"><button class="btn quiet sm" type="button">Open</button></div></div>';
        }).join('');
      }
    }
    root().innerHTML = header()
      + '<p class="lede">Find a director&rsquo;s scorecards by the email address they gave, then export, correct, restrict or delete one. Withdrawing consent deletes the record. Finish every request within one month.</p>'
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
    root().innerHTML = '<button class="btn quiet sm" type="button" onclick="raixRqBack()" style="margin-bottom:22px">&larr; All results</button>'
      + header(esc(m.name || 'Director') + (m.restricted ? ' <span class="tag gold" style="vertical-align:middle">Restricted</span>' : ''))
      + '<p class="muted small" style="margin-bottom:6px">' + esc(m.id) + ' &middot; ' + (m.board ? esc(m.board) : 'Board not given')
      + ' &middot; completed ' + esc(fmtDate(m.completed_at)) + ' &middot; deleted automatically by ' + esc(fmtDate(m.delete_by))
      + ' &middot; notice ' + (m.notice ? esc(m.notice) : '&mdash;') + '</p>'
      + '<div class="toolbar">'
      + '<button class="btn quiet sm" type="button" onclick="raixRqExport()">Export as JSON</button>'
      + '<button class="btn quiet sm" type="button" onclick="raixRqRestrict(' + (m.restricted ? 'false' : 'true') + ')">' + (m.restricted ? 'Lift restriction' : 'Restrict use') + '</button></div>'
      + (m.restricted ? '<p class="small muted">Restricted: kept, but left out of the benchmark, the admin views and every export.</p>' : '')
      + '<hr class="rule">'
      + '<h3 class="sub">Correct contact details</h3>'
      + '<p class="muted small" style="margin-bottom:14px">Each is stored twice in the record (at the top and inside org); both copies change together.</p>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px 20px">'
      + field('rq-name', 'Full name', r.userName || org.name)
      + field('rq-mail', 'Email', r.userEmail || org.email, 'email')
      + field('rq-phone', 'Phone', r.userPhone || org.phone)
      + field('rq-board', 'Board', org.org)
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

  /* A short screen (Requests before a search, Analytics or Research data with
     no records) left the page footer half way up the window. The active view
     gets a minimum height of "window minus header minus footer", so the
     footer sits at the bottom; a long screen simply grows past it. That
     height depends only on the window, never on the content, so views that
     fill in later (Analytics awaits its data) need no second pass. Runs on
     every view change (go() is wrapped below) and on resize, for every view. */
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

  function paint() {
    if (state.current) { paintRecord(); } else { paintSearch(); }
    window.scrollTo({ top: 0 });
    fitToWindow();
  }

  async function search(email) {
    state.email = email;
    var out = await call('/search', 'POST', { email: email });
    if (!out.ok) { state.list = null; paintSearch(); showErr('rq-err', out.error); return; }
    state.list = out.data.records || [];
    paintSearch();
    fitToWindow();
  }

  window.raixRequests = function () {
    go('requests');
    state.current = null;
    paint();
  };

  window.raixRqFind = function () {
    var v = (document.getElementById('rq-email').value || '').trim();
    if (!v) { showErr('rq-err', 'Enter an email address.'); return; }
    search(v);
  };

  window.raixRqOpen = async function (id) {
    var out = await call('/' + encodeURIComponent(id));
    if (!out.ok) { toast(out.error); return; }
    state.current = out.data;
    paint();
  };

  /* Research data: a row with no board had no click (a board row opens its
     board modal, openOrg). It now opens the same kind of modal for that one
     scorecard: level, the seven bars, the radar, and its PDF. Built from the
     page's own pieces (modal, dimRows, killChart, charts, exportPDF), so it
     looks and behaves like the board modal. */
  window.raixOpenRecord = async function (id) {
    var r = researchRecords(await getRecords()).find(function (x) { return x.id === id; });
    if (!r) { toast('That scorecard is no longer in the dataset.'); return; }
    var lv = r.level ? RUNGS.find(function (x) { return x.name === r.level; }) : null;
    var o = r.org || {};
    var who = r.userName || o.name || 'Director';
    var facts = [o.sector, o.size ? o.size + ' employees' : '', o.role, r.year].filter(Boolean).map(esc).join(', ');
    modal('<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px"><div><h3>' + esc(who) + '</h3>'
      + '<p class="muted small">Board not given. ' + facts + (facts ? '. ' : '') + 'Scored ' + esc(fmtDate(r.timestamp)) + '.'
      + (r.synthetic ? ' <span class="tag syn">Synthetic</span>' : '') + '</p></div>'
      + '<div style="text-align:right"><div style="font-family:var(--num);font-size:40px;font-weight:700;letter-spacing:-.03em;line-height:1;color:' + (lv ? lv.c : 'var(--stone)') + '">' + (lv ? lv.v : '&mdash;') + '</div>'
      + '<div class="small muted" style="margin-top:4px">' + (lv ? lv.name : '') + ', floor ' + fmt(r.floor) + ', average ' + fmt(r.mean) + '</div></div></div>'
      + '<div style="margin:18px 0">' + dimRows(r.dims || {}, null, r.floorCode) + '</div>'
      + '<div style="height:260px;margin-top:18px"><canvas id="recRadar"></canvas></div>'
      + '<div class="nav-row"><button class="btn quiet sm" onclick="raixRecordPDF(\'' + esc(r.id) + '\')">Download PDF</button><button class="btn sm" onclick="closeModal()">Close</button></div>');
    document.querySelector('.modal').classList.add('wide');
    setTimeout(function () {
      var c = document.getElementById('recRadar');
      if (!c || !window.Chart) { return; }
      killChart('org');
      charts.org = new Chart(c, { type: 'radar',
        data: { labels: DIMS.map(function (d) { return d.code; }),
          datasets: [{ label: 'Scorecard', data: DIMS.map(function (d) { return (r.dims || {})[d.code] == null ? null : r.dims[d.code]; }),
            borderColor: '#E8933F', backgroundColor: 'rgba(232,147,63,.12)', borderWidth: 1.6, pointRadius: 3 }] },
        options: { responsive: true, maintainAspectRatio: false,
          scales: { r: { min: 0, max: 4, ticks: { stepSize: 1, color: '#6F84A0', backdropColor: 'transparent', font: { size: 10 } },
            grid: { color: 'rgba(61,130,196,.18)' }, angleLines: { color: 'rgba(61,130,196,.18)' },
            pointLabels: { color: '#A0B4CA', font: { family: 'IBM Plex Mono', size: 11 } } } },
          plugins: { legend: { labels: { color: '#A0B4CA', boxWidth: 12 } } } } });
    }, 60);
  };

  /* The director's own scorecard PDF (the results page's), for this record. */
  window.raixRecordPDF = async function (id) {
    var r = researchRecords(await getRecords()).find(function (x) { return x.id === id; });
    if (!r) { return; }
    var kept = lastRecord;
    lastRecord = r;
    try { exportPDF(); } finally { lastRecord = kept; }
  };

  window.raixRqBack = function () {
    state.current = null;
    if (state.email) { search(state.email); } else { paint(); }
    window.scrollTo({ top: 0 });
  };

  window.raixRqExport = async function () {
    var id = state.current.meta.id;
    var res;
    try { res = await fetch(RQ + '/' + encodeURIComponent(id) + '/export', { credentials: 'same-origin' }); } catch (e) { res = null; }
    if (!res || !res.ok) { toast(res && res.status === 401 ? 'Your admin sign-in has ended.' : 'The record could not be exported.'); return; }
    download('raix-scorecard-' + id + '.json', await res.text(), 'application/json');
  };

  window.raixRqRestrict = async function (on) {
    var out = await call('/' + encodeURIComponent(state.current.meta.id) + '/restrict', 'POST', { restricted: on });
    if (!out.ok) { toast(out.error); return; }
    state.current = out.data;
    paint();
    toast(on ? 'Restricted' : 'Restriction lifted');
  };

  window.raixRqSaveIdentity = async function () {
    var val = function (id) { return document.getElementById(id).value; };
    var out = await call('/' + encodeURIComponent(state.current.meta.id) + '/identity', 'POST', {
      name: val('rq-name'), email: val('rq-mail'), phone: val('rq-phone'), board: val('rq-board')
    });
    if (!out.ok) { showErr('rq-err-identity', out.error); return; }
    state.current = out.data;
    paint();
    toast('Contact details corrected');
  };

  window.raixRqSaveRecord = async function () {
    var out = await call('/' + encodeURIComponent(state.current.meta.id) + '/record', 'POST', {
      payload: document.getElementById('rq-json').value
    });
    if (!out.ok) { showErr('rq-err-json', out.error); return; }
    state.current = out.data;
    paint();
    toast('Record corrected');
  };

  window.raixRqDelete = async function () {
    var reason = document.getElementById('rq-reason').value;
    var out = await call('/' + encodeURIComponent(state.current.meta.id) + '/delete', 'POST', {
      reason: reason, confirm: document.getElementById('rq-confirm').value.trim()
    });
    if (!out.ok) { showErr('rq-err-delete', out.error); return; }
    toast(reason === 'withdrawal' ? 'Consent withdrawn: the record has been deleted' : 'The record has been deleted');
    raixRqBack();
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
