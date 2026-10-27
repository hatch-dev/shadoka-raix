<?php
/**
 * Editing a draft instrument.
 *
 * One form per competency, saved independently — a single form holding seven
 * competencies and thirty-five statements is one misclick away from losing an
 * afternoon's work.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/admin');
?>

<?php $this->start('content'); ?>
<div class="admin-head">
  <div>
    <div class="eyebrow">
      <a href="<?= e(url('admin/' . $slug . '/instrument')) ?>">← Instrument</a>
    </div>
    <h1 class="title">Editing version <?= e($instrument['version']) ?></h1>
    <p class="lede">
      A draft. Nothing here affects any score until it is published, and
      publishing archives the current version rather than replacing it.
    </p>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <form method="post" action="<?= e(url('admin/' . $slug . '/instrument/' . $instrument['id'] . '/discard')) ?>"
          onsubmit="return confirm('Discard this draft? Every edit in it is lost.')">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button class="btn sm danger" type="submit">Discard draft</button>
    </form>
    <form method="post" action="<?= e(url('admin/' . $slug . '/instrument/' . $instrument['id'] . '/publish')) ?>"
          onsubmit="return confirm('Publish version <?= e($instrument['version']) ?>? New scorecards will use it from now on. Existing ones keep the version they were taken under.')">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button class="btn sm gold" type="submit"<?= $problems === [] ? '' : ' disabled' ?>>Publish</button>
    </form>
  </div>
</div>

<?php if (!empty($flash)): ?>
  <div class="notice"><?= e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="err" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<?php if ($problems !== []): ?>
  <div class="notice" style="border-color:rgba(210,86,78,.45);background:rgba(210,86,78,.08)">
    <b style="color:var(--r1)">Not ready to publish.</b>
    <ul style="margin:6px 0 0 18px">
      <?php foreach ($problems as $problem): ?>
        <li><?= e($problem) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="notice">
  <b>What cannot be changed here.</b>
  A competency's three-letter code is fixed: it identifies the competency in
  every export, analytics query and codebook already published, and renaming it
  would break comparability across versions. Statements can be reworded but not
  added or removed — a competency that gains or loses an item has stopped being
  the same measurement, which is a bigger change than an edit.
</div>

<?php foreach ($competencies as $competency): ?>
  <div class="card" style="margin-bottom:18px" id="competency-<?= e($competency['id']) ?>">
    <form method="post"
          action="<?= e(url('admin/' . $slug . '/instrument/' . $instrument['id'] . '/competency/' . $competency['id'])) ?>">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">

      <h3><span class="pill"><?= e($competency['code']) ?></span>
        <input class="f" name="name" value="<?= e($competency['name']) ?>" maxlength="100"
               style="display:inline-block;width:auto;min-width:320px;font-weight:600">
      </h3>

      <div style="margin:14px 0">
        <label class="f" for="desc-<?= e($competency['id']) ?>">Description</label>
        <textarea class="f" id="desc-<?= e($competency['id']) ?>" name="description" rows="3"><?= e($competency['description']) ?></textarea>
      </div>

      <div class="grid grid-2" style="margin-bottom:14px">
        <div>
          <label class="f" for="bq-<?= e($competency['id']) ?>">Board question</label>
          <input class="f" id="bq-<?= e($competency['id']) ?>" name="board_question"
                 value="<?= e($competency['board_question']) ?>" maxlength="255">
          <div class="hint">The question a board should be able to press.</div>
        </div>
        <div>
          <label class="f" for="fl-<?= e($competency['id']) ?>">Failing signature</label>
          <input class="f" id="fl-<?= e($competency['id']) ?>" name="failure_line"
                 value="<?= e($competency['failure_line']) ?>" maxlength="255">
          <div class="hint">What failing this competency looks like.</div>
        </div>
      </div>

      <details style="margin-bottom:14px">
        <summary class="f" style="cursor:pointer">Guide panels and floor narrative</summary>
        <div style="margin-top:12px">
          <?php foreach ([
              'guide_what'      => 'What it is',
              'guide_why'       => 'Why it matters',
              'guide_how'       => 'How to build it',
              'guide_example'   => 'What it looks like',
          ] as $field => $label): ?>
            <div style="margin-bottom:12px">
              <label class="f" for="<?= e($field . '-' . $competency['id']) ?>"><?= e($label) ?></label>
              <textarea class="f" id="<?= e($field . '-' . $competency['id']) ?>" name="<?= e($field) ?>"
                        rows="3"><?= e($competency[$field]) ?></textarea>
            </div>
          <?php endforeach; ?>

          <div>
            <label class="f" for="fn-<?= e($competency['id']) ?>">Floor narrative</label>
            <textarea class="f" id="fn-<?= e($competency['id']) ?>" name="floor_narrative"
                      rows="4"><?= e($competency['floor_narrative']) ?></textarea>
            <div class="hint">
              Shown when this competency sets a director's level.
              <code>{floor}</code> is replaced with their score.
            </div>
          </div>
        </div>
      </details>

      <label class="f">Statements</label>
      <?php foreach ($competency['items'] as $item): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px">
          <span class="mono small muted" style="padding-top:10px;min-width:52px">
            <?= e($competency['code']) ?> <?= e($item['position']) ?>
          </span>
          <textarea class="f" name="statements[<?= e($item['id']) ?>]" rows="2"
                    required><?= e($item['statement']) ?></textarea>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:14px">
        <button class="btn sm" type="submit">Save <?= e($competency['code']) ?></button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
<?php $this->stop(); ?>
