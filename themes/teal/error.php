<?php
/** Error page (404/403/…).
 * @var int $code
 * @var string $message
 * @var string $baseUrl
 */
use DN\Security;
?>
<h1><?= (int)($code ?? 404) ?></h1>
<p class="post-excerpt"><?= Security::e($message ?? 'Page not found.') ?></p>
<p><a class="more-link" href="<?= Security::e($baseUrl) ?>/">← Back to the home page</a></p>
