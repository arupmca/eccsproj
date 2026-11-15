<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ledger-lib.php';

// Members see only their own books: the member comes from the sign-in, never from the address.
$user     = require_login('member');
$memberId = (int) $user['member_id'];
$pdo      = db();

$types  = lg_types();
$labels = array_map(fn ($t) => str_replace(' Ledger', '', $t['title']), $types) + ['payments' => 'All payments'];
$type   = isset($labels[$_GET['type'] ?? '']) ? (string) $_GET['type'] : 'payments';

$years = lg_fy_options($pdo);
$first = (string) cb_first_month($pdo);
$latestFinal = latest_final_month($pdo);
$fy = (int) ($_GET['fy'] ?? 0);
if (!in_array($fy, $years, true)) {
    $fy = $latestFinal !== null ? lg_fy_year($latestFinal) : ($years[0] ?? lg_fy_year(date('Y-m-d')));
    if ($years && !in_array($fy, $years, true)) { $fy = $years[0]; }
}
$through = $years ? lg_opening_through($pdo, $fy) : null;
$data    = $years ? lg_data($pdo, $fy, $memberId) : ['final' => [], 'coll' => [], 'loans' => [], 'refund' => [], 'fi' => []];

$q = static fn (array $extra = []): string => 'member-ledgers.php?' . http_build_query(array_merge(['type' => $type, 'fy' => $fy], $extra));
$amt = static fn (float $v): string => abs($v) < 0.005 ? '&mdash;' : e(rs($v));
$openLabel = lg_fy_start($fy) <= $first
    ? 'Opening balance as on ' . date('j F Y', strtotime((string) opening_date($pdo)))
    : 'Opening balance as on 1 April ' . $fy;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My ledgers | Sadya High School Employees' Credit Co-operative Society Ltd.</title>
<link rel="icon" href="images/logo.png" type="image/png">
<style>
  :root { --navy:#1f3864; --blue:#2f5fa8; --red:#d0342c; --grey:#5a6472; --line:#dbe3ee; --bg:#f4f7fb; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:"Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; background:var(--bg); color:#1d2733; line-height:1.5; }
  header { background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:12px 32px; }
  .brand { display:flex; align-items:center; gap:12px; }
  .brand img { width:44px; height:44px; object-fit:contain; }
  .brand b { display:block; color:var(--navy); }
  .brand small { color:var(--grey); font-size:11px; }
  nav { display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
  nav a { color:var(--navy); font-weight:600; font-size:14px; text-decoration:none; }
  nav a:hover, nav a.on { color:var(--blue); }
  main { max-width:1000px; margin:0 auto; padding:28px 20px 56px; }
  h1 { margin:0 0 2px; color:var(--navy); font-size:26px; }
  .sub { margin:0 0 18px; color:var(--grey); }
  .chips { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
  .chips a { text-decoration:none; padding:7px 16px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--navy); font-weight:600; font-size:14px; }
  .chips a.on { background:var(--navy); color:#fff; border-color:var(--navy); }
  .controls { display:flex; flex-wrap:wrap; gap:12px; align-items:end; margin:0 0 16px; }
  .controls label { display:block; font-weight:600; font-size:12px; color:var(--grey); margin-bottom:4px; }
  .controls select { padding:8px 10px; font:inherit; border:1px solid var(--line); border-radius:8px; background:#fff; min-width:220px; }
  .btn { font:inherit; font-weight:700; font-size:13px; padding:8px 18px; border-radius:999px; border:1.5px solid var(--navy); background:#fff; color:var(--navy); cursor:pointer; }
  .panel { background:#fff; border:1px solid var(--line); border-radius:12px; margin-bottom:20px; overflow:hidden; }
  .panel h2 { margin:0; padding:14px 18px; font-size:16px; color:var(--navy); border-bottom:1px solid var(--line); }
  table { width:100%; border-collapse:collapse; font-size:14px; font-variant-numeric:tabular-nums; }
  th, td { padding:9px 12px; text-align:right; border-bottom:1px solid var(--line); white-space:nowrap; }
  th:first-child, td:first-child { text-align:left; }
  thead th { background:var(--navy); color:#fff; font-size:12px; font-weight:600; }
  tfoot td { font-weight:700; background:#f4f6fb; }
  tr.open td { background:#f4f6fb; font-weight:600; }
  td.pend { color:#9aa3b2; font-style:italic; text-align:center; }
  .note { color:var(--grey); font-size:13px; margin:0 0 20px; }
  .warn { background:#fff8e6; border:1px solid #ecd28a; color:#7a5700; border-radius:8px; padding:11px 16px; margin-bottom:14px; }
  .scroll { overflow-x:auto; }
  @media (max-width:700px) { header { padding:12px 16px; } th, td { padding:8px 8px; font-size:13px; } }
  @media print { header, .chips, .controls, .noprint { display:none !important; } body { background:#fff; } .panel { border:1px solid #999; } }
</style>
</head>
<body>

<header>
  <div class="brand">
    <img src="images/logo.png" alt="Society logo" onerror="this.style.display='none'">
    <div><b>SADYA HIGH SCHOOL</b><small>EMPLOYEES' CREDIT CO-OPERATIVE SOCIETY LTD.</small></div>
  </div>
  <nav aria-label="Main">
    <a href="member-dashboard.php">My account</a>
    <a href="member-ledgers.php" class="on">My ledgers</a>
    <a href="emi-calculator.html">EMI Calculator</a>
    <a href="change-password.php">Change password</a>
    <a href="logout.php">Sign out</a>
  </nav>
</header>

<main>
  <h1>My ledgers</h1>
  <p class="sub"><?= e($user['name']) ?> &middot; <?= e(display_member_no((int) $user['member_no'])) ?> &middot; month-by-month history for each financial year (April to March)</p>

<?php if (!$years): ?>
  <div class="warn">No records have been loaded yet.</div>
<?php else: ?>
  <div class="chips" role="navigation" aria-label="Ledgers">
<?php   foreach ($labels as $k => $label): ?>
    <a href="<?= e($q(['type' => $k])) ?>"<?= $k === $type ? ' class="on"' : '' ?>><?= e($label) ?></a>
<?php   endforeach; ?>
  </div>

  <form class="controls" method="get" action="member-ledgers.php" id="fyForm">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div>
      <label for="fy">Financial year</label>
      <select id="fy" name="fy">
<?php   foreach ($years as $y): ?>
        <option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= e(lg_fy_label($y)) ?> (April <?= $y ?> &ndash; March <?= $y + 1 ?>)</option>
<?php   endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn" type="submit">Show</button></noscript>
    <button class="btn noprint" type="button" onclick="window.print()">Print</button>
  </form>

<?php   if ($through === null): ?>
  <div class="warn">Earlier months are not saved yet, so this year&rsquo;s figures are not available.</div>
<?php   elseif ($type === 'payments'):
        $paid = ['thrift' => 0.0, 'share' => 0.0, 'mt' => 0.0, 'st' => 0.0, 'guarantee' => 0.0, 'other' => 0.0, 'total' => 0.0];
        $got  = [];
?>
  <section class="panel">
    <h2>What I paid &mdash; <?= e(lg_fy_label($fy)) ?></h2>
    <div class="scroll"><table>
      <thead><tr><th>Month</th><th>Date</th><th>Thrift fund</th><th>Share</th><th>MT loan<br>(principal + interest)</th><th>ST loan<br>(principal + interest)</th><th>Guarantee fund</th><th>Other</th><th>Total paid</th></tr></thead>
      <tbody>
<?php   foreach (lg_months($fy) as $m):
            if ($m < $first) { continue; }
            if (!isset($data['final'][$m])): ?>
        <tr><td><?= e(cb_month_label($m)) ?></td><td class="pend" colspan="8">not entered yet</td></tr>
<?php       continue; endif;
            $c = $data['coll'][$memberId][$m] ?? [];
            $row = ['thrift' => (float) ($c['thrift'] ?? 0), 'share' => (float) ($c['share'] ?? 0),
                    'mt' => (float) ($c['mt_principal'] ?? 0) + (float) ($c['mt_interest'] ?? 0),
                    'st' => (float) ($c['st_principal'] ?? 0) + (float) ($c['st_interest'] ?? 0),
                    'guarantee' => (float) ($c['guarantee'] ?? 0), 'other' => (float) ($c['admission'] ?? 0) + (float) ($c['others'] ?? 0)];
            $row['total'] = array_sum($row);
            foreach ($row as $k => $v) { $paid[$k] += $v; }
            $given = ['loans' => (float) ($data['loans'][$memberId]['MT'][$m] ?? 0) + (float) ($data['loans'][$memberId]['ST'][$m] ?? 0),
                      'refunds' => array_sum($data['refund'][$memberId][$m] ?? []),
                      'interest' => array_sum($data['fi'][$memberId][$m] ?? [])];
            if (array_sum($given) > 0) { $got[$m] = $given; }
?>
        <tr>
          <td><?= e(cb_month_label($m)) ?></td>
          <td><?= $data['final'][$m] ? e(date('j M', strtotime($data['final'][$m]))) : '' ?></td>
<?php       foreach (['thrift', 'share', 'mt', 'st', 'guarantee', 'other'] as $k): ?><td><?= $amt($row[$k]) ?></td><?php endforeach; ?>
          <td><strong><?= $amt($row['total']) ?></strong></td>
        </tr>
<?php   endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="2">Total for the year</td>
<?php   foreach (['thrift', 'share', 'mt', 'st', 'guarantee', 'other', 'total'] as $k): ?><td><?= $amt($paid[$k]) ?></td><?php endforeach; ?>
      </tr></tfoot>
    </table></div>
  </section>

<?php   if ($got): ?>
  <section class="panel">
    <h2>What the society paid or credited to me &mdash; <?= e(lg_fy_label($fy)) ?></h2>
    <div class="scroll"><table>
      <thead><tr><th>Month</th><th>Loans given</th><th>Refunds</th><th>Interest credited</th></tr></thead>
      <tbody>
<?php       foreach ($got as $m => $g): ?>
        <tr><td><?= e(cb_month_label($m)) ?></td><td><?= $amt($g['loans']) ?></td><td><?= $amt($g['refunds']) ?></td><td><?= $amt($g['interest']) ?></td></tr>
<?php       endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php   endif; ?>
  <p class="note">Each month appears once the Secretary has saved it. Loan instalments are shown as the total paid (principal plus interest); the loan ledgers show the split.</p>

<?php   else:
        $cfg  = $types[$type];
        $open = get_balances($pdo, $memberId, $through)[$memberId][$cfg['key']] ?? 0.0;
        $L    = lg_build($type, $data, $memberId, (float) $open, $fy, $first);
        $split = lg_opening_split($pdo, $fy, $memberId, $type);
?>
  <section class="panel">
    <h2><?= e($cfg['title']) ?> &mdash; <?= e(lg_fy_label($fy)) ?></h2>
    <div class="scroll"><table>
      <thead><tr><th>Month</th><th>Date</th><th>Opening</th>
<?php   foreach ($L['cols'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
        <th>Closing</th></tr></thead>
      <tbody>
        <tr class="open">
          <td colspan="2"><?= e($openLabel) ?><?php if ($split !== null): ?>
            <span style="font-weight:400;color:var(--grey)"> (principal <?= e(rs($split['principal'])) ?> + interest <?= e(rs($split['interest'])) ?>)</span><?php endif; ?></td>
          <td><?= $amt($L['opening']) ?></td>
          <td colspan="<?= count($L['cols']) ?>"></td>
          <td><?= $amt($L['opening']) ?></td>
        </tr>
<?php   foreach ($L['rows'] as $r): ?>
<?php     if ($r['final']): ?>
        <tr>
          <td><?= e(cb_month_label($r['month'])) ?></td>
          <td><?= $r['date'] ? e(date('j M', strtotime($r['date']))) : '' ?></td>
          <td><?= $amt($r['opening']) ?></td>
<?php       foreach ($L['cols'] as $k => $_): ?><td><?= $amt($r['v'][$k] ?? 0) ?></td><?php endforeach; ?>
          <td><strong><?= $amt($r['closing']) ?></strong></td>
        </tr>
<?php     else: ?>
        <tr><td><?= e(cb_month_label($r['month'])) ?></td><td class="pend" colspan="<?= 3 + count($L['cols']) ?>">not entered yet</td></tr>
<?php     endif; ?>
<?php   endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="3">Total for the year</td>
<?php   foreach ($L['cols'] as $k => $_): ?><td><?= $amt($L['totals'][$k]) ?></td><?php endforeach; ?>
        <td><?= $amt($L['closing']) ?></td></tr></tfoot>
    </table></div>
  </section>
  <p class="note">Opening + additions &minus; reductions = closing, month by month. A month appears once the Secretary has saved it.</p>
<?php   endif; ?>
<?php endif; ?>
</main>

<script>
  var s = document.getElementById("fy");
  if (s) { s.addEventListener("change", function () { document.getElementById("fyForm").submit(); }); }
</script>
</body>
</html>
