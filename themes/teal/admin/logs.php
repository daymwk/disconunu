<?php
/** Read-only audit trail of administrative actions (superadmin route). */
use DN\Model\AdminLog;
use DN\Security;
use DN\View;
// Stored in UTC, shown in the site timezone (DN_TIMEZONE).
$tz = new DateTimeZone(date_default_timezone_get());
?>
<header class="page-heading"><div><p class="eyebrow">Audit trail</p><h1>Logs <span class="count-pill"><?= (int)$total ?></span></h1><p class="page-description">Who did what, and when. Times in <?= Security::e($tz->getName()) ?>.</p></div></header>
<form class="filter-bar" method="get" action="<?= Security::e($baseUrl) ?>/admin/logs">
  <label class="search-field">Search logs<input type="search" name="q" value="<?= Security::e($filters['q'] ?? '') ?>" placeholder="User, action or target"></label>
  <label>Action<select name="action"><option value="">All actions</option><?php foreach (AdminLog::ACTIONS as $value): ?><option value="<?= Security::e($value) ?>" <?= ($filters['action'] ?? '') === $value ? 'selected' : '' ?>><?= Security::e(str_replace('_', ' ', $value)) ?></option><?php endforeach; ?></select></label>
  <label>Per page<input type="number" name="per_page" min="1" max="100" value="<?= (int)$perPage ?>"></label>
  <div class="filter-actions"><button class="btn" type="submit">Filter</button><a class="btn btn-ghost" href="<?= Security::e($baseUrl) ?>/admin/logs">Reset</a></div>
</form>
<section class="panel table-panel" aria-label="Logs">
  <table class="admin-table"><thead><tr><th scope="col">When</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">IP</th></tr></thead><tbody>
  <?php foreach ($logs as $log): ?><tr>
    <td data-label="When"><?= Security::e((new DateTimeImmutable($log->createdAt, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s')) ?></td>
    <td data-label="User"><strong><?= Security::e($log->username !== '' ? $log->username : '—') ?></strong></td>
    <td data-label="Action"><span class="badge"><?= Security::e(str_replace('_', ' ', $log->action)) ?></span></td>
    <td data-label="Target"><?= Security::e($log->target) ?><?php if ($log->detail !== ''): ?><span class="row-subtitle"><?= Security::e($log->detail) ?></span><?php endif; ?></td>
    <td data-label="IP"><code><?= Security::e($log->ip) ?></code></td>
  </tr><?php endforeach; ?>
  </tbody></table>
  <?php if (!$logs): ?><div class="empty-state"><strong>No logs found</strong>Try a different search or reset the filters.</div><?php endif; ?>
</section>
<?php View::partial('admin/pagination', compact('page', 'pages', 'total', 'perPage', 'filters', 'paginationUrl')); ?>
