<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ledger-lib.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

$types  = lg_types();
$labels = array_map(fn ($t) => $t['title'], $types) + ['general' => 'General Ledger'];
$type   = isset($labels[$_GET['type'] ?? '']) ? (string) $_GET['type'] : 'mt';

// ---- financial year
$years = lg_fy_options($pdo);
if (!$years) {
    admin_open($user, 'Ledgers');
    admin_tabs('ledgers');
    echo '<section class="rep-wrap"><p style="margin:0;padding:18px">No opening balances have been loaded yet.</p></section>';
    admin_close();
    exit;
}
$latestFinal = latest_final_month($pdo);
$fy = (int) ($_GET['fy'] ?? 0);
if (!in_array($fy, $years, true)) {
    $fy = $latestFinal !== null ? lg_fy_year($latestFinal) : $years[0];
    if (!in_array($fy, $years, true)) { $fy = $years[0]; }
}
$first    = (string) cb_first_month($pdo);
$fyStart  = lg_fy_start($fy);
$through  = lg_opening_through($pdo, $fy);

// ---- members
$members = lg_members($pdo);
$byId = [];
foreach ($members as $m) { $byId[(int) $m['id']] = $m; }
$memberParam = (string) ($_GET['member'] ?? 'all');
$memberId = ($memberParam !== 'all' && isset($byId[(int) $memberParam])) ? (int) $memberParam : null;

$q = static fn (array $extra = []): string => 'admin-ledgers.php?' . http_build_query(array_merge(['type' => $type, 'fy' => $fy, 'member' => $memberId ?? 'all'], $extra));

$openLabel = $fyStart <= $first
    ? 'Opening balance as on ' . date('j F Y', strtotime((string) opening_date($pdo)))
    : 'Opening balance as on 1 April ' . $fy;

$css = <<<CSS
  .lg-controls { display:flex; flex-wrap:wrap; gap:12px 18px; align-items:end; margin:0 0 16px; }
  .lg-controls label { display:block; font-weight:600; font-size:.8rem; margin-bottom:4px; color:var(--text-muted); }
  .lg-controls select { padding:8px 10px; font:inherit; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:#fff; min-width:190px; }
  .lg-types { display:flex; flex-wrap:wrap; gap:8px; margin:6px 0 16px; }
  .lg-types a { text-decoration:none; }
  .lg-head { display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; align-items:end; margin:0 0 10px; }
  .lg-head h1 { margin:0; font-size:1.5rem; color:var(--navy); }
  .lg-head p { margin:2px 0 0; color:var(--text-muted); font-size:.9rem; }
  .lg-nav a { color:var(--blue); font-weight:600; text-decoration:none; margin-left:14px; font-size:.88rem; }
  table.rep td.pend { color:#9aa3b2; font-style:italic; text-align:center; }
  table.rep tr.sec td { background:#e8eef9; color:var(--navy); font-weight:700; text-align:left; font-size:.8rem; letter-spacing:.03em; }
  table.rep tr.open td { background:#f4f6fb; font-weight:600; }
  table.rep a { color:var(--blue); font-weight:600; text-decoration:none; }
  .okline { color:#1e6a3a; font-weight:600; }
  .badline { color:var(--red); font-weight:600; }
  @media print {
    .admin-header, .admin-tabs, .lg-controls, .lg-types, .lg-nav, .btn-row, .noprint { display:none !important; }
    .rep-wrap { box-shadow:none; border:1px solid #999; }
    body { background:#fff; }
  }
CSS;

admin_open($user, $labels[$type], $css);
admin_tabs('ledgers');

$amt = static fn (float $v): string => abs($v) < 0.005 ? '&mdash;' : e(rs($v));
?>

    <div class="lg-types" role="navigation" aria-label="Ledgers">
<?php foreach ($labels as $k => $title): ?>
      <a class="mchip<?= $k === $type ? ' active' : '' ?>" href="<?= e($q(['type' => $k, 'account' => null])) ?>"><?= e(str_replace(' Ledger', '', $title)) ?></a>
<?php endforeach; ?>
    </div>

    <form class="lg-controls" method="get" action="admin-ledgers.php" id="lgForm">
      <input type="hidden" name="type" value="<?= e($type) ?>">
<?php if ($type !== 'general'): ?>
      <div>
        <label for="member">Member</label>
        <select id="member" name="member">
          <option value="all"<?= $memberId === null ? ' selected' : '' ?>>All members (summary)</option>
<?php   foreach ($members as $m): ?>
          <option value="<?= (int) $m['id'] ?>"<?= $memberId === (int) $m['id'] ? ' selected' : '' ?>><?= e(display_member_no((int) $m['member_no']) . ' ' . $m['full_name'] . ($m['status'] === 'inactive' ? ' (closed)' : '')) ?></option>
<?php   endforeach; ?>
        </select>
      </div>
<?php endif; ?>
      <div>
        <label for="fy">Financial year</label>
        <select id="fy" name="fy">
<?php foreach ($years as $y): ?>
          <option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= e(lg_fy_label($y)) ?> (April <?= $y ?> &ndash; March <?= $y + 1 ?>)</option>
<?php endforeach; ?>
        </select>
      </div>
      <noscript><button class="btn" type="submit">Show</button></noscript>
    </form>

<?php
// =================================================================== general ledger
if ($type === 'general'):
    $gl  = lg_general($pdo, $fy);
    $key = (string) ($_GET['account'] ?? '');
    $acc = $gl['accounts'][$key] ?? null;
    $dc  = static fn (float $v, string $nature): string => abs($v) < 0.005 ? e(rs(0)) : e(rs(abs($v))) . ($v >= 0 ? ($nature === 'dr' ? ' Dr' : ' Cr') : ($nature === 'dr' ? ' Cr' : ' Dr'));

    if ($acc !== null): ?>
    <div class="lg-head">
      <div><h1><?= e($acc['name']) ?></h1><p>General ledger &middot; financial year <?= e(lg_fy_label($fy)) ?></p></div>
      <div class="lg-nav"><a href="<?= e($q(['type' => 'general', 'account' => null])) ?>">&larr; All accounts</a>
        <a href="#" onclick="window.print();return false;">Print</a></div>
    </div>
    <section class="rep-wrap">
      <table class="rep">
        <thead><tr><th>Month</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
        <tbody>
          <tr class="open"><td class="l"><?= e($openLabel) ?></td><td></td><td></td><td><?= $dc($acc['opening'], $acc['nature']) ?></td></tr>
<?php   foreach ($acc['rows'] as $r): ?>
<?php     if ($r['final']): ?>
          <tr><td class="l"><?= e(cb_month_label($r['month'])) ?></td><td><?= $amt($r['dr']) ?></td><td><?= $amt($r['cr']) ?></td><td><?= $dc($r['closing'], $acc['nature']) ?></td></tr>
<?php     else: ?>
          <tr><td class="l"><?= e(cb_month_label($r['month'])) ?></td><td class="pend" colspan="3">not entered yet</td></tr>
<?php     endif; ?>
<?php   endforeach; ?>
        </tbody>
        <tfoot><tr><td class="l">Total for the year</td><td><?= $amt($acc['dr']) ?></td><td><?= $amt($acc['cr']) ?></td><td><?= $dc($acc['closing'], $acc['nature']) ?></td></tr></tfoot>
      </table>
    </section>
<?php
    else: ?>
    <div class="lg-head">
      <div><h1>General Ledger</h1><p>All accounts &middot; financial year <?= e(lg_fy_label($fy)) ?> &middot; built from the saved months of the cash book</p></div>
      <div class="lg-nav"><a href="#" onclick="window.print();return false;">Print</a></div>
    </div>
<?php   if (!$gl['opening_known']): ?>
    <div class="warn">The earlier months of the previous year are not saved yet, so the opening balances for this year are not known. Only movements are shown.</div>
<?php   endif; ?>
    <section class="rep-wrap">
      <table class="rep">
        <thead><tr><th>Account</th><th>Opening</th><th>Debit</th><th>Credit</th><th>Closing</th></tr></thead>
        <tbody>
<?php   $section = '';
        foreach ($gl['accounts'] as $a):
            if ($a['section'] !== $section): $section = $a['section']; ?>
          <tr class="sec"><td colspan="5"><?= e($section) ?></td></tr>
<?php       endif; ?>
          <tr>
            <td class="l"><a href="<?= e($q(['type' => 'general', 'account' => $a['key']])) ?>"><?= e($a['name']) ?></a></td>
            <td><?= $dc($a['opening'], $a['nature']) ?></td>
            <td><?= $amt($a['dr']) ?></td>
            <td><?= $amt($a['cr']) ?></td>
            <td><?= $dc($a['closing'], $a['nature']) ?></td>
          </tr>
<?php   endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td class="l">Total of the year&rsquo;s postings</td><td></td><td><?= e(rs($gl['total_dr'])) ?></td><td><?= e(rs($gl['total_cr'])) ?></td><td></td></tr>
        </tfoot>
      </table>
    </section>
<?php   $balanced = abs($gl['total_dr'] - $gl['total_cr']) < 0.005; ?>
    <p class="<?= $balanced ? 'okline' : 'badline' ?>"><?= $balanced
        ? 'Debits equal credits: the books balance for the postings of this year.'
        : 'Debits and credits differ by ' . e(rs(abs($gl['total_dr'] - $gl['total_cr']))) . '. Please tell Claude.' ?></p>
    <p style="color:var(--text-muted);font-size:.85rem">Cash in hand starts from the opening cash in hand set under Settings, and its closing balance is the cash in hand shown in the Cash Book.
      Bank shows only the year&rsquo;s movement: the opening bank balance is not in the system yet.
      Interest on thrift and guarantee fund appears here when it is credited (at account closure now; at year end in a later stage).</p>
<?php
    endif;

// =================================================================== member ledgers
else:
    $cfg = $types[$type];
    $keyBal = $cfg['key'];

    if ($through === null): ?>
    <div class="warn">The months before April <?= $fy ?> are not saved yet, so the opening balance for <?= e(lg_fy_label($fy)) ?> is not known.</div>
<?php
    elseif ($memberId !== null):
        $m    = $byId[$memberId];
        $data = lg_data($pdo, $fy, $memberId);
        $opening = get_balances($pdo, $memberId, $through)[$memberId][$keyBal] ?? 0.0;
        $L    = lg_build($type, $data, $memberId, (float) $opening, $fy, $first);
        $split = lg_opening_split($pdo, $fy, $memberId, $type);

        $ids = array_keys($byId);
        $pos = array_search($memberId, $ids, true);
        $prev = $pos > 0 ? $ids[$pos - 1] : null;
        $next = $pos < count($ids) - 1 ? $ids[$pos + 1] : null;
?>
    <div class="lg-head">
      <div>
        <h1><?= e($cfg['title']) ?></h1>
        <p><?= e(display_member_no((int) $m['member_no']) . ' ' . $m['full_name']) ?><?= $m['status'] === 'inactive' ? ' (account closed)' : '' ?> &middot; financial year <?= e(lg_fy_label($fy)) ?></p>
      </div>
      <div class="lg-nav">
<?php   if ($prev !== null): ?><a href="<?= e($q(['member' => $prev])) ?>">&larr; Previous member</a><?php endif; ?>
<?php   if ($next !== null): ?><a href="<?= e($q(['member' => $next])) ?>">Next member &rarr;</a><?php endif; ?>
        <a href="<?= e($q(['member' => 'all'])) ?>">All members</a>
        <a href="#" onclick="window.print();return false;">Print</a>
      </div>
    </div>

    <section class="rep-wrap">
      <table class="rep">
        <thead>
          <tr><th>Month</th><th>Date</th><th>Opening</th>
<?php   foreach ($L['cols'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
            <th>Closing</th></tr>
        </thead>
        <tbody>
          <tr class="open">
            <td class="l" colspan="2"><?= e($openLabel) ?><?php if ($split !== null): ?>
              <span style="font-weight:400;color:var(--text-muted)"> (principal <?= e(rs($split['principal'])) ?> + interest <?= e(rs($split['interest'])) ?>)</span><?php endif; ?></td>
            <td><?= $amt($L['opening']) ?></td>
            <td colspan="<?= count($L['cols']) ?>"></td>
            <td><?= $amt($L['opening']) ?></td>
          </tr>
<?php   foreach ($L['rows'] as $r): ?>
<?php     if ($r['final']): ?>
          <tr>
            <td class="l"><?= e(cb_month_label($r['month'])) ?></td>
            <td><?= $r['date'] ? e(date('j M', strtotime($r['date']))) : '' ?></td>
            <td><?= $amt($r['opening']) ?></td>
<?php       foreach ($L['cols'] as $k => $_): ?><td><?= $amt($r['v'][$k] ?? 0) ?></td><?php endforeach; ?>
            <td><strong><?= $amt($r['closing']) ?></strong></td>
          </tr>
<?php     else: ?>
          <tr><td class="l"><?= e(cb_month_label($r['month'])) ?></td><td class="pend" colspan="<?= 3 + count($L['cols']) ?>">not entered yet</td></tr>
<?php     endif; ?>
<?php   endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td class="l" colspan="3">Total for the year</td>
<?php   foreach ($L['cols'] as $k => $_): ?><td><?= $amt($L['totals'][$k]) ?></td><?php endforeach; ?>
            <td><?= $amt($L['closing']) ?></td></tr>
        </tfoot>
      </table>
    </section>
<?php
    else: // ---- all members: one line each
        $data = lg_data($pdo, $fy, null);
        $open = get_balances($pdo, null, $through);
        $sumOpen = 0.0; $sumClose = 0.0; $sumCols = array_fill_keys(array_keys($cfg['cols']), 0.0);
?>
    <div class="lg-head">
      <div><h1><?= e($cfg['title']) ?></h1><p>All members &middot; financial year <?= e(lg_fy_label($fy)) ?> &middot; choose a member to see the month-by-month ledger</p></div>
      <div class="lg-nav"><a href="#" onclick="window.print();return false;">Print</a></div>
    </div>
    <section class="rep-wrap">
      <table class="rep">
        <thead>
          <tr><th>Member</th><th>Opening</th>
<?php   foreach ($cfg['cols'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
            <th>Closing</th></tr>
        </thead>
        <tbody>
<?php   foreach ($members as $m):
            $id = (int) $m['id'];
            $L  = lg_build($type, $data, $id, (float) ($open[$id][$keyBal] ?? 0), $fy, $first);
            $quiet = abs($L['opening']) < 0.005 && abs($L['closing']) < 0.005 && !array_filter($L['totals'], fn ($x) => abs($x) >= 0.005);
            $sumOpen += $L['opening']; $sumClose += $L['closing'];
            foreach ($L['totals'] as $k => $x) { $sumCols[$k] += $x; }
?>
          <tr<?= $quiet ? ' style="opacity:.55"' : '' ?>>
            <td class="l"><a href="<?= e($q(['member' => $id])) ?>"><?= e(display_member_no((int) $m['member_no']) . ' ' . $m['full_name']) ?></a><?= $m['status'] === 'inactive' ? ' <span class="month-tag">closed</span>' : '' ?></td>
            <td><?= $amt($L['opening']) ?></td>
<?php       foreach ($L['cols'] as $k => $_): ?><td><?= $amt($L['totals'][$k]) ?></td><?php endforeach; ?>
            <td><strong><?= $amt($L['closing']) ?></strong></td>
          </tr>
<?php   endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td class="l">TOTAL</td><td><?= e(rs($sumOpen)) ?></td>
<?php   foreach ($sumCols as $x): ?><td><?= e(rs($x)) ?></td><?php endforeach; ?>
            <td><?= e(rs($sumClose)) ?></td></tr>
        </tfoot>
      </table>
    </section>
<?php
    endif;
endif;

admin_close('<script>
  // Changing the member or the year opens the new view straight away.
  document.querySelectorAll("#lgForm select").forEach(function (s) {
    s.addEventListener("change", function () { document.getElementById("lgForm").submit(); });
  });
</script>');
