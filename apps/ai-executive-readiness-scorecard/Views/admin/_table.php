<?php
/**
 * The reference's table(headers, rows): a plain `pair-table` in a scrolling
 * wrapper, the first cell of each row a row header. Every cell is escaped.
 *
 * @var list<string>       $headers
 * @var list<list<mixed>>  $rows
 */
?>
<div class="table-scroll"><table class="pair-table">
  <thead><tr><?php foreach ($headers as $header): ?><th scope="col"><?= e($header) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
    <?php foreach ($rows as $row): ?>
      <tr><?php foreach (array_values($row) as $i => $cell): ?><?= $i === 0 ? '<th scope="row">' . e((string) $cell) . '</th>' : '<td>' . e((string) $cell) . '</td>' ?><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
