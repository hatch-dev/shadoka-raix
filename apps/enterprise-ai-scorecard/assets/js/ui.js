/**
 * Shared chrome: modal, toast, scroll reveal, the custom cursor.
 *
 * Ported from the reference single file, with two changes that matter:
 *
 *  - `esc()` there escaped &, < and > but NOT quotes, so an organisation name
 *    containing a double quote broke out of its attribute. Everything here
 *    escapes quotes too.
 *  - The modal traps focus and closes on Escape. The reference's closed only on a
 *    click outside it, which a keyboard user cannot reach.
 */

/**
 * Small numbers as words, as this instrument's prose is written.
 *
 * Derived rather than hard-coded so a sentence stays true if an instrument
 * version changes its counts — but spelled out, because "all 6 dimensions" and
 * "once 30 or more organizations have scored" read like a spec sheet next to the
 * sentences around them. Anything outside the table falls back to digits, which
 * is the right way to fail.
 */
export function numberWord(n, capitalise = false) {
  const words = {
    1: 'one', 2: 'two', 3: 'three', 4: 'four', 5: 'five', 6: 'six', 7: 'seven',
    8: 'eight', 9: 'nine', 10: 'ten', 11: 'eleven', 12: 'twelve',
    15: 'fifteen', 20: 'twenty', 25: 'twenty-five', 30: 'thirty',
    35: 'thirty-five', 40: 'forty', 50: 'fifty',
  };

  const word = words[n];

  if (!word) return String(n);

  return capitalise ? word.charAt(0).toUpperCase() + word.slice(1) : word;
}

/** Escape for HTML. Unlike the reference's esc(), this covers both quote characters. */
export function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** A score as the instrument prints it: two decimals, or an em dash. */
export function fmt(value) {
  return value === null || value === undefined || Number.isNaN(value)
    ? '—'
    : Number(value).toFixed(2);
}

/* ---------------------------------------------------------------- modal -- */

let lastFocused = null;

export function modal(html) {
  const root = document.getElementById('modalRoot');
  if (!root) return;

  lastFocused = document.activeElement;

  root.innerHTML =
    '<div class="modal-bg"><div class="modal" role="dialog" aria-modal="true" tabindex="-1">' +
    html +
    '</div></div>';

  const backdrop = root.querySelector('.modal-bg');
  const panel = root.querySelector('.modal');

  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) closeModal();
  });

  root.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', onModalKeydown);
  panel.focus();

  return panel;
}

function onModalKeydown(event) {
  if (event.key === 'Escape') {
    closeModal();
    return;
  }

  // Keep Tab inside the dialog while it is open.
  if (event.key !== 'Tab') return;

  const panel = document.querySelector('#modalRoot .modal');
  if (!panel) return;

  const focusable = panel.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  );
  if (!focusable.length) return;

  const first = focusable[0];
  const last = focusable[focusable.length - 1];

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

export function closeModal() {
  const root = document.getElementById('modalRoot');
  if (root) root.innerHTML = '';

  document.removeEventListener('keydown', onModalKeydown);

  if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  lastFocused = null;
}

/* ---------------------------------------------------------------- toast -- */

let toastTimer = null;

export function toast(message) {
  const element = document.getElementById('toast');
  if (!element) return;

  element.textContent = message;
  element.classList.add('show');

  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => element.classList.remove('show'), 2600);
}

/* --------------------------------------------------------------- reveal -- */

let observer = null;

export function initReveal() {
  if (window.matchMedia('(prefers-reduced-motion:reduce)').matches) return;

  if (!observer) {
    observer = new IntersectionObserver(
      (entries) =>
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('vis');
          observer.unobserve(entry.target);
        }),
      { threshold: 0.1, rootMargin: '0px 0px -40px 0px' },
    );
  }

  document.querySelectorAll('.reveal:not(.vis)').forEach((element) => observer.observe(element));
}

/* --------------------------------------------------------------- chrome -- */

export function initChrome() {
  const reduced = window.matchMedia('(prefers-reduced-motion:reduce)').matches;
  const finePointer = window.matchMedia('(hover:hover) and (pointer:fine)').matches;

  if (finePointer && !reduced) initCursor();

  const nav = document.querySelector('nav');
  if (nav) {
    const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 24);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  initReveal();
}

function initCursor() {
  const dot = document.getElementById('cur');
  const ring = document.getElementById('curRing');
  if (!dot || !ring) return;

  document.body.classList.add('cursorfx');

  let ringX = 0;
  let ringY = 0;
  let targetX = 0;
  let targetY = 0;

  document.addEventListener('mousemove', (event) => {
    targetX = event.clientX;
    targetY = event.clientY;
    dot.style.left = targetX + 'px';
    dot.style.top = targetY + 'px';
  });

  (function follow() {
    ringX += (targetX - ringX) * 0.16;
    ringY += (targetY - ringY) * 0.16;
    ring.style.left = ringX + 'px';
    ring.style.top = ringY + 'px';
    requestAnimationFrame(follow);
  })();

  document.addEventListener('mouseover', (event) => {
    const hot = event.target.closest('button,a,select,input,label,.rec,tr.clickable');
    dot.classList.toggle('hot', Boolean(hot));
    ring.classList.toggle('hot', Boolean(hot));
  });
}
