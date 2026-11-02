<?php
/** Error page with the mirror-ball code. @var int $code @var string $message @var string $baseUrl */
use DN\Security;
?>
<div class="error-page">
  <p class="code"><?= (int)($code ?? 404) ?></p>
  <h1><?= Security::e($message ?? 'Page not found.') ?></h1>
  <p><a class="more-link" href="<?= Security::e($baseUrl) ?>/">← Back to the home page</a></p>
</div>
