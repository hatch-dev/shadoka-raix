/**
 * Every chart on the analytics dashboard — all four panes.
 *
 * Figures come from a JSON block the page printed, not from a fetch, so a chart
 * cannot disagree with the numbers rendered beside it. If Chart.js did not
 * load, each canvas is replaced with a quiet note rather than left as an empty
 * box — every panel already prints its own figures in words.
 *
 * One script for the whole dashboard, keyed off which figures the payload
 * carries: the page ships only the pane it is showing, so each block below is
 * skipped on the three panes that have nothing for it, and `draw()` no-ops on
 * a canvas that is not on the page.
 *
 * Not a module: the admin console loads plain scripts, and this needs no
 * imports.
 */
(function () {
  'use strict';

  var element = document.getElementById('chartData');
  if (!element) return;

  var data;
  try {
    data = JSON.parse(element.textContent);
  } catch (e) {
    data = null;
  }
  if (!data) return;

  var INK = '#6F84A0';
  var LABEL = '#A0B4CA';
  var GRID = 'rgba(61,130,196,.10)';
  var GOLD = 'rgba(232,147,63,1)';
  var GOLD_FILL = 'rgba(232,147,63,.12)';
  var BLUE = '#3D82C4';
  // A lighter blue for lines and points, which read thinner than a filled bar
  // and disappear into the panel at the bar colour.
  var BLUE_LINE = '#5A9FDA';

  if (!window.Chart) {
    [].forEach.call(document.querySelectorAll('canvas'), function (canvas) {
      var note = document.createElement('div');
      note.className = 'muted small';
      note.style.cssText =
        'display:flex;align-items:center;justify-content:center;height:100%;min-height:120px;' +
        'border:1px dashed var(--rule);border-radius:3px;text-align:center;padding:14px';
      note.textContent =
        'Chart could not be drawn. The figures beside this panel carry the same information.';
      canvas.replaceWith(note);
    });
    return;
  }

  /** The axis and legend styling every chart here shares. */
  function base() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: LABEL, font: { family: 'Public Sans', size: 12 }, boxWidth: 12 } },
      },
      scales: {
        x: {
          ticks: { color: INK, font: { family: 'IBM Plex Mono', size: 10 } },
          grid: { color: GRID },
        },
        y: {
          ticks: { color: INK, font: { family: 'IBM Plex Mono', size: 10 } },
          grid: { color: GRID },
        },
      },
    };
  }

  /**
   * Merge tick options WITHOUT losing the ones base() set.
   *
   * `Object.assign(axis, { ticks: {...} })` replaces the whole ticks object, so
   * the mono face quietly disappears from that axis while every other chart
   * keeps it. That is how the level and floor charts ended up in a different
   * font from the rest of the dashboard.
   */
  function axis(from, extra) {
    var merged = Object.assign({}, from, extra);
    merged.ticks = Object.assign({}, from.ticks, extra.ticks);
    if (extra.grid) merged.grid = Object.assign({}, from.grid, extra.grid);
    return merged;
  }

  function draw(id, config) {
    var canvas = document.getElementById(id);
    if (!canvas) return;
    try {
      new window.Chart(canvas, config);
    } catch (e) {
      /* One broken chart must not take the other three down with it. */
      if (window.console) window.console.error(id, e);
    }
  }

  var levels = data.levels || [];
  var competencies = data.competencies || [];
  var sectors = data.sectors || [];

  /* ---- how many directors sit at each level ---------------------------- */

  draw('cLevels', {
    type: 'bar',
    data: {
      labels: levels.map(function (l) { return l.name; }),
      datasets: [{
        label: 'Directors',
        data: levels.map(function (l) { return l.n; }),
        backgroundColor: levels.map(function (l) { return l.colour; }),
        borderWidth: 0,
      }],
    },
    options: Object.assign(base(), {
      plugins: { legend: { display: false } },
      scales: Object.assign(base().scales, {
        y: axis(base().scales.y, { beginAtZero: true, ticks: { precision: 0 } }),
      }),
    }),
  });

  /* ---- the shape of the seven competencies ----------------------------- */

  draw('cRadarAn', {
    type: 'radar',
    data: {
      labels: competencies.map(function (c) { return c.code; }),
      datasets: [{
        label: 'Mean',
        data: competencies.map(function (c) { return c.mean; }),
        borderColor: GOLD,
        backgroundColor: GOLD_FILL,
        pointBackgroundColor: GOLD,
        borderWidth: 1.5,
        pointRadius: 3,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        r: {
          min: 0,
          max: 4,
          ticks: {
            stepSize: 1,
            color: INK,
            backdropColor: 'transparent',
            font: { family: 'IBM Plex Mono', size: 10 },
          },
          grid: { color: 'rgba(61,130,196,.18)' },
          angleLines: { color: 'rgba(61,130,196,.18)' },
          pointLabels: { color: LABEL, font: { family: 'IBM Plex Mono', size: 11 } },
        },
      },
    },
  });

  /* ---- mean floor by sector -------------------------------------------- */

  /*
   * A long sector name would otherwise push the plot area down to nothing, so
   * it is elided at 22 characters exactly as the reference does.
   *
   * This chart also replaces the shared scales outright rather than extending
   * them — the reference does the same, which is why its category axis is in
   * the sans face at 11px while every other axis on the dashboard is mono 10.
   */
  draw('cSector', {
    type: 'bar',
    data: {
      labels: sectors.map(function (s) {
        var name = String(s.sector || '');
        return name.length > 22 ? name.slice(0, 20) + '…' : name;
      }),
      datasets: [{
        label: 'Mean floor',
        data: sectors.map(function (s) { return s.mean_floor; }),
        backgroundColor: sectors.map(function (s) { return s.colour || GOLD; }),
        borderWidth: 0,
      }],
    },
    options: Object.assign(base(), {
      indexAxis: 'y',
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { afterLabel: function (i) { return 'n = ' + (sectors[i.dataIndex] || {}).n; } } },
      },
      scales: {
        x: { min: 1, max: 4, ticks: { color: INK }, grid: { color: GRID } },
        y: { ticks: { color: LABEL, font: { size: 11 } }, grid: { display: false } },
      },
    }),
  });

  /* ---- which competency caps the level --------------------------------- */

  draw('cFloor', {
    type: 'bar',
    data: {
      labels: competencies.map(function (c) { return c.code; }),
      datasets: [{
        label: 'Directors capped here',
        data: competencies.map(function (c) { return c.floor_count || 0; }),
        backgroundColor: BLUE,
        borderWidth: 0,
      }],
    },
    options: Object.assign(base(), {
      plugins: {
        legend: { display: false },
        // The code is on the axis; the tooltip gives the full name, as in the
        // reference — seven three-letter labels are not self-explanatory.
        tooltip: {
          callbacks: {
            title: function (items) {
              return (competencies[items[0].dataIndex] || {}).name || '';
            },
          },
        },
      },
      scales: Object.assign(base().scales, {
        y: axis(base().scales.y, { beginAtZero: true, ticks: { precision: 0 } }),
      }),
    }),
  });

  /* ==== Year-over-year ================================================== */

  var years = data.years || [];

  /*
   * Floor and average over time. Fixed at 1–4, the scale the instrument is
   * marked on: auto-scaling a pair of lines that move by 0.1 over three years
   * turns a flat dataset into a dramatic climb.
   */
  if (years.length) {
    draw('cYoY', {
      type: 'line',
      data: {
        labels: years.map(function (y) { return y.year; }),
        datasets: [
          {
            label: 'Mean floor',
            data: years.map(function (y) { return y.mean_floor; }),
            borderColor: GOLD,
            backgroundColor: 'rgba(232,147,63,.10)',
            tension: 0.3,
            pointRadius: 4,
            fill: true,
          },
          {
            label: 'Mean average',
            data: years.map(function (y) { return y.mean_average; }),
            borderColor: BLUE_LINE,
            backgroundColor: 'rgba(90,159,218,.06)',
            tension: 0.3,
            pointRadius: 4,
            borderDash: [5, 4],
            fill: true,
          },
        ],
      },
      options: Object.assign(base(), {
        plugins: {
          legend: base().plugins.legend,
          tooltip: {
            callbacks: {
              afterLabel: function (i) {
                var row = years[i.dataIndex] || {};
                return 'n = ' + row.n;
              },
            },
          },
        },
        scales: Object.assign(base().scales, {
          y: axis(base().scales.y, { min: 1, max: 4 }),
        }),
      }),
    });
  }

  /* ==== Competencies ==================================================== */

  var itemData = data.items || null;
  var dimSectors = data.dimSectors || [];

  /*
   * How the five statements of one competency land across the ladder: one
   * stacked bar per statement, one dataset per rung, in the rungs' own colours
   * so the ladder reads the same here as everywhere else.
   */
  if (itemData && itemData.rows && itemData.rows.length) {
    draw('cDist', {
      type: 'bar',
      data: {
        labels: itemData.rows.map(function (r) { return r.label; }),
        datasets: (itemData.rungs || []).map(function (rung, index) {
          return {
            label: rung.name,
            data: itemData.rows.map(function (r) { return (r.distribution || [])[index] || 0; }),
            backgroundColor: rung.colour,
            borderWidth: 0,
          };
        }),
      },
      options: Object.assign(base(), {
        scales: {
          x: axis(base().scales.x, { stacked: true, grid: { display: false } }),
          y: axis(base().scales.y, { stacked: true, beginAtZero: true, ticks: { precision: 0 } }),
        },
      }),
    });
  }

  if (dimSectors.length) {
    draw('cDimSec', {
      type: 'bar',
      data: {
        labels: dimSectors.map(function (s) {
          var name = String(s.sector || '');
          return name.length > 22 ? name.slice(0, 20) + '…' : name;
        }),
        datasets: [{
          label: 'Mean',
          data: dimSectors.map(function (s) { return s.mean; }),
          backgroundColor: dimSectors.map(function (s) { return s.colour || GOLD; }),
          borderWidth: 0,
        }],
      },
      options: Object.assign(base(), {
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { afterLabel: function (i) { return 'n = ' + (dimSectors[i.dataIndex] || {}).n; } } },
        },
        scales: {
          x: { min: 1, max: 4, ticks: { color: INK }, grid: { color: GRID } },
          y: { ticks: { color: LABEL, font: { size: 11 } }, grid: { display: false } },
        },
      }),
    });
  }

  /* ==== Correlation ===================================================== */

  /*
   * One scatter per figure, with a least-squares line drawn only where the
   * coefficient is reportable. A fit line through eleven points is a claim the
   * page has just finished refusing to make in words.
   */
  (data.correlations || []).forEach(function (correlation) {
    // Drawn even with nothing to plot: the pane promises the scatter whatever
    // the figures are, and an empty plot area is what "how thin the data is"
    // looks like at n = 0.
    var points = correlation.points || [];

    var sets = [{
      label: 'Directors',
      data: points,
      backgroundColor: 'rgba(90,159,218,.75)',
      pointRadius: 5,
    }];

    if (correlation.reportable && correlation.stat) {
      var xs = points.map(function (p) { return p.x; });
      var ys = points.map(function (p) { return p.y; });
      var mx = xs.reduce(function (a, b) { return a + b; }, 0) / xs.length;
      var my = ys.reduce(function (a, b) { return a + b; }, 0) / ys.length;
      var sxy = 0;
      var sxx = 0;

      xs.forEach(function (x, i) {
        sxy += (x - mx) * (ys[i] - my);
        sxx += (x - mx) * (x - mx);
      });

      if (sxx > 0) {
        var slope = sxy / sxx;
        var intercept = my - slope * mx;
        var x0 = Math.min.apply(null, xs);
        var x1 = Math.max.apply(null, xs);

        sets.push({
          label: 'Fit',
          type: 'line',
          data: [{ x: x0, y: intercept + slope * x0 }, { x: x1, y: intercept + slope * x1 }],
          borderColor: GOLD,
          borderWidth: 1.5,
          pointRadius: 0,
          borderDash: [6, 4],
        });
      }
    }

    draw('cCorr-' + correlation.metric, {
      type: 'scatter',
      data: { datasets: sets },
      options: Object.assign(base(), {
        scales: {
          x: {
            min: 1,
            max: 4,
            title: { display: true, text: 'Floor score', color: INK, font: { family: 'IBM Plex Mono', size: 10 } },
            ticks: { color: INK }, grid: { color: GRID },
          },
          y: {
            // Every figure on this axis is a count — meetings, hours, seats —
            // so the axis starts at zero. `beginAtZero` only guarantees that
            // zero is INCLUDED, and Chart.js then pads below it: the axis came
            // out running from −2 meetings.
            min: 0,
            title: { display: true, text: correlation.label, color: INK, font: { family: 'IBM Plex Mono', size: 10 } },
            ticks: { color: INK }, grid: { color: GRID },
          },
        },
      }),
    });
  });
}());
