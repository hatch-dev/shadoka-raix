/**
 * The Analytics charts, drawn from specs the server writes onto each canvas.
 *
 * The reference's chartBase(), plot(), series() and drawRadar(), options and
 * all — including its font names, which are not loaded on the page, so the
 * charts fall back exactly as the reference's did. The numbers come from the
 * server (Analytics, over the same records as the tables beside them).
 *
 * If Chart.js did not load, each canvas is replaced by the reference's
 * chartFallback() sentence.
 */
(function () {
  'use strict';

  var SCOPE_COLOR = { self: '#E8933F', leadership: '#5A9FDA' };
  var canvases = document.querySelectorAll('canvas[data-chart]');

  if (!canvases.length) return;

  if (!window.Chart) {
    canvases.forEach(function (canvas) {
      var note = document.createElement('p');
      note.className = 'muted small';
      note.textContent = 'The chart is unavailable. The tables show the same scores.';
      canvas.replaceWith(note);
    });
    return;
  }

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

  function series(scope, data) {
    return {
      label: scope === 'self' ? 'Self-ratings' : 'Ratings of leaders',
      data: data,
      borderColor: SCOPE_COLOR[scope],
      backgroundColor: SCOPE_COLOR[scope],
      borderWidth: 2,
      tension: 0.15,
    };
  }

  function plot(canvas, spec) {
    var options = chartBase();
    options.scales.y.beginAtZero = true;
    if (spec.scoreScale) options.scales.y = Object.assign({}, options.scales.y, { min: 1, max: 5 });

    new window.Chart(canvas, {
      type: spec.type,
      data: { labels: spec.labels, datasets: spec.series.map(function (s) { return series(s.scope, s.data); }) },
      options: options,
    });
  }

  function radar(canvas, spec) {
    var colors = ['rgba(232,147,63,1)', 'rgba(90,159,218,1)', 'rgba(160,180,202,1)'];

    new window.Chart(canvas, {
      type: 'radar',
      data: {
        labels: spec.labels,
        datasets: spec.sets.map(function (set, i) {
          return {
            label: set.label,
            data: set.data,
            borderColor: colors[i % 3],
            backgroundColor: colors[i % 3].replace(',1)', ',.12)'),
            pointBackgroundColor: colors[i % 3],
            borderWidth: 1.5,
            pointRadius: 3,
          };
        }),
      },
      options: {
        responsive: true,
        scales: {
          r: {
            min: 0,
            max: 5,
            ticks: { stepSize: 1, color: '#6F84A0', backdropColor: 'transparent', font: { family: 'Spline Sans Mono', size: 10 } },
            grid: { color: 'rgba(61,130,196,.18)' },
            angleLines: { color: 'rgba(61,130,196,.18)' },
            pointLabels: { color: '#A0B4CA', font: { family: 'Spline Sans Mono', size: 11 } },
          },
        },
        plugins: {
          legend: { display: spec.sets.length > 1, labels: { color: '#A0B4CA', font: { family: 'Instrument Sans', size: 12 } } },
        },
      },
    });
  }

  function scatter(canvas, spec) {
    var options = chartBase();
    options.scales.x = Object.assign({}, options.scales.x, {
      min: 1,
      max: 5,
      title: { display: true, text: spec.xTitle, color: '#A0B4CA' },
    });

    new window.Chart(canvas, {
      type: 'scatter',
      data: { datasets: [{ label: spec.label, data: spec.points, backgroundColor: SCOPE_COLOR[spec.scope] }] },
      options: options,
    });
  }

  canvases.forEach(function (canvas) {
    var spec;
    try {
      spec = JSON.parse(canvas.getAttribute('data-chart'));
    } catch (error) {
      return;
    }

    if (spec.kind === 'radar') radar(canvas, spec);
    else if (spec.kind === 'scatter') scatter(canvas, spec);
    else plot(canvas, spec);
  });
})();
