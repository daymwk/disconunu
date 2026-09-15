<?php
/** Page d'erreur.
 * @var int $code
 * @var string $message
 * @var string $baseUrl
 */
?>
<div class="error-page">
  <div class="code"><?= (int)$code ?></div>
  <p><?= htmlspecialchars($message ?: 'Rien ici.', ENT_QUOTES, 'UTF-8') ?></p>
  <p><a class="more-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/">← Retour à l’accueil</a></p>
</div>
