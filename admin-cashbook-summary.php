<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cashbook-lib.php';
require_once __DIR__ . '/cashbook-view.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

$month = cb_month_param((string) ($_GET['month'] ?? ''));
$open  = cb_open_month($pdo);
if ($month === null || $open === null || $open['month'] !== $month) {
    set_flash('err', 'That month is not open for review.');
    redirect('admin-dashboard.php');
}

$ctx  = cb_ctx($pdo, $month);
$meta = cb_meta($pdo, $month);
$missing = $meta ? cb_unsaved_members($pdo, $month, $ctx) : $ctx['members'];
if (!$meta || $missing || empty($meta['entry_date'])) {
    set_flash('err', 'Press Save for every member, and save the date, before finalizing ' . cb_month_label($month) . '.');
    redirect('admin-cashbook-entry.php?month=' . cb_month_url($month));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Your page expired. Please try again.';
    } else {
        $errors = cb_finalize($pdo, $month, (int) $user['id']);
        if (!$errors) {
            set_flash('ok', cb_month_label($month) . ' has been saved. The dashboard now shows the position at the end of the month.');
            redirect('admin-dashboard.php');
        }
    }
}

$parsed   = cb_complete_rows(cb_parsed_from_db($pdo, $month), $ctx);
$problems = $errors ?: cb_check($parsed, $ctx);
$warnings = cb_warnings($parsed, $ctx);
$cashNow  = cb_cash_month($pdo, $month);
if ($cashNow['closing'] < -0.004) {
    $warnings[] = 'Cash in hand would be negative at the end of the month (-' . rs(abs($cashNow['closing'])) . '). Check the payments, or add a bank withdrawal.';
}
$rep      = cb_report($pdo, $month);

admin_open($user, 'Review ' . cb_month_label($month));
admin_tabs('cashbook');
?>

    <div class="month-banner" style="margin-top:6px">
      <h1>Review &mdash; <?= e(cb_month_label($month)) ?></h1>
      <span class="month-tag">Not saved yet</span>
      <p class="month-note">Check the receipts and payments below. Use <strong>Edit</strong> to change the Total or Interest of any loan
        (the Principal always follows as Total &minus; Interest). When everything is right, save the month.</p>
    </div>

<?php if ($problems): ?>
    <div class="flash flash-err" role="alert">
      This month cannot be saved yet:
      <ul class="errlist">
<?php   foreach ($problems as $er): ?>
        <li><?= e($er) ?></li>
<?php   endforeach; ?>
      </ul>
    </div>
<?php endif; ?>
<?php foreach ($warnings as $w): ?>
    <div class="warn"><?= e($w) ?></div>
<?php endforeach; ?>

<?php cb_render_report($rep, ['edit_links' => true]); ?>

<?php if ($rep['closures']): ?>
    <div class="warn">
      Account closures in this month:
<?php   foreach ($rep['closures'] as $c): ?>
      <a href="admin-member-close.php?closure=<?= (int) $c['id'] ?>" style="color:inherit;font-weight:700"><?= e($c['full_name']) ?></a>
        (<?= (float) $c['net_payable'] >= 0 ? 'pay ₹' . e(inr_auto($c['net_payable'])) : 'recover ₹' . e(inr_auto(abs((float) $c['net_payable']))) ?>)&nbsp;
<?php   endforeach; ?>
      &mdash; they leave the member list when this month is saved.
    </div>
<?php endif; ?>

    <div class="btn-row">
      <a class="btn secondary" href="admin-cashbook-entry.php?month=<?= e(cb_month_url($month)) ?>">Edit entries</a>
      <form method="post" action="admin-cashbook-summary.php?month=<?= e(cb_month_url($month)) ?>" class="inline-form" id="confirmForm"
            data-month="<?= e(cb_month_label($month)) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn"<?= $problems ? ' disabled' : '' ?>>Confirm and save <?= e(cb_month_label($month)) ?></button>
      </form>
      <a class="btn secondary" href="admin-dashboard.php">Back to dashboard</a>
    </div>

<?php
admin_close('<script>
  document.getElementById("confirmForm").addEventListener("submit", function (ev) {
    if (!confirm("Save " + this.dataset.month + "?\n\nOnce saved, this month becomes part of the balances and every ledger. You can still correct it later from the Cash Book tab (Edit this month); each correction is checked so that every later month stays consistent.")) {
      ev.preventDefault();
    }
  });
</script>');
