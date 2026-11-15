<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cashbook-lib.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

$css = <<<CSS
  .doc { background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); padding:26px 30px; max-width:760px; }
  .doc h2 { margin:0 0 4px; color:var(--navy); font-size:1.25rem; }
  .doc .sub { margin:0 0 18px; color:var(--text-muted); font-size:.9rem; }
  table.settle { width:100%; border-collapse:collapse; font-size:.92rem; font-variant-numeric:tabular-nums; margin-bottom:6px; }
  table.settle th { text-align:left; font-size:.78rem; color:var(--text-muted); padding:8px 0; border-bottom:2px solid var(--navy); }
  table.settle th.r, table.settle td.r { text-align:right; }
  table.settle td { padding:8px 0; border-bottom:1px solid var(--border-color); }
  table.settle tr.grp td { font-weight:700; color:var(--navy); padding-top:16px; border-bottom:none; }
  table.settle tr.sum td { font-weight:700; background:#f4f6fb; padding:10px 8px; }
  table.settle tr.net td { font-weight:700; font-size:1.05rem; background:#e8eef9; padding:12px 8px; }
  .rsb { position:relative; display:inline-block; }
  .rsb::before { content:"\20B9"; position:absolute; left:9px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; }
  .rsb input { padding-left:22px !important; }
  table.settle input { width:130px; padding:7px 9px; text-align:right; font:inherit; border:1px solid var(--border-color); border-radius:6px; }
  .formrow { display:flex; flex-wrap:wrap; gap:12px 24px; align-items:end; margin-bottom:18px; }
  .formrow label { display:block; font-weight:600; font-size:.85rem; margin-bottom:4px; }
  .formrow input[type=date] { padding:8px 10px; font:inherit; border:1px solid var(--border-color); border-radius:var(--radius-sm); }
  @media print { .admin-header, .admin-tabs, .noprint, .flash, .warn { display:none !important; } .doc { border:none; box-shadow:none; padding:0; } body { background:#fff; } }
CSS;

// ============================================================ statement view
if (isset($_GET['closure']) || (($_POST['action'] ?? '') === 'cancel')) {
    $closureId = (int) ($_GET['closure'] ?? $_POST['closure'] ?? 0);
    $q = $pdo->prepare(
        'SELECT cl.*, m.full_name, m.member_no FROM member_closures cl JOIN members m ON m.id = cl.member_id WHERE cl.id = :id'
    );
    $q->execute([':id' => $closureId]);
    $cl = $q->fetch();
    if (!$cl) {
        set_flash('err', 'That closure was not found.');
        redirect('admin-dashboard.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid()) {
            set_flash('err', 'Your page expired. Please try again.');
        } elseif ($cl['status'] !== 'draft') {
            set_flash('err', 'A saved closure cannot be cancelled.');
        } else {
            cb_closure_cancel($pdo, $closureId);
            set_flash('ok', 'The closure for ' . $cl['full_name'] . ' was cancelled. Their account is unchanged.');
        }
        redirect('admin-dashboard.php');
    }

    $refundTotal = (float) $cl['share_refund'] + (float) $cl['thrift_balance'] + (float) $cl['thrift_interest']
                 + (float) $cl['guarantee_balance'] + (float) $cl['guarantee_interest'];
    $duesTotal   = (float) $cl['mt_principal'] + (float) $cl['mt_interest'] + (float) $cl['st_principal'] + (float) $cl['st_interest'];
    $net         = (float) $cl['net_payable'];
    $isDraft     = $cl['status'] === 'draft';

    admin_open($user, 'Account closure statement', $css);
    show_flash();
    admin_tabs('members');
    ?>

    <div class="doc" style="margin-top:14px">
      <h2>Account closure statement</h2>
      <p class="sub">Sadya High School Employees&rsquo; Co-operative Credit Society Ltd.</p>

      <table class="settle" style="margin-bottom:18px">
        <tr><td style="width:34%"><strong>Member</strong></td><td><?= e(display_member_no((int) $cl['member_no']) . ' ' . $cl['full_name']) ?></td></tr>
        <tr><td><strong>Account closed on</strong></td><td><?= e(date('j F Y', strtotime($cl['closing_date']))) ?></td></tr>
        <tr><td><strong>Cash book month</strong></td><td><?= e(cb_month_label($cl['cash_month'])) ?>
          <span class="month-tag<?= $isDraft ? '' : ' final' ?>" style="margin-left:8px"><?= $isDraft ? 'Takes effect when the month is saved' : 'Saved &middot; account closed' ?></span></td></tr>
      </table>

      <table class="settle">
        <thead><tr><th>Particulars</th><th class="r">Amount (₹)</th></tr></thead>
        <tbody>
          <tr class="grp"><td colspan="2">Refund due to the member</td></tr>
          <tr><td>Share balance</td><td class="r"><?= e(inr_auto($cl['share_refund'])) ?></td></tr>
          <tr><td>Thrift fund balance</td><td class="r"><?= e(inr_auto($cl['thrift_balance'])) ?></td></tr>
          <tr><td>Interest on thrift fund</td><td class="r"><?= e(inr_auto($cl['thrift_interest'])) ?></td></tr>
          <tr><td>Guarantee fund balance</td><td class="r"><?= e(inr_auto($cl['guarantee_balance'])) ?></td></tr>
          <tr><td>Interest on guarantee fund</td><td class="r"><?= e(inr_auto($cl['guarantee_interest'])) ?></td></tr>
          <tr class="sum"><td>Total refund</td><td class="r"><?= e(inr_auto($refundTotal)) ?></td></tr>

          <tr class="grp"><td colspan="2">Less: dues recovered</td></tr>
          <tr><td>MT loan principal</td><td class="r"><?= e(inr_auto($cl['mt_principal'])) ?></td></tr>
          <tr><td>MT loan interest</td><td class="r"><?= e(inr_auto($cl['mt_interest'])) ?></td></tr>
          <tr><td>ST loan principal</td><td class="r"><?= e(inr_auto($cl['st_principal'])) ?></td></tr>
          <tr><td>ST loan interest</td><td class="r"><?= e(inr_auto($cl['st_interest'])) ?></td></tr>
          <tr class="sum"><td>Total dues</td><td class="r"><?= e(inr_auto($duesTotal)) ?></td></tr>

          <tr class="net"><td><?= $net >= 0 ? 'Net amount payable to the member' : 'Net amount recoverable from the member' ?></td>
              <td class="r"><?= e(inr_auto(abs($net))) ?></td></tr>
        </tbody>
      </table>
      <p class="sub" style="margin-top:14px">Signature of member: ____________________ &nbsp;&nbsp;&nbsp; Secretary: ____________________</p>
    </div>

    <div class="btn-row noprint" style="margin-top:18px">
      <button type="button" class="btn" onclick="window.print()">Print statement</button>
<?php if ($isDraft): ?>
      <a class="btn secondary" href="admin-cashbook-entry.php?month=<?= e(cb_month_url($cl['cash_month'])) ?>">Go to <?= e(cb_month_label($cl['cash_month'])) ?> cash book</a>
      <form method="post" action="admin-member-close.php" class="inline-form" id="cancelForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="closure" value="<?= (int) $cl['id'] ?>">
        <button type="submit" class="btn danger">Cancel this closure</button>
      </form>
<?php endif; ?>
      <a class="btn secondary" href="admin-dashboard.php">Back to dashboard</a>
    </div>
<?php
    admin_close('<script>
  var f = document.getElementById("cancelForm");
  if (f) { f.addEventListener("submit", function (ev) { if (!confirm("Cancel this closure? The member\'s account stays as it is.")) { ev.preventDefault(); } }); }
</script>');
    exit;
}

// ============================================================ settlement form
$memberId = (int) ($_GET['member_id'] ?? $_POST['member_id'] ?? 0);
$q = $pdo->prepare("SELECT id, member_no, full_name FROM members WHERE id = :id AND status = 'active'");
$q->execute([':id' => $memberId]);
$member = $q->fetch();
if (!$member) {
    set_flash('err', 'That member was not found.');
    redirect('admin-dashboard.php');
}

$existing = $pdo->prepare('SELECT id FROM member_closures WHERE member_id = :id');
$existing->execute([':id' => $memberId]);
if (($cid = $existing->fetchColumn())) {
    redirect('admin-member-close.php?closure=' . (int) $cid);
}

$open = cb_open_month($pdo);
if ($open === null) {
    set_flash('err', 'Every month up to now is already saved, so there is no open month to record the closure in.');
    redirect('admin-dashboard.php');
}
$month = $open['month'];

$today   = date('Y-m-d');
$lastDay = min(cb_month_end($month), $today);
$date    = $lastDay;
$errors  = [];
$override = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Your page expired. Please try again.';
    } else {
        $posted = trim((string) ($_POST['closing_date'] ?? ''));
        $ts = strtotime($posted);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted) || $ts === false || date('Y-m-d', $ts) !== $posted) {
            $errors[] = 'Enter the closing date.';
        } elseif (cb_month_start($posted) !== $month) {
            $errors[] = 'The closing date must fall in ' . cb_month_label($month) . ', the month now open for entry.';
        } elseif ($posted > $today) {
            $errors[] = 'The closing date cannot be in the future.';
        } else {
            $date = $posted;
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create' && !$errors) {
            $map = ['thrift_interest' => 'Interest on thrift fund', 'guarantee_interest' => 'Interest on guarantee fund',
                    'mt_interest' => 'MT interest', 'st_interest' => 'ST interest'];
            foreach ($map as $key => $label) {
                $raw = trim((string) ($_POST[$key] ?? ''));
                if ($raw !== '') {
                    $override[$key] = cb_amount($raw, $label, $errors);
                }
            }
            if (!$errors) {
                $c = cb_closure_compute($pdo, $memberId, $date, $override);
                $id = cb_closure_create($pdo, $memberId, $month, $date, $c, (int) $user['id']);
                set_flash('ok', 'Account closure added to the ' . cb_month_label($month) . ' cash book. It takes effect when that month is saved.');
                redirect('admin-member-close.php?closure=' . $id);
            }
        } elseif ($action === 'preview' && !$errors) {
            $override = []; // new date: recalculate the suggested interest
        }
    }
}

$c = cb_closure_compute($pdo, $memberId, $date, $override);

$replace = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE member_id = :m AND collection_month = :mo AND closure_id IS NULL');
$replace->execute([':m' => $memberId, ':mo' => $month]);
$hasEntries = (int) $replace->fetchColumn() > 0;
$replace = $pdo->prepare('SELECT COUNT(*) FROM cash_book_entries WHERE member_id = :m AND cash_month = :mo AND closure_id IS NULL');
$replace->execute([':m' => $memberId, ':mo' => $month]);
$hasEntries = $hasEntries || (int) $replace->fetchColumn() > 0;

admin_open($user, 'Close account', $css);
admin_tabs('members');
?>

    <div class="month-banner" style="margin-top:6px">
      <h1>Close account &mdash; <?= e($member['full_name']) ?></h1>
      <span class="month-tag"><?= e(display_member_no((int) $member['member_no'])) ?></span>
      <p class="month-note">Share, thrift fund and guarantee fund are refunded with interest; any loan dues are recovered from the same amount.
        This is recorded in the <strong><?= e(cb_month_label($month)) ?></strong> cash book (the month now open for entry) and takes effect when that month is saved.
        To close the account in a later month, save the earlier months first.</p>
    </div>

<?php if ($errors): ?>
    <div class="flash flash-err" role="alert"><ul class="errlist" style="margin:0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($hasEntries): ?>
    <div class="warn">Entries already typed for this member in the <?= e(cb_month_label($month)) ?> sheet will be replaced by the closure.</div>
<?php endif; ?>

    <form method="post" action="admin-member-close.php" class="doc" id="closeForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">

      <div class="formrow">
        <div>
          <label for="closing_date">Closing date</label>
          <input type="date" id="closing_date" name="closing_date" value="<?= e($date) ?>" min="<?= e($month) ?>" max="<?= e($lastDay) ?>">
        </div>
        <button type="submit" name="action" value="preview" class="btn secondary">Recalculate interest for this date</button>
      </div>

      <table class="settle">
        <thead><tr><th>Particulars</th><th class="r">Amount (₹)</th></tr></thead>
        <tbody>
          <tr class="grp"><td colspan="2">Refund due to the member</td></tr>
          <tr><td>Share balance</td><td class="r"><?= e(inr_auto($c['share_refund'])) ?></td></tr>
          <tr><td>Thrift fund balance</td><td class="r"><?= e(inr_auto($c['thrift_balance'])) ?></td></tr>
          <tr><td>Interest on thrift fund <small style="color:var(--text-muted)">(<?= e(inr_auto((float) cb_rules($pdo)['thrift_interest_rate'])) ?>% a year, this financial year to date)</small></td>
              <td class="r"><span class="rsb"><input data-calc="in" inputmode="decimal" name="thrift_interest" value="<?= e((string) $c['thrift_interest']) ?>" aria-label="Interest on thrift fund"></span></td></tr>
          <tr><td>Guarantee fund balance</td><td class="r"><?= e(inr_auto($c['guarantee_balance'])) ?></td></tr>
          <tr><td>Interest on guarantee fund</td>
              <td class="r"><span class="rsb"><input data-calc="in" inputmode="decimal" name="guarantee_interest" value="<?= e((string) $c['guarantee_interest']) ?>" aria-label="Interest on guarantee fund"></span></td></tr>
          <tr class="sum"><td>Total refund</td><td class="r" id="refundTotal"><?= e(inr_auto($c['refund_total'])) ?></td></tr>

          <tr class="grp"><td colspan="2">Less: dues recovered</td></tr>
          <tr><td>MT loan principal outstanding</td><td class="r"><?= e(inr_auto($c['mt_principal'])) ?></td></tr>
          <tr><td>MT loan interest <small style="color:var(--text-muted)">(last month in full + days to the closing date)</small></td>
              <td class="r"><?= $c['mt_principal'] > 0
                  ? '<span class="rsb"><input data-calc="due" inputmode="decimal" name="mt_interest" value="' . e((string) $c['mt_interest']) . '" aria-label="MT interest"></span>'
                  : '&mdash;' ?></td></tr>
          <tr><td>ST loan principal outstanding</td><td class="r"><?= e(inr_auto($c['st_principal'])) ?></td></tr>
          <tr><td>ST loan interest <small style="color:var(--text-muted)">(last month in full + days to the closing date)</small></td>
              <td class="r"><?= $c['st_principal'] > 0
                  ? '<span class="rsb"><input data-calc="due" inputmode="decimal" name="st_interest" value="' . e((string) $c['st_interest']) . '" aria-label="ST interest"></span>'
                  : '&mdash;' ?></td></tr>
          <tr class="sum"><td>Total dues</td><td class="r" id="duesTotal"><?= e(inr_auto($c['dues_total'])) ?></td></tr>

          <tr class="net"><td id="netLabel"><?= $c['net_payable'] >= 0 ? 'Net amount payable to the member' : 'Net amount recoverable from the member' ?></td>
              <td class="r" id="netTotal"><?= e(inr_auto(abs($c['net_payable']))) ?></td></tr>
        </tbody>
      </table>

      <div class="btn-row noprint" style="margin-bottom:0">
        <button type="submit" name="action" value="create" class="btn">Add to <?= e(cb_month_label($month)) ?> cash book</button>
        <a class="btn secondary" href="admin-dashboard.php">Cancel</a>
      </div>
    </form>

<?php
$js = '<script>
(function () {
  var fixed = ' . json_encode([
      'refund' => round($c['share_refund'] + $c['thrift_balance'] + $c['guarantee_balance'], 2),
      'dues'   => round($c['mt_principal'] + $c['st_principal'], 2),
  ]) . ';
  function num(v) { v = String(v || "").replace(/,/g, "").trim(); var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function fmt(n) { return n.toLocaleString("en-IN", { maximumFractionDigits: 2 }); }
  function recalc() {
    var refund = fixed.refund, dues = fixed.dues;
    document.querySelectorAll("[data-calc=in]").forEach(function (el) { refund += num(el.value); });
    document.querySelectorAll("[data-calc=due]").forEach(function (el) { dues += num(el.value); });
    var net = refund - dues;
    document.getElementById("refundTotal").textContent = fmt(refund);
    document.getElementById("duesTotal").textContent = fmt(dues);
    document.getElementById("netTotal").textContent = fmt(Math.abs(net));
    document.getElementById("netLabel").textContent = net >= 0 ? "Net amount payable to the member" : "Net amount recoverable from the member";
  }
  document.getElementById("closeForm").addEventListener("input", recalc);
})();
</script>';
admin_close($js);
