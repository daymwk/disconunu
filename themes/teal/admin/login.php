<?php
/** Connexion / premier lancement (création de l'admin).
 * @var string $error
 * @var bool $noUsers
 * @var string $csrf
 * @var string $baseUrl
 */
use DN\Security;
?>
<h1><?= $noUsers ? 'Premier lancement' : 'Connexion' ?></h1>

<?php if ($noUsers): ?>
<p>Aucun compte n’existe encore. Le premier compte créé aura le rôle <strong>admin</strong>.</p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= Security::e($error) ?></div>
<?php endif; ?>

<form class="auth-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/login">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Login
    <input type="text" name="username" autocomplete="username" required autofocus>
  </label>
  <?php if ($noUsers): ?>
  <label>Email
    <input type="email" name="email" autocomplete="email" required>
  </label>
  <?php endif; ?>
  <label>Mot de passe
    <input type="password" name="password" autocomplete="<?= $noUsers ? 'new-password' : 'current-password' ?>" required>
  </label>
  <button class="btn" type="submit"><?= $noUsers ? 'Créer l’admin' : 'Se connecter' ?></button>
</form>
