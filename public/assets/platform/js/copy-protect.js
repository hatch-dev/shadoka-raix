/*
 * Copy protection, on every RAIX page: the public scorecards (their in-page
 * admin views included), the admin consoles and RAIX_Index.html.
 *
 * A port of the free feature set of the WordPress plugin "WP Content Copy
 * Protection & No Right Click" (owner's request, 20 Nov 2026):
 *
 *   - no context menu (right click, or long press on a phone);
 *   - no text selection, and nothing to copy or cut;
 *   - Ctrl/Cmd + A, C, X, S, V and U do nothing, nor do the developer-tools
 *     shortcuts F12, Ctrl+Shift+I / J / C (Windows, Linux) and
 *     Cmd+Option+I / J / C / U (Mac); F12 and J / C go beyond the plugin's
 *     free version (owner's request, 20 Nov 2026);
 *   - images cannot be dragged off the page (or saved, with no context menu);
 *   - a short notice says why, instead of the plugin's alert() box.
 *
 * Form fields are left alone, as the plugin leaves them: inside an input,
 * a textarea, a select or an editable area, people can still select, copy,
 * paste and right-click (sign-in passwords, email addresses, the admin
 * Requests search). Printing is NOT blocked: Ctrl+P and the browser's own
 * print still work, and the PDF downloads are untouched either way.
 *
 * This is a deterrent, not a security control. Anyone can switch JavaScript
 * off, open the browser's menu, use View Source or take a screenshot. Nothing
 * here keeps data from a determined visitor; the server is what does that.
 *
 * Loaded with `defer` by copy_protection_tag() (platform/Support/helpers.php),
 * switched off for the whole platform with COPY_PROTECTION=false in .env. The
 * notice's text comes from the tag's data-message. Self-contained: no globals
 * read from the page, nothing written to it but one <style> and the notice,
 * and every handler only calls preventDefault(), so the pages' own handlers
 * still run.
 */
(function () {
  'use strict';

  if (window.__raixCopyProtect) {
    return;
  }
  window.__raixCopyProtect = true;

  var tag     = document.currentScript;
  var MESSAGE = (tag && tag.getAttribute('data-message')) || 'Content is protected.';

  var FIELD = 'input, textarea, select, [contenteditable]:not([contenteditable="false"])';

  /* A text node has no closest(); start from its element. */
  function elementOf(node) {
    return node && node.nodeType === 1 ? node : (node && node.parentElement) || null;
  }

  function inField(node) {
    var el = elementOf(node);
    return !!(el && (el.isContentEditable || (el.closest && el.closest(FIELD))));
  }

  /* The focused field counts too: copy and keydown can target <body>. */
  function allowed(e) {
    return inField(e.target) || inField(document.activeElement);
  }

  /* ---- The notice ------------------------------------------------------- */

  var notice = null;
  var timer  = 0;

  function say() {
    if (!document.body) {
      return;
    }
    if (!notice) {
      notice = document.createElement('div');
      notice.setAttribute('role', 'status');
      notice.setAttribute('aria-live', 'polite');
      notice.className = 'raix-cp-notice';
      document.body.appendChild(notice);
    }
    notice.textContent = MESSAGE;
    notice.classList.add('raix-cp-show');
    clearTimeout(timer);
    timer = setTimeout(function () { notice.classList.remove('raix-cp-show'); }, 1800);
  }

  /* ---- No selection: CSS for the look, selectstart for the behaviour ---- */

  /*
   * The fields get user-select:text back explicitly: iOS Safari will not let
   * anyone type into a field whose ancestor is user-select:none.
   */
  var style = document.createElement('style');
  style.textContent =
      'html{-webkit-user-select:none;user-select:none;-webkit-touch-callout:none}'
    + 'input,textarea,select,[contenteditable]:not([contenteditable="false"]),'
    + '[contenteditable]:not([contenteditable="false"]) *'
    + '{-webkit-user-select:text;user-select:text;-webkit-touch-callout:default}'
    + 'img{-webkit-user-drag:none}'
    + '.raix-cp-notice{position:fixed;left:50%;bottom:24px;z-index:2147483647;'
    + 'transform:translate(-50%,8px);max-width:calc(100% - 32px);box-sizing:border-box;'
    + 'padding:10px 16px;border-radius:6px;background:rgba(17,24,39,.94);color:#fff;'
    + 'font:500 14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
    + 'box-shadow:0 6px 24px rgba(0,0,0,.25);pointer-events:none;opacity:0;'
    + 'transition:opacity .2s,transform .2s}'
    + '.raix-cp-notice.raix-cp-show{opacity:1;transform:translate(-50%,0)}'
    + '@media (prefers-reduced-motion:reduce){.raix-cp-notice{transition:none}}'
    + '@media print{.raix-cp-notice{display:none}}';
  (document.head || document.documentElement).appendChild(style);

  document.addEventListener('selectstart', function (e) {
    if (!inField(e.target)) {
      e.preventDefault();
    }
  }, true);

  /* ---- No context menu -------------------------------------------------- */

  document.addEventListener('contextmenu', function (e) {
    if (!inField(e.target)) {
      e.preventDefault();
      say();
    }
  }, true);

  /* ---- Nothing to copy or cut ------------------------------------------- */

  function guardClipboard(e) {
    if (!allowed(e)) {
      e.preventDefault();
      say();
    }
  }
  document.addEventListener('copy', guardClipboard, true);
  document.addEventListener('cut', guardClipboard, true);

  /* ---- No dragging images (or selected text) off the page --------------- */

  document.addEventListener('dragstart', function (e) {
    var el = elementOf(e.target);
    if (inField(el) || (el && el.closest && el.closest('[draggable="true"]'))) {
      return;
    }
    e.preventDefault();
    if (el && el.tagName === 'IMG') {
      say();
    }
  }, true);

  /* ---- Keyboard shortcuts ---------------------------------------------- */

  /*
   * By physical key (e.code) where the browser gives one: with Option held a
   * Mac reports e.key as a symbol ("ˆ" for I), and other keyboard layouts
   * move the letters about.
   */
  function letterOf(e) {
    if (e.code && /^Key[A-Z]$/.test(e.code)) {
      return e.code.charAt(3).toLowerCase();
    }
    return (e.key || '').toLowerCase();
  }

  window.addEventListener('keydown', function (e) {
    /* F12 (developer tools), with or without modifiers. */
    if (e.key === 'F12' || e.keyCode === 123) {
      e.preventDefault();
      say();
      return;
    }

    if (!e.ctrlKey && !e.metaKey) {
      return;
    }

    var k     = letterOf(e);
    var block = false;

    if (e.shiftKey || e.altKey) {
      /* Developer tools (I: tools, J: console, C: inspect element) and
         Safari's View Source; anything else with Shift or Alt
         (Ctrl+Shift+V, AltGr letters) is left alone. */
      block = ((k === 'i' || k === 'j' || k === 'c') && e.ctrlKey && e.shiftKey && !e.altKey)
           || ((k === 'i' || k === 'j' || k === 'c' || k === 'u') && e.metaKey && e.altKey);
    } else if (k === 'a' || k === 'c' || k === 'x' || k === 'v') {
      block = !allowed(e);
    } else if (k === 's' || k === 'u') {
      block = true;
    }

    if (block) {
      e.preventDefault();
      say();
    }
  }, true);
})();
