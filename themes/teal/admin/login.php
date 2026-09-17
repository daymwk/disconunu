<?php
/** Sign-in page, and first-run setup (the very first account becomes superadmin).
 * @var string $error
 * @var bool $noUsers
 * @var string $baseUrl
 * @var string $csrf
 */
use DN\Security;
?>
<h1><?= $noUsers ? 'Set up your site' : 'Sign in' ?></h1>

<?php if ($error !== ''): ?><div class="flash flash-error"><?= Security::e($error) ?></div><?php endif; ?>

<?php if ($noUsers): ?>
  <p class="post-excerpt">No account exists yet. This first one is created as
    <strong>superadmin</strong>: it can do everything, including settings, themes and user roles.</p>
<?php endif; ?>

<form class="post-form" method="post" action="<?= Security::e($baseUrl) ?>/admin/login">
  <input type="hidden" name="csrf" value="<?= Security::e($csrf) ?>">
  <label>Username
    <input type="text" name="username" required autofocus autocomplete="username" maxlength="64">
  </label>
  <?php if ($noUsers): ?>
  <label>Email
    <input type="email" name="email" required autocomplete="email" maxlength="255">
  </label>
  <?php endif; ?>
  <label>Password <?= $noUsers ? '(10 characters minimum)' : '' ?>
    <input type="password" name="password" required autocomplete="<?= $noUsers ? 'new-password' : 'current-password' ?>">
  </label>
  <button class="btn" type="submit"><?= $noUsers ? 'Create the superadmin account' : 'Sign in' ?></button>
</form>
