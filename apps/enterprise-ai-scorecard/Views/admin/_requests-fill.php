<?php
/**
 * The Requests screens are short, so the footer (margin-top only) rode up to
 * the middle of the window. On these pages alone the main area fills the
 * window and the footer sits at the bottom. Scoped by :has(.rq-page) so no
 * other admin page changes.
 */
?>
<style>
  body.admin:has(.rq-page){display:flex;flex-direction:column;min-height:100vh}
  body.admin:has(.rq-page) .admin-main{flex:1 0 auto}
  body.admin:has(.rq-page) > footer{flex-shrink:0}
</style>
