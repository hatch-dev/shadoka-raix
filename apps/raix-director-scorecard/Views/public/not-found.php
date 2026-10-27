<?php
/**
 * Shown for a token that does not resolve.
 *
 * Says the same thing for an unknown token as for a deleted one: confirming
 * which is which would let someone probe for valid tokens.
 *
 * @var \Platform\Core\View $this
 */
$this->extend('layouts/public');
?>

<?php $this->start('content'); ?>
<section class="view active">
  <div class="wrap-n" style="padding-top:80px;padding-bottom:80px">
    <div class="eyebrow">Not found</div>
    <h2 class="title">That scorecard could not be found.</h2>
    <p class="lede">The link may be incomplete, or the scorecard may have been removed.
       A scorecard is reached by its own private link — there is no account to sign in to,
       and no way to look one up.</p>
    <div class="nav-row">
      <a class="btn quiet" href="<?= e(url($slug)) ?>">Overview</a>
      <a class="btn gold" href="<?= e(url($slug . '/assess')) ?>">Score yourself</a>
    </div>
  </div>
</section>
<?php $this->stop(); ?>
