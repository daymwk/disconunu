<?php
/** List metadata stays useful even on the first or an empty page. */
use DN\View;
?>
<div class="list-footer">
  <span class="muted"><?= (int)$total ?> <?= $total === 1 ? 'result' : 'results' ?> · Page <?= (int)$page ?> of <?= (int)$pages ?></span>
  <?= View::pagination((int)$page, (int)$pages, $paginationUrl ?? '') ?>
</div>
