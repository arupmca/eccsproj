<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cashbook-lib.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

// ---- which month is being shown: the balances on the 1st of that month
// (which are also the closing balances of the month before it)
$states  = cb_month_states($pdo);
$first   = (string) cb_first_month($pdo);
$latest  = latest_final_month($pdo);
$openDay = opening_date($pdo);
$viewable = [];
foreach ($states as $i => $st) {
    if ($i === 0 || $states[$i - 1]['state'] === 'final') {
        $viewable[$st['month']] = $st['state'];
    }
}
$asked = cb_month_param((string) ($_GET['month'] ?? ''));
$view  = ($asked !== null && isset($viewable[$asked])) ? $asked : (($latest !== null && isset($viewable[$latest])) ? $latest : $first);
$viewState = $viewable[$view] ?? 'final';
$through   = $view === $first ? BALANCES_OPENING : cb_add_month($view, -1);
$openMonth = cb_open_month($pdo);

$title = cb_month_label($view);
$asOn  = $view === $first && $openDay ? $openDay : $view;
if ($view === $first) {
    $tag = 'Opening balances &middot; as on ' . e(date('j F Y', strtotime($asOn)));
} else {
    $tag = 'Balances as on ' . e(date('j F Y', strtotime($view))) . ' &middot; closing balance of ' . e(cb_month_label(cb_add_month($view, -1)));
}
$note = '';
if ($viewState === 'draft') {
    $note = e(cb_month_label($view)) . ' entries are in progress and are not saved yet.';
} elseif ($viewState === 'next') {
    $note = e(cb_month_label($view)) . ' entries have not been entered yet.';
}
$final = $viewState === 'final';

// members as they stood on the 1st of the month: those whose account was closed later are still listed
$q = $pdo->prepare(
    "SELECT m.id, m.member_no, m.full_name, m.status, u.login_id,
            (SELECT cl.id FROM member_closures cl WHERE cl.member_id = m.id AND cl.status = 'draft') AS closure_id,
            (SELECT cl2.cash_month FROM member_closures cl2 WHERE cl2.member_id = m.id AND cl2.status = 'final') AS closed_in
       FROM members m
  LEFT JOIN users u ON u.member_id = m.id
      WHERE m.status = 'active'
         OR EXISTS (SELECT 1 FROM member_closures c3 WHERE c3.member_id = m.id AND c3.status = 'final' AND c3.cash_month >= :v)
   ORDER BY m.member_no"
);
$q->execute([':v' => $view]);
$members = $q->fetchAll();

$bal    = get_balances($pdo, null, $through);   // the month being shown
$balNow = get_balances($pdo);                   // today's position, used for the action buttons

$tot = ['share' => 0.0, 'thrift' => 0.0, 'guarantee' => 0.0, 'mt' => 0.0, 'st' => 0.0];
$activeLoans = 0;
foreach ($members as $m) {
    $b = $bal[(int) $m['id']];
    foreach ($tot as $k => $_) {
        $tot[$k] += $b[$k];
    }
    $activeLoans += ($b['mt'] > 0 ? 1 : 0) + ($b['st'] > 0 ? 1 : 0);
}
$pending = (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'applied'")->fetchColumn();

admin_open($user, 'Secretary Portal');
show_flash();
?>

    <div class="month-banner">
      <h1><?= e($title) ?></h1>
      <span class="month-tag<?= $final ? ' final' : '' ?>"><?= $tag ?></span>
<?php if ($viewState === 'draft' || $viewState === 'next'): ?>
      <a class="btn btn-sm" href="admin-cashbook-entry.php?month=<?= e(cb_month_url($view)) ?>"><?= $viewState === 'draft' ? 'Continue' : 'Enter data for' ?> <?= e(cb_month_label($view)) ?></a>
<?php endif; ?>
      <?php if ($note !== ''): ?><p class="month-note"><?= $note ?></p><?php endif; ?>
    </div>
<?php admin_month_strip($states, $view, 'admin-dashboard.php', $openDay, 'balances'); ?>

    <section class="admin-stats">
      <div class="stat-box">
        <span class="stat-box-label"><?= admin_icon('users') ?> TOTAL MEMBERS</span>
        <span class="stat-box-value"><?= count($members) ?></span>
      </div>
      <div class="stat-box">
        <span class="stat-box-label"><?= admin_icon('wallet') ?> ACTIVE LOANS</span>
        <span class="stat-box-value"><?= $activeLoans ?></span>
      </div>
      <div class="stat-box">
        <span class="stat-box-label"><?= admin_icon('trend') ?> PENDING APPROVALS</span>
        <span class="stat-box-value"><?= $pending ?></span>
      </div>
      <div class="stat-box">
        <span class="stat-box-label"><?= admin_icon('rupee') ?> TOTAL OUTSTANDING</span>
        <span class="stat-box-value">&#8377;<?= e(inr($tot['mt'] + $tot['st'])) ?></span>
      </div>
    </section>

<?php admin_tabs('members'); ?>

    <section class="admin-toolbar">
      <div class="admin-search">
        <?= admin_icon('search') ?>
        <input type="text" id="memberSearch" placeholder="Search by name, membership no, userid">
      </div>
      <button type="button" class="admin-add-btn" disabled title="Coming in a later stage">
        <?= admin_icon('plus') ?> Add Member
      </button>
    </section>

    <section class="admin-table-wrap">
      <table class="admin-table" id="membersTable">
        <thead>
          <tr>
            <th>Member No.</th>
            <th>Name</th>
            <th class="col-right">Share</th>
            <th class="col-right">Thrift</th>
            <th class="col-right">Guarantee</th>
            <th class="col-right">MT Out.</th>
            <th class="col-right">ST Out.</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($members as $m):
        $b   = $bal[(int) $m['id']];
        $now = $balNow[(int) $m['id']] ?? ['share' => 0, 'thrift' => 0, 'guarantee' => 0, 'mt' => 0, 'st' => 0];
        // Delete works only once every balance (today's position) is zero.
        $canDelete = abs($now['share']) < 0.005 && abs($now['thrift']) < 0.005 && abs($now['guarantee']) < 0.005
                  && abs($now['mt']) < 0.005 && abs($now['st']) < 0.005;
?>
          <tr>
            <td><span class="member-no"><?= e(display_member_no((int) $m['member_no'])) ?></span></td>
            <td>
              <span class="member-name"><?= e($m['full_name']) ?></span>
              <span class="member-email"><?= e($m['login_id'] ?? '') ?></span>
            </td>
            <td class="col-right"><?= e(inr($b['share'])) ?></td>
            <td class="col-right"><?= e(inr($b['thrift'])) ?></td>
            <td class="col-right"><?= $b['guarantee'] > 0 ? e(inr($b['guarantee'])) : '<span class="amount-none">&mdash;</span>' ?></td>
            <td class="col-right"><?= $b['mt'] > 0 ? '<span class="amount-due">' . e(inr($b['mt'])) . '</span>' : '<span class="amount-none">&mdash;</span>' ?></td>
            <td class="col-right"><?= $b['st'] > 0 ? '<span class="amount-due">' . e(inr($b['st'])) . '</span>' : '<span class="amount-none">&mdash;</span>' ?></td>
            <td class="col-actions">
<?php   if ($m['status'] !== 'active'): ?>
              <span class="month-tag">closed in <?= e(cb_month_label((string) $m['closed_in'])) ?></span>
<?php   elseif ($m['closure_id'] !== null): ?>
              <a class="action-deposit" href="admin-member-close.php?closure=<?= (int) $m['closure_id'] ?>">Closure pending</a>
<?php   elseif ($openMonth === null): ?>
              <button type="button" class="action-deposit" disabled title="Every month up to now is already saved">Close account</button>
<?php   else: ?>
              <a class="action-deposit" href="admin-member-close.php?member_id=<?= (int) $m['id'] ?>" title="Retirement or transfer: refund share, thrift and guarantee fund">Close account</a>
<?php   endif; ?>
              <form method="post" action="admin-member-delete.php" class="inline-form js-delete" data-name="<?= e($m['full_name']) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                <button type="submit" class="action-icon action-icon-danger"
                        aria-label="Delete <?= e($m['full_name']) ?>"
                        <?= $canDelete ? '' : 'disabled' ?>
                        title="<?= $canDelete ? 'Delete this member' : 'Share, thrift, guarantee, MT and ST balances must all be zero first' ?>"><?= admin_icon('delete') ?></button>
              </form>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:700;background:#f4f6fb">
            <td colspan="2" style="padding:16px 20px">TOTAL</td>
            <td class="col-right" style="padding:16px 20px"><?= e(inr($tot['share'])) ?></td>
            <td class="col-right" style="padding:16px 20px"><?= e(inr($tot['thrift'])) ?></td>
            <td class="col-right" style="padding:16px 20px"><?= e(inr($tot['guarantee'])) ?></td>
            <td class="col-right" style="padding:16px 20px"><?= e(inr($tot['mt'])) ?></td>
            <td class="col-right" style="padding:16px 20px"><?= e(inr($tot['st'])) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </section>

<?php
admin_close('<script>
  // Search: hide rows that do not contain the typed text.
  document.getElementById("memberSearch").addEventListener("input", function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll("#membersTable tbody tr").forEach(function (row) {
      row.style.display = row.textContent.toLowerCase().indexOf(q) === -1 ? "none" : "";
    });
  });

  // Ask before deleting a member.
  document.querySelectorAll("form.js-delete").forEach(function (f) {
    f.addEventListener("submit", function (ev) {
      if (!confirm("Delete " + f.dataset.name + " from the member list?\n\nTheir past entries are kept in the ledgers, but they will no longer appear in the list or be able to sign in.")) {
        ev.preventDefault();
      }
    });
  });
</script>');
