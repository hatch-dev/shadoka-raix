/**
 * The admin bar's scrolled state.
 *
 * The public site does this in ui.js `initChrome()`; the console loads plain
 * scripts rather than modules, so it is repeated here rather than imported.
 * Both do the same thing: the bar is transparent over the top of the page and
 * resolves into a solid one once anything has scrolled under it.
 */
(function () {
  'use strict';

  var nav = document.querySelector('body.admin > nav');
  if (!nav) return;

  function onScroll() {
    nav.classList.toggle('scrolled', window.scrollY > 24);
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
}());

/**
 * Say what just happened, then take the query parameter back out of the URL.
 *
 * The reference toasted after seeding — "Seeded 40 scorecards across 14 boards"
 * — and these are the same messages. Leaving the parameter in the address bar
 * would re-announce it on every reload and travel if the URL were shared.
 */
(function () {
  'use strict';

  var element = document.getElementById('toast');
  if (!element) return;

  var query = new URLSearchParams(location.search);
  var message = null;

  if (query.has('seeded')) {
    var records = Number(query.get('seeded')) || 0;
    var boards = Number(query.get('boards')) || 0;
    message = 'Seeded ' + records + ' scorecard' + (records === 1 ? '' : 's') +
      ' across ' + boards + ' board' + (boards === 1 ? '' : 's');
  } else if (query.has('purged')) {
    var removed = Number(query.get('purged')) || 0;
    message = removed === 0
      ? 'Nothing to remove'
      : 'Removed ' + removed + ' synthetic record' + (removed === 1 ? '' : 's');
  } else if (query.has('wiped')) {
    var wiped = Number(query.get('wiped')) || 0;
    message = wiped === 0
      ? 'There was nothing to delete'
      : 'Deleted ' + wiped + ' record' + (wiped === 1 ? '' : 's') + ' — the whole dataset';
  }

  if (message === null) return;

  element.textContent = message;
  element.classList.add('show');
  setTimeout(function () { element.classList.remove('show'); }, 2600);

  ['seeded', 'boards', 'purged', 'wiped'].forEach(function (key) { query.delete(key); });
  var search = query.toString();
  history.replaceState(null, '', location.pathname + (search ? '?' + search : '') + location.hash);
}());

/**
 * A submit button that says it is working.
 *
 * Any submit button carrying `data-busy` disables itself on submit and wears
 * that wording until the reply lands. Delegated from the document because the
 * form it was written for is also injected into a dialog, and a <script> inside
 * injected markup never runs.
 *
 * Three details that are the whole point of doing it here rather than inline:
 *
 *  - The disable happens a tick AFTER the event. Disabling a control during
 *    submit drops its own name and value from what is posted.
 *  - A second submit while busy is refused, which is what stops a double press
 *    running an expensive job twice.
 *  - Coming back to the page through history restores the button. Otherwise
 *    the back button lands you on a dead form.
 */
(function () {
  'use strict';

  var BUSY = 'is-busy';

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.tagName !== 'FORM') return;

    var button = event.submitter && event.submitter.hasAttribute('data-busy')
      ? event.submitter
      : form.querySelector('[data-busy]');

    if (!button) return;

    // Already running: refuse the second press rather than send it.
    if (button.classList.contains(BUSY)) {
      event.preventDefault();
      return;
    }

    // An onsubmit handler said no — a cancelled confirm(), say. Nothing was
    // sent, so nothing should look as though it is running.
    if (event.defaultPrevented) return;

    button.classList.add(BUSY);
    button.setAttribute('aria-busy', 'true');
    button.dataset.idleLabel = button.textContent;
    button.textContent = button.getAttribute('data-busy');

    setTimeout(function () { button.disabled = true; }, 0);
  });

  /* Restored from the back/forward cache, mid-submit state and all. */
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;

    [].forEach.call(document.querySelectorAll('[data-busy].' + BUSY), function (button) {
      button.classList.remove(BUSY);
      button.removeAttribute('aria-busy');
      button.disabled = false;
      if (button.dataset.idleLabel) button.textContent = button.dataset.idleLabel;
    });
  });
}());