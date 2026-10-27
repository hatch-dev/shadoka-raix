/**
 * Dialogs in the admin console.
 *
 * The single-file build opened the Data seeder as a modal over whatever view
 * you were on, and the nav item here does the same. A link carrying
 * `data-modal-src` fetches that URL and shows the returned markup in a dialog
 * instead of navigating to it.
 *
 * The link keeps a real href. With scripting off, or before this file has run,
 * clicking it opens the page — which exists, and carries the same form.
 *
 * Same classes and the same behaviour as the public site's `modal()`: backdrop
 * click, Escape, and Tab kept inside the panel. Written as a plain script
 * because the console does not load modules.
 */
(function () {
  'use strict';

  var root = document.getElementById('modalRoot');
  if (!root) return;

  var lastFocused = null;

  // Bumped by every dialog request and by close(), so a response that arrives
  // after a newer click, or after the dialog was closed, is dropped.
  var requestId = 0;

  function close() {
    requestId++;
    root.innerHTML = '';
    document.removeEventListener('keydown', onKeydown, true);
    if (lastFocused && lastFocused.focus) lastFocused.focus();
    lastFocused = null;
  }

  function onKeydown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }

    if (event.key !== 'Tab') return;

    // Keep Tab inside the dialog while it is open.
    var panel = root.querySelector('.modal');
    if (!panel) return;

    var focusable = panel.querySelectorAll(
      'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) return;

    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function esc(text) {
    return String(text).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function open(html, wide) {
    /*
     * A dialog already on screen (a console row opening a record, a record's
     * Organization button) swaps its CONTENTS in place. Rebuilding the whole
     * backdrop re-ran its blur and flashed the page behind on every step.
     */
    var backdrop = root.querySelector('.modal-bg');
    var panel;

    if (backdrop) {
      panel = backdrop.querySelector('.modal');
      panel.className = 'modal' + (wide ? ' wide' : '');
      panel.innerHTML = html;
      panel.scrollTop = 0;
    } else {
      lastFocused = document.activeElement;

      root.innerHTML =
        '<div class="modal-bg"><div class="modal' + (wide ? ' wide' : '') +
        '" role="dialog" aria-modal="true" tabindex="-1">' + html + '</div></div>';

      backdrop = root.querySelector('.modal-bg');
      panel = root.querySelector('.modal');

      backdrop.addEventListener('mousedown', function (event) {
        if (event.target === backdrop) close();
      });

      document.addEventListener('keydown', onKeydown, true);
    }

    panel.querySelectorAll('[data-close-modal]').forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        close();
      });
    });

    /*
     * A <script> that arrives through innerHTML is inert — the parser marks it
     * "already started" and never runs it. A fragment that draws something of
     * its own (the board dialog's radar) therefore does nothing at all, with no
     * error to say why. Re-creating the element is what makes it run.
     *
     * Only our own fragments reach here: the source is an attribute on a link
     * in a page this console rendered, never anything a visitor supplies.
     */
    panel.querySelectorAll('script').forEach(function (original) {
      if (original.type && original.type !== 'text/javascript') return;   // JSON payloads stay data

      var script = document.createElement('script');
      for (var i = 0; i < original.attributes.length; i++) {
        script.setAttribute(original.attributes[i].name, original.attributes[i].value);
      }
      script.textContent = original.textContent;
      original.replaceWith(script);
    });

    /*
     * The first field, so a dialog that asks for something is usable from the
     * keyboard at once. Not the first BUTTON: on a dialog that only reads —
     * the board profile — the first button is Close, at the foot, and focusing
     * it scrolls the panel past its own heading before anyone has seen it.
     * Focus falls to the panel itself there, which is what Escape and Tab are
     * already bound against.
     */
    var target = panel.querySelector('input,select,textarea') || panel;
    if (target.focus) target.focus();
  }

  /*
   * Delegated from the document rather than bound once at load, because the
   * console's own tabs and its Seeder button are links inside injected
   * markup — a querySelectorAll at startup could never have reached them.
   *
   * Modified clicks are left alone: ctrl/cmd-click still opens the real page
   * behind the link in a new tab, which is the reason the href is kept.
   */
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    var link = event.target && event.target.closest
      ? event.target.closest('[data-modal-src]')
      : null;

    if (!link) return;

    event.preventDefault();

    var source = link.getAttribute('data-modal-src');
    var wide = link.hasAttribute('data-modal-wide');

    /*
     * The dialog opens once, with its real content. A "Loading…" panel is shown
     * only if the fetch is slow; showing it every time put a panel on screen for
     * a fraction of a second and then replaced it at another size — the flicker.
     * It no longer reuses the trigger's text as its heading either: on a record
     * row that was the whole row, name, email, scores and all.
     *
     * `requestId` makes a slow response lose to a later click.
     */
    var mine = ++requestId;
    var slow = setTimeout(function () {
      if (mine === requestId) open('<p class="muted">Loading…</p>', wide);
    }, 250);

    fetch(source, { headers: { Accept: 'text/html' } })
      .then(function (response) {
        if (!response.ok) throw new Error('That screen could not be loaded (' + response.status + ').');
        return response.text();
      })
      .then(function (html) {
        clearTimeout(slow);
        if (mine === requestId) open(html, wide);
      })
      .catch(function (error) {
        clearTimeout(slow);
        if (mine !== requestId) return;
        /*
         * The page behind the link still works; offer it rather than leaving a
         * dead dialog. The href can be on the trigger itself or, when the
         * trigger is a table row, on the link inside it — a <tr> may not carry
         * one. Without either, there is nothing to offer and the button is
         * left off rather than pointing at "null".
         */
        var fallback = link.getAttribute('href');
        if (!fallback) {
          var inner = link.querySelector && link.querySelector('a[href]');
          fallback = inner ? inner.getAttribute('href') : '';
        }

        // Escaped: the trigger's text can be a respondent's own name.
        var title = (link.getAttribute('data-modal-title') || link.textContent || '').trim().split('\n')[0];

        open(
          '<h3>' + esc(title) + '</h3>' +
          '<p class="muted" style="margin-bottom:14px">' + esc(error.message) + '</p>' +
          '<div class="nav-row"><button class="btn quiet sm" data-close-modal>Cancel</button>' +
          (fallback ? '<a class="btn gold sm" href="' + esc(fallback) + '">Open the page</a>' : '') +
          '</div>',
          wide
        );
      });
  });
}());
