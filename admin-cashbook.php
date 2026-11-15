<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cashbook-lib.php';
require_once __DIR__ . '/cashbook-view.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

$states = cb_month_states($pdo);
$latest = latest_final_month($pdo);
$asked  = cb_month_param((string) ($_GET['month'] ?? ''));
$view   = $latest;
foreach ($states as $s) {
    if ($asked !== null && $s['month'] === $asked && $s['state'] === 'final') {
        $view = $asked;
    }
}
$openMonth = cb_open_month($pdo);

admin_open($user, 'Cash Book');
show_flash();
admin_tabs('cashbook');
?>

    <div class="month-banner" style="margin-top:6px">
      <h1><?= $view !== null ? e(cb_month_label($view)) : 'Cash Book' ?></h1>
<?php if ($view !== null): ?>
      <span class="month-tag final">Saved</span>
      <a class="btn secondary" style="padding:6px 16px" href="admin-cashbook-entry.php?month=<?= e(cb_month_url($view)) ?>">Edit this month</a>
<?php endif; ?>
<?php if ($openMonth !== null): ?>
      <p class="month-note">
        <?= $openMonth['state'] === 'draft' ? 'A draft for ' : 'Next to enter: ' ?><strong><?= e(cb_month_label($openMonth['month'])) ?></strong>
        <?= $openMonth['state'] === 'draft' ? 'is in progress.' : '.' ?>
        Months must be saved in order, so later months stay locked until this one is saved.
      </p>
<?php else: ?>
      <p class="month-note">Every month up to <?= e(cb_month_label(cb_month_start('today'))) ?> has been saved. The next month can be entered once it begins.</p>
<?php endif; ?>
    </div>
<?php admin_month_strip($states, $view, 'admin-cashbook.php', null); ?>

<?php if ($view === null): ?>
    <section class="rep-wrap"><p style="margin:0;padding:18px;color:var(--text-muted)">
      No month has been saved yet. Use the highlighted month above to start entering data.</p></section>
<?php else:
    $rep = cb_report($pdo, $view);
    cb_render_report($rep);
    if ($rep['closures']): ?>
    <section class="rep-wrap">
      <h2 class="rep-title">Accounts closed this month</h2>
      <table class="rep">
        <thead><tr><th>Member</th><th>Closed on</th><th>Net</th><th></th></tr></thead>
        <tbody>
<?php   foreach ($rep['closures'] as $c): ?>
          <tr>
            <td class="l"><?= e(display_member_no((int) $c['member_no']) . ' ' . $c['full_name']) ?></td>
            <td><?= e(date('j M Y', strtotime($c['closing_date']))) ?></td>
            <td><?= (float) $c['net_payable'] >= 0 ? 'Paid ' . e(rs($c['net_payable'])) : 'Recovered ' . e(rs(abs((float) $c['net_payable']))) ?></td>
            <td><a href="admin-member-close.php?closure=<?= (int) $c['id'] ?>" style="color:var(--blue);font-weight:600">Statement</a></td>
          </tr>
<?php   endforeach; ?>
        </tbody>
      </table>
    </section>
<?php   endif;
endif; ?>

<?php admin_close(); ?>
