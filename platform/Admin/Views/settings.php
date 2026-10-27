<?php
/**
 * /admin/{slug}/settings — one app's admin password.
 *
 * The strength checklist is rendered from Auth::PASSWORD_RULES (passed in as
 * $rules), the same list the server enforces, so what the page ticks off is
 * exactly what the server will accept. The script only helps; the server
 * re-checks everything and its errors land under the field they belong to.
 *
 * @var \Platform\Core\View $this
 * @var string $appName
 * @var string $appSlug
 * @var bool $pinEnabled
 * @var ?string $changedAt
 * @var int $minLength
 * @var int $maxLength
 * @var array<string, array{label:string, pattern:string}> $rules
 * @var ?string $notice
 * @var array<string, string> $errors keyed by field: current, pin, pin_confirm, form
 */
$this->extend('layouts/admin');

$errors = $errors ?? [];

/* A password input with its show/hide toggle and its server error, if any. */
$field = static function (string $id, string $label, string $autocomplete, array $errors, string $extra = ''): string {
    $error = $errors[$id] ?? null;
    $html  = '<div class="pw-field' . ($error !== null ? ' has-error' : '') . '">'
        . '<label class="f" for="' . e($id) . '">' . e($label) . '</label>'
        . '<div class="pw-wrap">'
        . '<input class="f" id="' . e($id) . '" name="' . e($id) . '" type="password" autocomplete="' . e($autocomplete) . '"'
        . ($error !== null ? ' aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '')
        . ' ' . $extra . '>'
        . '<button type="button" class="pw-toggle" data-toggle="' . e($id) . '" aria-label="Show password" aria-pressed="false">Show</button>'
        . '</div>';

    if ($error !== null) {
        $html .= '<div class="err pw-error" id="' . e($id) . '-error" role="alert">' . e($error) . '</div>';
    }

    return $html;
};
?>

<?php $this->start('content'); ?>
<style>
  .pw-field{margin-bottom:18px}
  .pw-wrap{position:relative}
  .pw-wrap input.f{padding-right:72px}
  .pw-toggle{
    position:absolute;right:8px;top:50%;transform:translateY(-50%);
    background:none;border:0;color:var(--blue-2);font:600 13px var(--sans);
    padding:6px 8px;cursor:pointer;border-radius:3px
  }
  .pw-toggle:hover{color:var(--blue-3)}
  .pw-field.has-error input.f{border-color:var(--r1)}
  .pw-error{font-size:13.5px}

  .pw-meter{display:flex;gap:6px;margin:12px 0 6px}
  .pw-meter span{flex:1;height:5px;border-radius:3px;background:var(--rule);transition:background .2s}
  .pw-meter[data-score="1"] span:nth-child(-n+1){background:var(--r1)}
  .pw-meter[data-score="2"] span:nth-child(-n+2){background:var(--gold)}
  .pw-meter[data-score="3"] span:nth-child(-n+3){background:var(--blue-2)}
  .pw-meter[data-score="4"] span{background:var(--blue-3)}
  .pw-strength{font-size:13px;color:var(--stone-2);min-height:18px}
  .pw-strength b{color:var(--paper);font-weight:600}

  .pw-rules{list-style:none;margin:10px 0 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px 14px}
  .pw-rules li{font-size:13.5px;color:var(--stone-2);display:flex;align-items:center;gap:8px;line-height:1.35}
  .pw-rules li::before{
    content:'';flex:none;width:14px;height:14px;border-radius:50%;
    border:1.5px solid var(--stone-2);box-sizing:border-box
  }
  .pw-rules li.met{color:var(--paper)}
  .pw-rules li.met::before{
    border-color:var(--blue-3);background:var(--blue-3) no-repeat center/10px
      url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'%3E%3Cpath d='M1.5 5.2l2.2 2.2L8.5 2.6' fill='none' stroke='%23060D18' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E")
  }
  .pw-match{font-size:13px;margin-top:6px;min-height:18px;color:var(--stone-2)}
  .pw-match.ok{color:var(--blue-3)}
  .pw-match.bad{color:var(--r1)}
  @media (max-width:520px){.pw-rules{grid-template-columns:1fr}}
</style>

<?php /* One centred column: the heading, the notice and the form share its width. */ ?>
<div style="max-width:560px;margin:0 auto">
<div class="admin-head" style="justify-content:center;text-align:center">
  <div>
    <div class="eyebrow">Settings</div>
    <h1 class="title">Admin password</h1>
    <p class="lede">
      Controls access to the admin console for <?= e($appName) ?>.
    </p>
  </div>
</div>

<?php if (!empty($notice)): ?>
  <div class="notice" role="status" style="margin-bottom:20px"><?= e($notice) ?></div>
<?php endif; ?>

<div class="card">
  <h3>Change password</h3>
  <p class="cap">
    <?php if ($changedAt !== null): ?>
      Last changed <?= e(date((string) config('app.date_format'), strtotime($changedAt))) ?>
    <?php else: ?>
      No password has been set yet.
    <?php endif; ?>
  </p>

  <?php if (!empty($errors['form'])): ?>
    <div class="err" role="alert" style="margin-bottom:14px"><?= e($errors['form']) ?></div>
  <?php endif; ?>

  <?php /* novalidate: the checklist below does the browser's job, better; the server re-checks. */ ?>
  <form method="post" action="<?= e(url('admin/' . $appSlug . '/settings/password')) ?>" id="pwForm" novalidate>
    <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">

    <?php if ($pinEnabled): ?>
      <?= $field('current', 'Current password', 'current-password', $errors, 'required') ?>
      </div>
    <?php endif; ?>

    <?= $field('pin', 'New password', 'new-password', $errors, 'required maxlength="' . e($maxLength) . '"') ?>
      <div class="pw-meter" id="pwMeter" data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
      <div class="pw-strength" id="pwStrength" aria-live="polite"></div>
      <ul class="pw-rules" id="pwRules">
        <li data-rule="length" data-min="<?= e($minLength) ?>" data-max="<?= e($maxLength) ?>">At least <?= e($minLength) ?> characters</li>
        <?php foreach ($rules as $key => $rule): ?>
          <li data-rule="<?= e($key) ?>" data-pattern="<?= e($rule['pattern']) ?>"><?= e($rule['label']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?= $field('pin_confirm', 'Confirm new password', 'new-password', $errors, 'required') ?>
      <div class="pw-match" id="pwMatch" aria-live="polite"></div>
    </div>

    <button class="btn gold" type="submit" id="pwSubmit">Change password</button>
  </form>
</div>
</div>
<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<script>
(function () {
  var form = document.getElementById('pwForm');
  if (!form) return;

  var pin      = document.getElementById('pin');
  var confirm  = document.getElementById('pin_confirm');
  var current  = document.getElementById('current');
  var meter    = document.getElementById('pwMeter');
  var strength = document.getElementById('pwStrength');
  var match    = document.getElementById('pwMatch');
  var rules    = Array.prototype.slice.call(document.querySelectorAll('#pwRules li'));

  // Sign-in ignores surrounding spaces, so the checks do too.
  function value(input) { return input ? input.value.trim() : ''; }

  function ruleMet(li, v) {
    if (li.dataset.rule === 'length') {
      return v.length >= +li.dataset.min && v.length <= +li.dataset.max;
    }
    return new RegExp(li.dataset.pattern).test(v);
  }

  function check() {
    var v = value(pin);
    var met = 0;

    rules.forEach(function (li) {
      var ok = ruleMet(li, v);
      li.classList.toggle('met', ok);
      if (ok) met++;
    });

    var allMet = met === rules.length;

    // 0–4 bars: nothing, weak, fair, good, strong. "Strong" needs every rule
    // AND some length beyond the minimum.
    var score = v === '' ? 0 : allMet ? (v.length >= 12 ? 4 : 3) : met >= 3 ? 2 : 1;
    meter.dataset.score = score;
    strength.innerHTML = v === '' ? '' :
      'Strength: <b>' + ['', 'Weak', 'Fair', 'Good', 'Strong'][score] + '</b>' +
      (allMet ? '' : ' — ' + (rules.length - met) + ' requirement' + (rules.length - met === 1 ? '' : 's') + ' left');

    var c = value(confirm);
    match.className = 'pw-match';
    if (c === '') {
      match.textContent = '';
    } else if (c === v) {
      match.className = 'pw-match ok';
      match.textContent = 'Passwords match.';
    } else {
      match.className = 'pw-match bad';
      match.textContent = 'Passwords do not match yet.';
    }

    return allMet && c === v && (!current || value(current) !== '');
  }

  // Typing in a field clears the server's error for it; it is stale now.
  function clearError(input) {
    var wrap = input.closest('.pw-field');
    var err = wrap && wrap.querySelector('.pw-error');
    if (wrap) wrap.classList.remove('has-error');
    if (err) err.remove();
    input.removeAttribute('aria-invalid');
  }

  [pin, confirm, current].forEach(function (input) {
    if (!input) return;
    input.addEventListener('input', function () { clearError(input); check(); });
  });

  // Show / hide, per field.
  document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.toggle);
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Not a disabled button: a disabled button cannot say WHY. Submitting
  // early instead points at the first thing still missing.
  form.addEventListener('submit', function (event) {
    if (check()) return;
    event.preventDefault();

    var target = current && value(current) === '' ? current
      : rules.some(function (li) { return !li.classList.contains('met'); }) ? pin
      : confirm;

    if (target === current) {
      showError(current, 'Enter the current password.');
    } else if (target === pin) {
      showError(pin, 'The new password does not meet every requirement below.');
    } else {
      showError(confirm, value(confirm) === '' ? 'Confirm the new password.' : 'The two passwords do not match.');
    }
    target.focus();
  });

  function showError(input, message) {
    clearError(input);
    var wrap = input.closest('.pw-field');
    var err = document.createElement('div');
    err.className = 'err pw-error';
    err.id = input.id + '-error';
    err.setAttribute('role', 'alert');
    err.textContent = message;
    wrap.querySelector('.pw-wrap').insertAdjacentElement('afterend', err);
    wrap.classList.add('has-error');
    input.setAttribute('aria-invalid', 'true');
  }

  check();
})();
</script>
<?php $this->stop(); ?>
