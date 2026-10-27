/**
 * The console's charts, drawn from the spec the server writes on each canvas
 * (data-chart): the AI use pane's scatters (the page's paneCorr()) and the
 * organization dialog's radar (openOrg()), with the page's own Chart.js
 * options, fonts and colours.
 *
 * Canvases also arrive inside dialogs, whose markup admin-modal.js injects
 * after load, so new ones are picked up as they appear. If Chart.js did not
 * load, each canvas becomes the page's chartFallback() note.
 *
 * Loaded once for every admin page, from admin/_shell-footer.php.
 */
(function () {
  'use strict';

  function chartBase() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { labels: { color: '#A0B4CA', font: { family: 'Instrument Sans', size: 12 }, boxWidth: 12 } } },
      scales: {
        x: { ticks: { color: '#6F84A0', font: { family: 'Spline Sans Mono', size: 10 } }, grid: { color: 'rgba(61,130,196,.10)' } },
        y: { ticks: { color: '#6F84A0', font: { family: 'Spline Sans Mono', size: 10 } }, grid: { color: 'rgba(61,130,196,.10)' } },
      },
    };
  }

  function fallback(canvas) {
    var d = document.createElement('div');
    d.className = 'muted small';
    d.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100%;min-height:120px;border:1px dashed var(--rule);border-radius:3px;text-align:center;padding:14px';
    d.textContent = 'Chart could not be drawn. The figures beside this panel carry the same information.';
    canvas.replaceWith(d);
  }

  // paneCorr(): respondents, and a dashed fit line where r is reported.
  function scatter(canvas, spec) {
    var ds = [{ label: 'Respondents', data: spec.points, backgroundColor: 'rgba(90,159,218,.75)', pointRadius: 5 }];
    if (spec.fit) {
      ds.push({ label: 'Fit', type: 'line', data: spec.fit, borderColor: '#E8933F', borderWidth: 1.5, pointRadius: 0, borderDash: [6, 4] });
    }
    var axisTitle = function (text) { return { display: true, text: text, color: '#6F84A0', font: { family: 'Spline Sans Mono', size: 10 } }; };
    return new window.Chart(canvas, {
      type: 'scatter',
      data: { datasets: ds },
      options: Object.assign(chartBase(), {
        scales: {
          x: { min: 1, max: 5, title: axisTitle('Overall score'), ticks: { color: '#6F84A0' }, grid: { color: 'rgba(61,130,196,.10)' } },
          y: { title: axisTitle(spec.yTitle), ticks: { color: '#6F84A0' }, grid: { color: 'rgba(61,130,196,.10)' } },
        },
      }),
    });
  }

  // openOrg(): the organization's mean on each skill.
  function radar(canvas, spec) {
    return new window.Chart(canvas, {
      type: 'radar',
      data: {
        labels: spec.labels,
        datasets: [{ label: 'Organization mean', data: spec.data, borderColor: '#E8933F', backgroundColor: 'rgba(232,147,63,.12)', borderWidth: 1.6, pointRadius: 3 }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          r: {
            min: 0, max: 5,
            ticks: { stepSize: 1, color: '#6F84A0', backdropColor: 'transparent', font: { size: 10 } },
            grid: { color: 'rgba(61,130,196,.18)' },
            angleLines: { color: 'rgba(61,130,196,.18)' },
            pointLabels: { color: '#A0B4CA', font: { family: 'Spline Sans Mono', size: 11 } },
          },
        },
        plugins: { legend: { labels: { color: '#A0B4CA', boxWidth: 12 } } },
      },
    });
  }

  function draw(root) {
    (root || document).querySelectorAll('canvas[data-chart]:not([data-drawn])').forEach(function (canvas) {
      canvas.setAttribute('data-drawn', '');
      if (!window.Chart) { fallback(canvas); return; }
      var spec;
      try { spec = JSON.parse(canvas.getAttribute('data-chart')); } catch (e) { return; }
      if (spec.kind === 'radar') radar(canvas, spec);
      else if (spec.kind === 'scatter') scatter(canvas, spec);
    });
  }

  function start() {
    draw(document);
    new MutationObserver(function () { draw(document); }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
