/**
 * The two admin PDFs, generated with jsPDF: the research report, and one
 * organisation's profile.
 *
 * A port of the reference's `exportFullPDF()` and `exportOrgPDF()` — same A4
 * page, same millimetre positions, same colours, same wording, same filename
 * shape. The console's PDF button should hand over the document that build
 * produced, not a printout of a web page.
 *
 * Every figure comes from the server (/report/data and
 * /analytics/organisation/{id}/report-data), computed by the same Analytics the
 * dashboard reads, so these PDFs cannot state a number the admin screens would
 * not. The reference had the figures in memory and re-derived them per document,
 * which is how its report and its dashboard came to define "mean overall score"
 * differently.
 *
 * Written as a plain script, not a module: the admin console loads plain scripts,
 * and the buttons live inside dialogs whose markup is injected — which is also
 * why the click is delegated.
 *
 * jsPDF is ~350KB and is fetched only when a button is pressed. Nobody opening
 * the dataset pays for a library they may never use.
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
    doc.setFontSize(15);
    doc.text('AI Readiness — The Enterprise Scorecard', 14, 12);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor.apply(doc, STONE);
    doc.text(title, 14, 19);

    doc.setFontSize(8);
    doc.text(subtitle, width - 14, 19, { align: 'right' });

    return 36;
  }

  /**
   * One bar per dimension: name, track, fill, the min–max ticks, mean, level.
   *
   * The weakest dimension is gold, bold and labelled "(floor)" — on paper there
   * is no Floor tag and no coloured row, and a bold row with no word beside it
   * would read as "this is the level", which is exactly what it is not on this
   * instrument.
   *
   * The track runs 1 to 5, so a fill is (mean - 1) / 4.
   */
  function bars(doc, y, dimensions, floorCode) {
    var width = doc.internal.pageSize.getWidth();
    var x0 = 14;
    var barX = x0 + 62;
    var barWidth = width - barX - 14 - 22;

    doc.setFontSize(9);

    dimensions.forEach(function (dimension) {
      var isFloor = dimension.code === floorCode;
      var mean = dimension.mean;

      doc.setTextColor.apply(doc, isFloor ? GOLD_TEXT : TEXT);
      doc.setFont('helvetica', isFloor ? 'bold' : 'normal');
      doc.text(dimension.name + (isFloor ? '  (floor)' : ''), x0, y + 3.5);

      doc.setFillColor.apply(doc, TRACK);
      doc.rect(barX, y, barWidth, 4.5, 'F');

      if (mean !== null && mean !== undefined) {
        doc.setFillColor.apply(doc, rgb(dimension.colour));
        doc.rect(barX, y, (barWidth * (mean - 1)) / 4, 4.5, 'F');

        // The spread across the set, as two ticks. Only when more than one record
        // scored it — one record has no spread, just a value.
        if (dimension.n > 1 && dimension.min !== null && dimension.max !== null
            && dimension.max > dimension.min) {
          doc.setDrawColor(60, 60, 60);
          doc.setLineWidth(0.4);
          [dimension.min, dimension.max].forEach(function (value) {
            var x = barX + (barWidth * (value - 1)) / 4;
            doc.line(x, y - 1, x, y + 5.5);
          });
        }
      }

      doc.setTextColor.apply(doc, TEXT);
      doc.setFont('helvetica', 'normal');
      doc.text(
        mean === null || mean === undefined ? '—' : Number(mean).toFixed(2),
        width - 14,
        y + 3.5,
        { align: 'right' },
      );

      doc.setFontSize(7);
      doc.setTextColor.apply(doc, RULE_LABEL);
      doc.text(dimension.rung || 'UNSCORED', width - 14 - 10, y + 3.5, { align: 'right' });
      doc.setFontSize(9);

      y += 9;
    });

    return y;
  }

  /**
   * One organisation, as the reference's `exportOrgPDF()` drew it: the same
   * header, one summary line, the bars, the footnote. Shorter than the research
   * report on purpose — it is a profile of one organisation, not a dataset.
   */
  function buildOrganisation(data) {
    var JsPDF = window.jspdf && window.jspdf.jsPDF;
    if (!JsPDF) throw new Error('The PDF library did not load.');

    var doc = new JsPDF({ unit: 'mm', format: 'a4' });
    var height = doc.internal.pageSize.getHeight();
    var width = doc.internal.pageSize.getWidth();

    var y = header(
      doc,
      'Organization profile',
      data.respondents + ' scorecard' + (data.respondents === 1 ? '' : 's'),
    );

    doc.setTextColor.apply(doc, INK);
    doc.setFont('times', 'normal');
    doc.setFontSize(22);
    doc.text(String(data.name || 'Unnamed'), 14, y);
    y += 7;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, BODY);
    doc.text(
      'Level ' + (data.level ? data.level.value + ' · ' + data.level.name : 'unscored') +
      '   ·   Overall score ' + fmt(data.mean) +
      '   ·   Floor (weakest dimension) ' + fmt(data.floor) +
      (data.floor_code ? ' on ' + data.floor_code : ''),
      14,
      y,
    );
    y += 10;

    /*
     * Context the reference kept in its dialog heading rather than its PDF. On
     * paper there is nothing else to say which organisation this is — or which
     * years it covers, which matters because the dialog this was printed from
     * shows the latest year alone and this document covers all of them.
     */
    var context = [
      data.sector,
      data.size ? data.size + ' employees' : null,
      data.years ? 'every scorecard, ' + data.years : null,
    ].filter(Boolean).join(' · ');

    if (context || data.synthetic) {
      doc.setFontSize(9);
      doc.setTextColor.apply(doc, CAPTION);
      doc.text(
        context + (data.synthetic ? (context ? '   ·   ' : '') + 'Includes synthetic demo records' : ''),
        14,
        y,
      );
      y += 8;
    }

    y = bars(doc, y, data.dimensions, data.floor_code);

    doc.setFontSize(8);
    doc.setTextColor.apply(doc, FOOTNOTE);
    doc.text(
      doc.splitTextToSize(
        'The level is the band of the overall average. The floor is the weakest dimension ' +
        'and is reported, not enforced. ' + (data.footnote || ''),
        width - 28,
      ),
      14,
      height - 14,
    );

    doc.save('Enterprise_Scorecard_' + String(data.name || 'organization').replace(/[^\w]+/g, '_') + '.pdf');
  }

  /** The whole dataset, as the reference's `exportFullPDF()` drew it. */
  function buildReport(data) {
    var JsPDF = window.jspdf && window.jspdf.jsPDF;
    if (!JsPDF) throw new Error('The PDF library did not load.');

    var doc = new JsPDF({ unit: 'mm', format: 'a4' });
    var width = doc.internal.pageSize.getWidth();
    var height = doc.internal.pageSize.getHeight();

    var y = header(doc, 'Research report', data.generated_on);

    doc.setTextColor.apply(doc, INK);
    doc.setFont('times', 'normal');
    doc.setFontSize(24);
    doc.text('Enterprise Scorecard dataset', 14, y);
    y += 8;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, BODY);
    doc.text(
      data.n + ' scorecards · ' + data.organisations + ' organizations · mean overall score ' +
      fmt(data.mean) + ' · mean floor (weakest dimension) ' + fmt(data.mean_floor),
      14,
      y,
    );
    y += 10;

    doc.setFontSize(9);
    doc.setTextColor.apply(doc, CAPTION);
    doc.text(
      doc.splitTextToSize(
        'Level follows each organization’s overall score, the average of all its answers; ' +
        'the floor is its weakest dimension and does not set the level. Scores are ' +
        'self-reported and unverified, and the sample is self-selected. ' +
        (data.include_synthetic
          ? 'This export INCLUDES synthetic demo records.'
          : 'Synthetic demo records are excluded.'),
        width - 28,
      ),
      14,
      y,
    );
    y += 14;

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, INK);
    doc.text('Dimension means', 14, y);
    y += 6;

    doc.setFont('helvetica', 'normal');
    y = bars(doc, y, data.dimensions, data.floor_code) + 6;

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor.apply(doc, INK);
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

    /*
     * Organisation, sector, year, level, overall, floor.
     *
     * No respondent name, email address or phone number: this is the document
     * most likely to be forwarded, and the CSV and JSON are where the contact
     * details belong.
     */
    var columns = [14, 76, 106, 126, 148, 168];
    ['Organization', 'Sector', 'Year', 'Level', 'Overall', 'Floor'].forEach(function (heading, index) {
      doc.text(heading, columns[index], y);
    });
    y += 4;

    doc.setDrawColor(200, 200, 200);
    doc.line(14, y - 2.5, width - 14, y - 2.5);
    doc.setFont('helvetica', 'normal');

    data.records
      .slice()
      .sort(function (a, b) {
        return String(a.organisation || '').localeCompare(String(b.organisation || ''));
      })
      .forEach(function (record) {
        if (y > height - 18) {
          doc.addPage();
          y = header(doc, 'Research report · records', 'continued');
          doc.setFontSize(7.5);
        }

        doc.setTextColor.apply(doc, TEXT);
        doc.text(String(record.organisation || '').slice(0, 34), columns[0], y);
        doc.text(String(record.sector || '').slice(0, 16), columns[1], y);
        doc.text(String(record.year || ''), columns[2], y);
        doc.text(String(record.level || '—').slice(0, 12), columns[3], y);
        doc.text(fmt(record.mean), columns[4], y);
        doc.text(fmt(record.floor), columns[5], y);
        y += 4.4;
      });

    var pages = doc.internal.getNumberOfPages();
    for (var page = 1; page <= pages; page++) {
      doc.setPage(page);
      doc.setFontSize(7.5);
      doc.setTextColor.apply(doc, FOOTNOTE);
      doc.text(
        'The Enterprise Scorecard · self-assessment · page ' + page + ' of ' + pages,
        14,
        height - 8,
      );
    }

    doc.save('Enterprise_Scorecard_Research_Report_' + new Date().toISOString().slice(0, 10) + '.pdf');
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    /*
     * Two documents, one handler: the whole dataset from the console, and one
     * organisation from the dialog the dashboard opens. They share the header,
     * the bars, the library loader and the busy state — a second copy of those is
     * a second PDF that can drift from the screens it came from.
     */
    var trigger = event.target && event.target.closest
      ? event.target.closest('[data-report-pdf],[data-org-pdf]')
      : null;
    if (!trigger) return;

    var organisation = trigger.hasAttribute('data-org-pdf');
    var source = trigger.getAttribute(organisation ? 'data-org-pdf' : 'data-report-pdf');

    event.preventDefault();

    var idle = trigger.textContent;
    trigger.textContent = 'Building…';
    trigger.classList.add('is-busy');

    function done() {
      trigger.textContent = idle;
      trigger.classList.remove('is-busy');
    }

    library(trigger.getAttribute('data-jspdf'))
      .then(function () {
        return fetch(source, { headers: { Accept: 'application/json' } });
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('The report figures could not be loaded (' + response.status + ').');
        }
        return response.json();
      })
      .then(function (payload) {
        var data = payload.data || payload;

        if (organisation ? !data.respondents : !data.n) {
          // The reference's own words when there is nothing to draw.
          toast('Nothing to export');
          return;
        }

        if (organisation) {
          buildOrganisation(data);
        } else {
          buildReport(data);
        }
      })
      .catch(function (error) {
        toast(error.message);
      })
      .then(done, done);
  });
}());
