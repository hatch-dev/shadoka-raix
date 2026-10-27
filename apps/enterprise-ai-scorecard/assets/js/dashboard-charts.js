/**
 * Every chart on this app's analytics dashboard — all four panes.
 *
 * This app's own, not the platform's. platform/js/dashboard-charts.js belongs to
 * the Director Scorecard: its axes are fixed at 1–4, its radar maxes at 4, and it
 * reads `competencies` and `mean_floor`. All of that is wrong for a five-point
 * instrument — a 4.60 bar would run off the end of a 1–4 axis — and changing it
 * to suit this one would silently change that one's dashboard at the same time.
 *
 * Figures come from a JSON block the page printed, not from a fetch, so a chart
 * cannot disagree with the numbers rendered beside it. If Chart.js did not load,
 * each canvas is replaced with a quiet note rather than left as an empty box —
 * every panel already prints its own figures in words.
 *
 * One script for the whole dashboard, keyed off which figures the payload
 * carries: the page ships only the pane it is showing, so each block below is
 * skipped on the three panes that have nothing for it, and `draw()` no-ops on a
 * canvas that is not on the page.
 *
 * Not a module: the admin console loads plain scripts, and this needs no imports.
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
  var BLUE_LINE = '#5A9FDA';

  /* The scale this instrument is marked on. Named once so no chart can drift. */
  var SCALE_MIN = 1;
  var SCALE_MAX = 5;

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
   * Object.assign(axis, { ticks: {...} }) replaces the whole ticks object, so the
   * mono face quietly disappears from that axis while every other chart keeps it.
   */
  function axis(from, extra) {
    var merged = Object.assign({}, from, extra);
    merged.ticks = Object.assign({}, from.ticks, extra.ticks || {});
    merged.grid = Object.assign({}, from.grid, extra.grid || {});
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

  /** A long category label would squeeze the plot area to nothing. */
  function elide(value, limit) {
    var name = String(value || '');
    return name.length > limit ? name.slice(0, limit - 2) + '…' : name;
  }

  var levels = data.levels || [];
  var dimensions = data.dimensions || [];
  var sectors = data.sectors || [];

  /* ==== Overview ========================================================= */

  /* ---- how many organisations sit at each level ------------------------- */

  draw('cLevels', {
    type: 'bar',
    data: {
      labels: levels.map(function (l) { return l.name; }),
      datasets: [{
        label: 'Organizations',
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

  /* ---- the shape of the six dimensions ---------------------------------- */

  draw('cRadarAn', {
    type: 'radar',
    data: {
      labels: dimensions.map(function (d) { return d.code; }),
      datasets: [{
        label: 'Mean',
        data: dimensions.map(function (d) { return d.mean; }),
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
          // 0 to 5, matching the results page's radar. The reference drew this
          // one 0–4 and that one 0–5, so the same score sat at a different radius
          // on two screens of one instrument.
          min: 0,
          max: SCALE_MAX,
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

  /* ---- mean overall score by sector ------------------------------------ */

  draw('cSector', {
    type: 'bar',
    data: {
      labels: sectors.map(function (s) { return elide(s.sector, 22); }),
      datasets: [{
        label: 'Mean overall score',
        data: sectors.map(function (s) { return s.mean; }),
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
        x: { min: SCALE_MIN, max: SCALE_MAX, ticks: { color: INK }, grid: { color: GRID } },
        y: { ticks: { color: LABEL, font: { size: 11 } }, grid: { display: false } },
      },
    }),
  });

  /* ---- which dimension is most often the weakest ----------------------- */

  draw('cFloor', {
    type: 'bar',
    data: {
      labels: dimensions.map(function (d) { return d.code; }),
      datasets: [{
        label: 'Weakest here',
        data: dimensions.map(function (d) { return d.floor_count || 0; }),
        backgroundColor: BLUE,
        borderWidth: 0,
      }],
    },
    options: Object.assign(base(), {
      plugins: {
        legend: { display: false },
        // The code is on the axis; the tooltip gives the full name, as in the
        // reference — six three-letter labels are not self-explanatory.
        tooltip: {
          callbacks: {
            title: function (items) {
              return (dimensions[items[0].dataIndex] || {}).name || '';
            },
          },
        },
      },
      scales: Object.assign(base().scales, {
        y: axis(base().scales.y, { beginAtZero: true, ticks: { precision: 0 } }),
      }),
    }),
  });

  /* ==== Year-over-year =================================================== */

  var years = data.years || [];

  /*
   * The reference's pair, in its order and styling: the floor solid gold with
   * its fill, the average dashed blue. Drawn whenever there is a year to draw,
   * as the reference never withheld it.
   */
  if (years.length) {
    draw('cYoY', {
      type: 'line',
      data: {
        labels: years.map(function (y) { return String(y.year); }),
        datasets: [
          {
            label: 'Mean floor (weakest dimension)',
            data: years.map(function (y) { return y.mean_floor; }),
            borderColor: '#E8933F',
            backgroundColor: 'rgba(232,147,63,.1)',
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
        scales: {
          // Fixed at 1–5, the scale the instrument is marked on, as the reference.
          y: { min: SCALE_MIN, max: SCALE_MAX, ticks: { color: INK }, grid: { color: GRID } },
          x: { ticks: { color: INK }, grid: { color: GRID } },
        },
      }),
    });
  }

  /* ==== Dimensions ======================================================= */

  var itemData = data.items || null;
  var dimSectors = data.dimSectors || [];

  if (itemData) {
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

  draw('cDimSec', {
    type: 'bar',
    data: {
      labels: dimSectors.map(function (s) { return elide(s.sector, 20); }),
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
        x: { min: SCALE_MIN, max: SCALE_MAX, ticks: { color: INK }, grid: { color: GRID } },
        y: { ticks: { color: LABEL, font: { size: 11 } }, grid: { display: false } },
      },
    }),
  });

  /* ==== Correlation ====================================================== */

  (data.correlations || []).forEach(function (correlation) {
    var points = correlation.points || [];

    var datasets = [{
      label: 'Respondents',
      data: points,
      backgroundColor: 'rgba(90,159,218,.75)',
      pointRadius: 5,
    }];

    /*
     * The fit line is drawn only where the coefficient is reportable. A trend
     * line through eleven points beside a card that has just refused to report r
     * would say the opposite of what the card says.
     */
    if (correlation.reportable && points.length >= 2) {
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

      if (sxx !== 0) {
        var slope = sxy / sxx;
        var intercept = my - slope * mx;
        var x0 = Math.min.apply(null, xs);
        var x1 = Math.max.apply(null, xs);

        datasets.push({
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
      data: { datasets: datasets },
      options: Object.assign(base(), {
        // The reference keeps the legend on: "Respondents", and "Fit" when drawn.
        plugins: { legend: base().plugins.legend },
        scales: {
          x: {
            min: SCALE_MIN,
            max: SCALE_MAX,
            title: {
              display: true,
              text: 'Overall score',
              color: INK,
              font: { family: 'IBM Plex Mono', size: 10 },
            },
            ticks: { color: INK },
            grid: { color: GRID },
          },
          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: correlation.label,
              color: INK,
              font: { family: 'IBM Plex Mono', size: 10 },
            },
            ticks: { color: INK },
            grid: { color: GRID },
          },
        },
      }),
    });
  });
})();
