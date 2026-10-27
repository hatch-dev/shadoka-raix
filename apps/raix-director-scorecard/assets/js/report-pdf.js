/**
 * The research report as a PDF, generated with jsPDF.
 *
 * A direct port of the single-file build's `exportFullPDF()`: same A4 page,
 * same millimetre positions, same colours, same wording, same filename. It
 * replaces the browser-print route that stood in for it — the console's PDF
 * button should hand over the document the reference produced, not a printout
 * of a web page.
 *
 * Every figure comes from /report/data, which the server computes with the
 * same Analytics the dashboard reads, so this PDF cannot state a number the
 * admin screens would not.
 *
 * Written as a plain script, not a module: the admin console loads plain
 * scripts, and the button that triggers this lives inside a dialog whose
 * markup is injected — which is also why the click is delegated.
 *
 * jsPDF is ~350KB and is fetched only when the button is pressed. Nobody
 * opening the dataset pays for a library they may never use.
 */
(function () {
  'use strict';

  /* The palette, as the reference passed it to jsPDF. */
  var INK = [6, 13, 24];
  var PAPER = [232, 240, 250];
  var STONE = [160, 180, 202];
  var TEXT = [40, 40, 40];
  var BODY = [60, 60, 60];
  var CAPTION = [90, 90, 90];
  var TRACK = [235, 238, 243];
  var GOLD_TEXT = [217, 164, 65];
  var RULE_LABEL = [120, 120, 120];
  var FOOTNOTE = [140, 140, 140];

  var loading = null;

  function toast(message) {
    var element = document.getElementById('toast');
    if (!element) return;

    element.textContent = message;
    element.classList.add('show');
    setTimeout(function () { element.classList.remove('show'); }, 2600);
  }

  /** Two decimals, or an em dash — the reference's fmt(). */
  function fmt(value) {
    return value === null || value === undefined ? '—' : Number(value).toFixed(2);
  }

  function rgb(hex) {
    var clean = String(hex || '').replace('#', '');

    return [
      parseInt(clean.slice(0, 2), 16) || 0,
      parseInt(clean.slice(2, 4), 16) || 0,
      parseInt(clean.slice(4, 6), 16) || 0,
    ];
  }

  /** Load jsPDF once, on first use. */
  function library(source) {
    if (window.jspdf && window.jspdf.jsPDF) return Promise.resolve();
    if (loading) return loading;

    loading = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = source;
      script.onload = resolve;
      script.onerror = function () { reject(new Error('The PDF library did not load.')); };
      document.head.appendChild(script);
    });

    return loading;
  }

  /** The dark band across the top. Returns the y to carry on from. */
  function header(doc, title, subtitle) {
    var width = doc.internal.pageSize.getWidth();

    doc.setFillColor.apply(doc, INK);
    doc.rect(0, 0, width, 26, 'F');

    doc.setTextColor.apply(doc, PAPER);
    doc.setFont('times', 'normal');
    doc.setFontSize(18);
    doc.text('RAI—X', 14, 12);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor.apply(doc, STONE);
    doc.text(title, 14, 19);

    doc.setFontSize(8);
    doc.text(subtitle, width - 14, 19, { align: 'right' });

    return 36;
  }

  /**
   * One bar per competency: name, track, fill, the min–max ticks, score, rung.
   *
   * The competency that sets the floor is gold and bold — on paper that is the
   * only cue left, with no Floor tag and no colour-coded row.
   */
  function bars(doc, y, competencies, floorCode) {
    var width = doc.internal.pageSize.getWidth();
    var x0 = 14;
    var barX = x0 + 62;
    var barWidth = width - barX - 14 - 22;

    doc.setFontSize(9);

    competencies.forEach(function (competency) {
      var isFloor = competency.code === floorCode;
      var mean = competency.mean;

      doc.setTextColor.apply(doc, isFloor ? GOLD_TEXT : TEXT);
      doc.setFont('helvetica', isFloor ? 'bold' : 'normal');
      doc.text(competency.name, x0, y + 3.5);

      doc.setFillColor.apply(doc, TRACK);
      doc.rect(barX, y, barWidth, 4.5, 'F');

      if (mean !== null && mean !== undefined) {
        doc.setFillColor.apply(doc, rgb(competency.colour));
        doc.rect(barX, y, (barWidth * (mean - 1)) / 3, 4.5, 'F');

        // The spread across the set, drawn as two ticks. Only when more than
        // one director scored it — one record has no spread, just a value.
        if (competency.n > 1 && competency.min !== null && competency.max !== null) {
          doc.setDrawColor(60, 60, 60);
          doc.setLineWidth(0.4);
          [competency.min, competency.max].forEach(function (value) {
            var x = barX + (barWidth * (value - 1)) / 3;
            doc.line(x, y - 1, x, y + 5.5);
          });
        }
      }

      doc.setTextColor.apply(doc, TEXT);
      doc.setFont('helvetica', 'normal');
      doc.text(mean === null || mean === undefined ? '—' : Number(mean).toFixed(2), width - 14, y + 3.5, {
        align: 'right',
      });

      doc.setFontSize(7);
      doc.setTextColor.apply(doc, RULE_LABEL);
      doc.text(competency.rung || 'UNSCORED', width - 14 - 10, y + 3.5, { align: 'right' });
      doc.setFontSize(9);

      y += 9;
    });

    return y;
  }

  /**
   * One board, as the reference's `exportOrgPDF()` drew it: the same header,
   * the same single summary line, the same bars, the same footnote and the
   * same filename. Shorter than the research report on purpose — it is a
   * profile of one board, not a dataset.
   */
  function buildBoard(data) {
    var JsPDF = window.jspdf && window.jspdf.jsPDF;
    if (!JsPDF) throw new Error('The PDF library did not load.');

    var doc = new JsPDF({ unit: 'mm', format: 'a4' });
    var width = doc.internal.pageSize.getWidth();
    var height = doc.internal.pageSize.getHeight();

    var y = header(
      doc,
      'Board profile',
      data.directors + ' director scorecard' + (data.directors === 1 ? '' : 's'),
    );

    doc.setTextColor.apply(doc, INK);
    doc.setFont('times', 'normal');
    doc.setFontSize(22);
    doc.text(String(data.name || 'Anonymous'), 14, y);
    y += 7;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, BODY);
    doc.text(
      'Level ' + (data.level ? data.level.value + ' · ' + data.level.name : 'unscored') +
      '   ·   Floor ' + fmt(data.floor) +
      '   ·   Average ' + fmt(data.mean),
      14,
      y,
    );
    y += 10;

    // Context the reference carried in its modal heading rather than its PDF.
    // On paper there is nothing else to say which board this is.
    var context = [
      data.sector,
      data.size ? data.size + ' employees' : null,
      // Which years these figures cover. The dialog this was printed from
      // shows the latest year alone, so the paper says what it is.
      data.years ? 'every scorecard, ' + data.years : null,
    ].filter(Boolean).join(' · ');

    if (context || data.synthetic) {
      doc.setFontSize(9);
      doc.setTextColor.apply(doc, CAPTION);
      doc.text(context + (data.synthetic ? (context ? '   ·   ' : '') + 'Synthetic demo record' : ''), 14, y);
      y += 8;
    }

    y = bars(doc, y, data.competencies, data.floor_code);

    doc.setFontSize(8);
    doc.setTextColor.apply(doc, FOOTNOTE);
    doc.text(
      'Self-assessment. Each director’s level is set by their weakest competency. ' +
      '· © 2026 SHADOKA, LLC. All rights reserved.',
      14,
      height - 12,
    );

    doc.save('RAI-X_' + String(data.name || 'board').replace(/[^\w]+/g, '_') + '.pdf');
  }

  function build(data) {
    var JsPDF = window.jspdf && window.jspdf.jsPDF;
    if (!JsPDF) throw new Error('The PDF library did not load.');

    var doc = new JsPDF({ unit: 'mm', format: 'a4' });
    var width = doc.internal.pageSize.getWidth();
    var height = doc.internal.pageSize.getHeight();

    var y = header(doc, 'Research report', data.generated_on);

    doc.setTextColor.apply(doc, INK);
    doc.setFont('times', 'normal');
    doc.setFontSize(24);
    doc.text('RAI-X dataset', 14, y);
    y += 8;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, BODY);
    doc.text(
      data.n + ' director scorecards · ' + data.boards + ' boards · mean floor ' +
      fmt(data.floor) + ' · mean average ' + fmt(data.mean),
      14,
      y,
    );
    y += 10;

    doc.setFontSize(9);
    doc.setTextColor.apply(doc, CAPTION);
    doc.text(
      doc.splitTextToSize(
        'Level is set by each director’s weakest competency. Scores are self-reported ' +
        'and unverified, and the sample is self-selected. ' +
        (data.include_synthetic
          ? 'This export includes synthetic demo records.'
          : 'Synthetic demo records are excluded.'),
        width - 28,
      ),
      14,
      y,
    );
    y += 12;

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, INK);
    doc.text('Competency means', 14, y);
    y += 6;

    doc.setFont('helvetica', 'normal');
    y = bars(doc, y, data.competencies, data.floor_code) + 6;

    doc.setFont('helvetica', 'bold');
    doc.text('Level distribution', 14, y);
    y += 6;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);

    data.levels.forEach(function (level) {
      doc.setTextColor.apply(doc, BODY);
      doc.text(level.value + ' · ' + level.name, 16, y);
      doc.text(String(level.n) + '  (' + level.percent + '%)', 60, y);
      doc.setFillColor.apply(doc, rgb(level.colour));
      doc.rect(85, y - 3, Math.max(0.5, ((width - 99) * level.n) / (data.n || 1)), 3.5, 'F');
      y += 6;
    });

    y += 6;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, INK);
    doc.text('Records', 14, y);
    y += 6;

    doc.setFontSize(7.5);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor.apply(doc, RULE_LABEL);

    var columns = [14, 74, 104, 124, 140, 156];
    ['Board', 'Sector', 'Year', 'Level', 'Floor', 'Avg'].forEach(function (heading, index) {
      doc.text(heading, columns[index], y);
    });
    y += 4;

    doc.setDrawColor(200, 200, 200);
    doc.line(14, y - 2.5, width - 14, y - 2.5);
    doc.setFont('helvetica', 'normal');

    data.records
      .slice()
      .sort(function (a, b) { return String(a.board || '').localeCompare(String(b.board || '')); })
      .forEach(function (record) {
        if (y > height - 18) {
          doc.addPage();
          y = header(doc, 'Research report · records', 'continued');
          doc.setFontSize(7.5);
        }

        doc.setTextColor.apply(doc, TEXT);
        doc.text(String(record.board || '').slice(0, 32), columns[0], y);
        doc.text(String(record.sector || '').slice(0, 16), columns[1], y);
        doc.text(String(record.year || ''), columns[2], y);
        doc.text(String(record.level || '—'), columns[3], y);
        doc.text(fmt(record.floor), columns[4], y);
        doc.text(fmt(record.mean), columns[5], y);
        y += 4.4;
      });

    var pages = doc.internal.getNumberOfPages();
    for (var page = 1; page <= pages; page++) {
      doc.setPage(page);
      doc.setFontSize(7.5);
      doc.setTextColor.apply(doc, FOOTNOTE);
      doc.text(
        'RAI-X Director Scorecard · self-assessment · © 2026 SHADOKA, LLC · page ' + page + ' of ' + pages,
        14,
        height - 8,
      );
    }

    doc.save('RAI-X_Research_Report_' + new Date().toISOString().slice(0, 10) + '.pdf');
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    /*
     * Two documents, one handler: the whole dataset from the console, and one
     * board from the dialog the dashboard opens. They share the header, the
     * bars, the library loader and the busy state — a second copy of those is
     * a second PDF that can drift from the screens it came from.
     */
    var link = event.target && event.target.closest
      ? event.target.closest('[data-report-pdf],[data-board-pdf]')
      : null;
    if (!link) return;

    var board = link.hasAttribute('data-board-pdf');
    var source = link.getAttribute(board ? 'data-board-pdf' : 'data-report-pdf');

    event.preventDefault();

    var idle = link.textContent;
    link.textContent = 'Building…';
    link.classList.add('is-busy');

    function done() {
      link.textContent = idle;
      link.classList.remove('is-busy');
    }

    library(link.getAttribute('data-jspdf'))
      .then(function () {
        return fetch(source, { headers: { Accept: 'application/json' } });
      })
      .then(function (response) {
        if (!response.ok) throw new Error('The report figures could not be loaded (' + response.status + ').');
        return response.json();
      })
      .then(function (payload) {
        var data = payload.data || payload;

        if (board ? !data.directors : !data.n) {
          // The reference's own words when there is nothing to draw.
          toast('Nothing to export');
          return;
        }

        if (board) {
          buildBoard(data);
        } else {
          build(data);
        }
      })
      .catch(function (error) {
        toast(error.message);
      })
      .then(done, done);
  });
}());
