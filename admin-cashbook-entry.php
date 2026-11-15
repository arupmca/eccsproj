<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cashbook-lib.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

// The month being entered now, or any month that is already saved (to correct it). Never a skipped month.
$month = cb_month_param((string) ($_GET['month'] ?? ''));
$state = null;
foreach (cb_month_states($pdo) as $s) {
    if ($s['month'] === $month) {
        $state = $s['state'];
    }
}
if ($month === null || !in_array($state, ['final', 'draft', 'next'], true)) {
    $open = cb_open_month($pdo);
    set_flash('err', $open === null
        ? 'That month cannot be opened.'
        : 'Only ' . cb_month_label($open['month']) . ' can be entered now, and saved months can be corrected. Months cannot be skipped.');
    redirect('admin-dashboard.php');
}
$isFinal = $state === 'final';

$ctx   = cb_ctx($pdo, $month);
$meta  = cb_meta($pdo, $month);
$saved = cb_saved_rows($pdo, $month);
$prev  = $isFinal ? [] : cb_prev_totals($pdo, $month);
$today = date('Y-m-d');
$date  = (string) ($meta['entry_date'] ?? '') !== '' ? (string) $meta['entry_date'] : min(substr($month, 0, 8) . '10', $today);
$dateSaved = (string) ($meta['entry_date'] ?? '');

$closureOf = [];
$q = $pdo->prepare('SELECT id, member_id FROM member_closures WHERE cash_month = :m');
$q->execute([':m' => $month]);
foreach ($q->fetchAll() as $r) {
    $closureOf[(int) $r['member_id']] = (int) $r['id'];
}

$stored   = cb_parsed_from_db($pdo, $month);
$payRows  = [];
foreach ($stored['payments'] as $p) {
    $payRows[] = ['head' => $p['head_id'], 'member' => (int) $p['member_id'], 'amount' => cb_fmt_input($p['amount']),
                  'date' => $p['date'], 'note' => $p['narration']];
}
while (count($payRows) < 3) {
    $payRows[] = ['head' => 0, 'member' => 0, 'amount' => '', 'date' => '', 'note' => ''];
}
$bankRows = [];
foreach ($stored['bank_lines'] as $b) {
    $bankRows[] = ['date' => $b['date'], 'amount' => cb_fmt_input($b['amount']), 'note' => $b['narration']];
}
while (count($bankRows) < 1) {
    $bankRows[] = ['date' => '', 'amount' => '', 'note' => ''];
}
$minDate = $month;
$maxDate = min(cb_month_end($month), $today);

// cash in hand: the opening figure, and what closing accounts (settled separately) already add or take out
$cashOpening = cb_cash_opening($pdo, $month);
$q = $pdo->prepare('SELECT COALESCE(SUM(thrift + share + mt_principal + mt_interest + st_principal + st_interest + admission + guarantee + others), 0)
                      FROM collections WHERE collection_month = :m AND closure_id IS NOT NULL');
$q->execute([':m' => $month]);
$closureIn = (float) $q->fetchColumn();
$q = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM cash_book_entries WHERE cash_month = :m AND side = 'P' AND closure_id IS NOT NULL");
$q->execute([':m' => $month]);
$closureOut = (float) $q->fetchColumn();
$payHeads   = array_filter($ctx['heads'], fn ($h) => $h['side'] === 'P');
$payMembers = array_filter($ctx['members'], fn ($m) => !in_array($m['id'], $ctx['closing'], true));
$totalMembers = count($ctx['members']);
$savedCount = 0;
foreach ($ctx['members'] as $id => $_) {
    $savedCount += isset($saved[$id]) ? 1 : 0;
}

$css = <<<'CSS'
  .sheet-top { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:end; margin:0 0 14px; }
  .sheet-top label { display:block; font-weight:600; font-size:.8rem; margin-bottom:4px; color:var(--text-muted); }
  .sheet-top input[type=date], .field-in { padding:8px 10px; font:inherit; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:#fff; }
  .mini { font:inherit; font-weight:700; font-size:.8rem; padding:8px 14px; border-radius:var(--radius-sm); border:1px solid var(--navy); background:#fff; color:var(--navy); cursor:pointer; }
  .mini:hover { background:#eef2fa; }
  .mini[disabled] { opacity:.45; cursor:not-allowed; }
  .mini.primary { background:var(--navy); color:#fff; }
  .mini.primary:hover { background:var(--navy-dark); }
  .state-text { font-size:.82rem; color:var(--text-muted); align-self:center; }

  /* the sheet fits the page: no sideways scrolling */
  .sheet-wrap { background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); margin-bottom:22px; }
  table.sheet { width:100%; table-layout:fixed; border-collapse:collapse; font-size:.8rem; font-variant-numeric:tabular-nums; }
  table.sheet th, table.sheet td { padding:5px 4px; border-bottom:1px solid var(--border-color); text-align:right; vertical-align:middle; }
  table.sheet thead th { background:#0b1f5c; color:#fff; font-weight:700; font-size:.74rem; line-height:1.15; padding:9px 4px; text-align:center; position:sticky; top:0; z-index:2; }
  table.sheet td.sl { text-align:center; color:var(--text-muted); }
  table.sheet td.mem, table.sheet th.mem { text-align:left; padding-left:8px; font-weight:600; white-space:normal; overflow-wrap:anywhere; line-height:1.2; }
  table.sheet tbody tr:nth-child(odd) td { background:#f6f8fd; }
  table.sheet tbody tr[data-saved="0"] td.sl { box-shadow:inset 4px 0 0 #e0a100; }
  table.sheet tbody tr[data-saved="1"] td.sl { box-shadow:inset 4px 0 0 #2e9d5b; }
  table.sheet tbody tr[data-editing="1"] td { background:#fff8e6 !important; }
  table.sheet tbody tr.err td { background:#fdeceb !important; }
  table.sheet input.num { width:100%; min-width:0; box-sizing:border-box; padding:5px 4px; text-align:right; font:inherit; color:inherit; border:1px solid transparent; border-radius:5px; background:transparent; }
  table.sheet input.num[readonly] { cursor:default; }
  table.sheet input.num:not([readonly]) { border-color:var(--border-color); background:#fff; }
  table.sheet input.num:focus-visible { outline:3px solid #9dbbe6; border-color:var(--blue); }
  table.sheet td.none { color:#b5bcc9; text-align:center; }
  /* hover or focus on an interest box: how it was calculated; if you changed it, a red notice instead */
  table.sheet td.intt { position:relative; }
  table.sheet td.intt:hover::after, table.sheet td.intt:focus-within::after {
    content:attr(data-formula); position:absolute; right:0; bottom:calc(100% + 4px); width:max-content; max-width:340px;
    white-space:normal; text-align:left; background:#1d2733; color:#fff; padding:6px 10px; border-radius:6px;
    font-size:.76rem; font-weight:500; line-height:1.35; z-index:30; box-shadow:0 4px 14px rgba(0,0,0,.25); pointer-events:none; }
  table.sheet td.intt.over:hover::after, table.sheet td.intt.over:focus-within::after {
    content:"Rule overridden"; background:#fff; color:#d0342c; border:1px solid #d0342c; font-weight:700; }
  table.sheet td.intt.over input { color:#d0342c; font-weight:700; }
  table.sheet td.prin { color:var(--navy); font-weight:600; padding-right:6px; }
  table.sheet td.prin.bad { color:var(--red); background:#fbe6e5 !important; }
  table.sheet td.tot { font-weight:700; padding-right:6px; }
  table.sheet td.act { text-align:center; white-space:nowrap; }
  .rb { font:inherit; font-size:.72rem; font-weight:700; padding:4px 7px; border-radius:5px; border:1px solid var(--navy); background:#fff; color:var(--navy); cursor:pointer; }
  .rb.save { background:var(--navy); color:#fff; }
  .rb[disabled] { opacity:.4; cursor:not-allowed; }
  .st { display:block; font-size:.68rem; margin-top:3px; color:var(--text-muted); }
  .st.ok { color:#1e6a3a; font-weight:700; }
  .st.pend { color:#946a00; font-weight:700; }
  table.sheet tfoot td { background:#e8eef9; font-weight:700; padding:8px 4px; }
  table.sheet tfoot td:first-child { text-align:left; padding-left:8px; }
  table.sheet td.closure { text-align:left; color:var(--text-muted); font-style:italic; padding-left:8px; }

  .rs { position:relative; display:block; }
  .rs::before { content:"\20B9"; position:absolute; left:9px; top:50%; transform:translateY(-50%); color:var(--text-muted); pointer-events:none; font-size:.9rem; }
  .rs input { padding-left:24px !important; }
  .cashbar { display:flex; flex-wrap:wrap; gap:6px 26px; align-items:baseline; background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); padding:12px 20px; margin:0 0 14px; }
  .cashbar div { display:flex; flex-direction:column; }
  .cashbar span { font-size:.72rem; color:var(--text-muted); font-weight:600; letter-spacing:.02em; }
  .cashbar strong { font-size:1.05rem; color:var(--navy); font-variant-numeric:tabular-nums; }
  .cashbar strong.neg { color:var(--red); }
  .cashbar .eq { font-size:1.1rem; color:var(--text-muted); align-self:flex-end; padding-bottom:1px; }
  .panel2 { background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); padding:18px 20px; margin-bottom:22px; }
  .panel2 h2 { margin:0 0 4px; font-size:1rem; color:var(--navy); }
  .panel2 p.hint { margin:0 0 12px; color:var(--text-muted); font-size:.84rem; }
  table.pay { width:100%; border-collapse:collapse; font-size:.88rem; }
  table.pay th { text-align:left; font-size:.74rem; color:var(--text-muted); padding:4px 6px; }
  table.pay td { padding:4px 6px; }
  table.pay select, table.pay input { width:100%; padding:8px 10px; font:inherit; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:#fff; box-sizing:border-box; }
  table.pay input.amt { text-align:right; }
  .linkbtn { background:none; border:none; color:var(--red); cursor:pointer; font:inherit; font-weight:600; }
  .addrow { background:none; border:none; color:var(--blue); cursor:pointer; font:inherit; font-weight:700; padding:6px 0; }
  .finalbar { display:flex; flex-wrap:wrap; gap:12px 18px; align-items:center; background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); padding:14px 20px; margin-bottom:26px; }
  .finalbar form { margin:0; }
  #msg { margin-bottom:14px; }
  #msg ul { margin:6px 0 0; padding-left:20px; }

  /* narrow screens: each member becomes a small card instead of a wide row */
  @media (max-width: 1100px) {
    table.sheet, table.sheet tbody, table.sheet tr, table.sheet td { display:block; width:100%; }
    table.sheet thead, table.sheet tfoot { display:none; }
    table.sheet tbody tr { padding:10px 12px; border-bottom:6px solid #e8eef9; }
    table.sheet td { border:0; display:flex; justify-content:space-between; align-items:center; gap:10px; padding:3px 0; text-align:right; background:transparent !important; }
    table.sheet td::before { content:attr(data-label); font-weight:600; color:var(--text-muted); font-size:.76rem; text-align:left; }
    table.sheet td.sl { display:none; }
    table.sheet td.mem { font-size:1rem; padding-bottom:6px; }
    table.sheet td.mem::before { content:none; }
    table.sheet td.none { display:none; }
    table.sheet input.num { width:46%; }
    table.sheet td.act { justify-content:flex-start; gap:8px; padding-top:8px; }
    table.sheet td.act::before { content:none; }
  }
CSS;

admin_open($user, 'Cash Book entry', $css);
show_flash();
admin_tabs('cashbook');
?>

    <div class="month-banner" style="margin-top:6px">
      <h1>Cash Book &mdash; <?= e(cb_month_label($month)) ?></h1>
      <span class="month-tag<?= $isFinal ? ' final' : '' ?>"><?= $isFinal ? 'Saved month &middot; editing' : ($state === 'draft' ? 'Draft &middot; not final yet' : 'New entry') ?></span>
<?php if ($isFinal): ?>
      <p class="month-note">This month is already saved. Each <strong>Save</strong> takes effect immediately, but only if every later month still adds up
        (no repayment above the loan outstanding, no refund above a balance, no negative balance, closed accounts stay at zero);
        otherwise it is refused and nothing changes. Amounts recorded in later months are never altered automatically.</p>
<?php else: ?>
      <p class="month-note">Each row is pre-filled: thrift, the calculated interest and last month&rsquo;s instalment. Press <strong>Save</strong> on every member
        (or <strong>Edit</strong> first to change an amount). The <strong>Pr</strong> columns are always Total &minus; Intt.
        Hover over an <strong>Intt</strong> box to see how the interest was calculated (it turns red with &ldquo;Rule overridden&rdquo; if you change it).
        <strong>Finalize</strong> becomes available once every member, the date and any payments are saved. Nothing counts until the month is finalized.</p>
<?php endif; ?>
    </div>

    <div id="msg" role="status" aria-live="polite"></div>

    <div class="sheet-top">
      <div>
        <label for="date">Date of collection</label>
        <input type="date" id="date" value="<?= e($date) ?>" data-saved="<?= e($dateSaved) ?>"
               min="<?= e($month) ?>" max="<?= e(min(cb_month_end($month), $today)) ?>">
      </div>
      <button type="button" class="mini" id="saveDate">Save date</button>
      <span class="state-text" id="dateState"></span>
    </div>

    <div class="cashbar" id="cashbar" aria-label="Cash in hand"
         data-opening="<?= e((string) $cashOpening) ?>" data-closure-in="<?= e((string) $closureIn) ?>" data-closure-out="<?= e((string) $closureOut) ?>">
      <div><span>OPENING CASH IN HAND</span><strong id="cashOpen"><?= e(rs($cashOpening)) ?></strong></div>
      <span class="eq">+</span>
      <div><span>INCOME THIS MONTH</span><strong id="cashIn">&mdash;</strong></div>
      <span class="eq">&minus;</span>
      <div><span>EXPENSES THIS MONTH</span><strong id="cashOut">&mdash;</strong></div>
      <span class="eq">=</span>
      <div><span>CLOSING CASH IN HAND</span><strong id="cashClose">&mdash;</strong></div>
    </div>

    <div class="sheet-wrap">
      <table class="sheet" id="sheet" data-month="<?= e(cb_month_url($month)) ?>" data-url="admin-cashbook-save.php"
             data-csrf="<?= e(csrf_token()) ?>" data-final="<?= $isFinal ? '1' : '0' ?>" data-total="<?= $totalMembers ?>">
        <colgroup>
          <col style="width:3.5%"><col style="width:12.5%"><col style="width:6.5%"><col style="width:6.5%"><col style="width:6%"><col style="width:6.5%">
          <col style="width:6.5%"><col style="width:6%"><col style="width:6.5%"><col style="width:6.5%"><col style="width:6.5%"><col style="width:4.5%">
          <col style="width:5%"><col style="width:8%"><col style="width:9%">
        </colgroup>
        <thead>
          <tr>
            <th>Sl<br>No</th><th class="mem">Member</th><th>Thrift<br>Fund</th>
            <th>MT<br>Pr</th><th>MT<br>Intt</th><th>MT<br>Total</th>
            <th>ST<br>Pr</th><th>ST<br>Intt</th><th>ST<br>Total</th>
            <th>Share</th><th>G.Fund</th><th>Adm</th><th>Others</th><th>Row<br>Total</th><th></th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($ctx['members'] as $id => $m):
        if (in_array($id, $ctx['closing'], true)): ?>
          <tr id="m<?= $id ?>">
            <td class="sl" data-label="No"><?= (int) $m['no'] ?></td>
            <td class="mem" data-label="Member"><?= e($m['name']) ?></td>
            <td class="closure" colspan="13">Account closure, settled separately.
              <a href="admin-member-close.php?closure=<?= (int) ($closureOf[$id] ?? 0) ?>" style="color:var(--blue);font-weight:600;font-style:normal">View statement</a></td>
          </tr>
<?php   continue; endif;
        $sr       = $saved[$id] ?? null;
        $isSaved  = $sr !== null && $sr['closure_id'] === null;
        $v        = $isSaved ? cb_row_strings($sr) : cb_default_row($m, $ctx, $prev);
?>
          <tr id="m<?= $id ?>" data-member="<?= $id ?>" data-saved="<?= $isSaved ? '1' : '0' ?>" data-editing="0"
              data-open-mt="<?= e((string) $m['bal']['mt']) ?>" data-open-st="<?= e((string) $m['bal']['st']) ?>">
            <td class="sl" data-label="No"><?= (int) $m['no'] ?></td>
            <td class="mem" data-label="Member"><?= e($m['name']) ?></td>
            <td data-label="Thrift Fund"><input class="num" inputmode="decimal" readonly data-f="thrift" value="<?= e($v['thrift']) ?>" aria-label="Thrift fund, <?= e($m['name']) ?>"></td>
<?php   foreach (['mt' => 'MT', 'st' => 'ST'] as $t => $T): ?>
<?php     if ($m['bal'][$t] > 0): ?>
            <td class="prin" data-p="<?= $t ?>" data-label="<?= $T ?> Pr">&mdash;</td>
            <td class="intt" data-label="<?= $T ?> Intt" data-formula="<?= e((string) ($ctx['formula'][$id][$t] ?? '')) ?>"><input class="num" inputmode="decimal" readonly data-f="<?= $t ?>_int" data-sug="<?= e((string) $ctx['sug'][$id][$t]) ?>" value="<?= e($v[$t . '_int']) ?>" aria-label="<?= $T ?> interest, <?= e($m['name']) ?>. Hover or focus to see how it is calculated."></td>
            <td data-label="<?= $T ?> Total"><input class="num" inputmode="decimal" readonly data-f="<?= $t ?>_total" value="<?= e($v[$t . '_total']) ?>" aria-label="<?= $T ?> total, <?= e($m['name']) ?>"></td>
<?php     else: ?>
            <td class="none">&mdash;</td><td class="none">&mdash;</td><td class="none">&mdash;</td>
<?php     endif; ?>
<?php   endforeach; ?>
            <td data-label="Share"><input class="num" inputmode="decimal" readonly data-f="share" value="<?= e($v['share']) ?>" aria-label="Share, <?= e($m['name']) ?>"></td>
            <td data-label="G.Fund"><input class="num" inputmode="decimal" readonly data-f="guarantee" value="<?= e($v['guarantee']) ?>" aria-label="Guarantee fund, <?= e($m['name']) ?>"></td>
            <td data-label="Adm"><input class="num" inputmode="decimal" readonly data-f="admission" value="<?= e($v['admission']) ?>" aria-label="Admission, <?= e($m['name']) ?>"></td>
            <td data-label="Others"><input class="num" inputmode="decimal" readonly data-f="others" value="<?= e($v['others']) ?>" aria-label="Others, <?= e($m['name']) ?>"></td>
            <td class="tot" data-rowtotal data-label="Row Total">&mdash;</td>
            <td class="act">
              <button type="button" class="rb edit">Edit</button>
              <button type="button" class="rb save">Save</button>
              <span class="st"></span>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td></td><td>TOTAL</td><td data-tot="thrift">&mdash;</td>
            <td data-tot="mt_prin">&mdash;</td><td data-tot="mt_int">&mdash;</td><td data-tot="mt_total">&mdash;</td>
            <td data-tot="st_prin">&mdash;</td><td data-tot="st_int">&mdash;</td><td data-tot="st_total">&mdash;</td>
            <td data-tot="share">&mdash;</td><td data-tot="guarantee">&mdash;</td><td data-tot="admission">&mdash;</td>
            <td data-tot="others">&mdash;</td><td data-tot="row">&mdash;</td><td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <section class="panel2" id="payPanel">
      <h2>Bank withdrawals (income)</h2>
      <p class="hint">Cash taken out of the bank. Add one line for every withdrawal, each with its date.</p>
      <table class="pay" id="bankTable">
        <thead><tr><th style="width:19%">Date of withdrawal</th><th style="width:19%">Amount</th><th>Narration</th><th></th></tr></thead>
        <tbody>
<?php foreach ($bankRows as $b): ?>
          <tr>
            <td><input type="date" data-b="date" value="<?= e($b['date']) ?>" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" aria-label="Date of withdrawal"></td>
            <td><span class="rs"><input class="amt" inputmode="decimal" data-b="amount" value="<?= e($b['amount']) ?>" aria-label="Amount withdrawn"></span></td>
            <td><input data-b="note" value="<?= e($b['note']) ?>" maxlength="255" aria-label="Narration"></td>
            <td><button type="button" class="linkbtn js-remove-bank">Remove</button></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      <button type="button" class="addrow" id="addBank">+ Add another withdrawal</button>

      <h2 style="margin-top:22px">Payments (expenses, loans issued, refunds)</h2>
      <p class="hint">Every payment needs its own date. Loans are checked against the share (<?= e(inr_auto((float) $ctx['rules']['loan_min_share_pct'])) ?>%)
        and guarantee fund (<?= e(inr_auto((float) $ctx['rules']['loan_min_guarantee_pct'])) ?>%) rules using the rows saved above.
        To refund a retiring member&rsquo;s whole account use <em>Close account</em> on the Members tab.</p>
      <table class="pay" id="payTable">
        <thead><tr><th style="width:14%">Date</th><th style="width:22%">Head</th><th style="width:22%">Member (if needed)</th><th style="width:13%">Amount</th><th>Narration</th><th></th></tr></thead>
        <tbody>
<?php foreach ($payRows as $i => $p): ?>
          <tr>
            <td><input type="date" data-p="date" value="<?= e($p['date']) ?>" min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" aria-label="Date of payment"></td>
            <td><select data-p="head" aria-label="Payment head">
              <option value="0">Choose&hellip;</option>
<?php   foreach ($payHeads as $h): ?>
              <option value="<?= $h['id'] ?>"<?= $p['head'] === $h['id'] ? ' selected' : '' ?>><?= e($h['name']) ?></option>
<?php   endforeach; ?>
            </select></td>
            <td><select data-p="member" aria-label="Member">
              <option value="0">&mdash;</option>
<?php   foreach ($payMembers as $m): ?>
              <option value="<?= $m['id'] ?>"<?= $p['member'] === $m['id'] ? ' selected' : '' ?>><?= e(cb_label($m)) ?></option>
<?php   endforeach; ?>
            </select></td>
            <td><span class="rs"><input class="amt" inputmode="decimal" data-p="amount" value="<?= e($p['amount']) ?>" aria-label="Amount"></span></td>
            <td><input data-p="note" value="<?= e($p['note']) ?>" maxlength="255" aria-label="Narration"></td>
            <td><button type="button" class="linkbtn js-remove-row">Remove</button></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:8px">
        <button type="button" class="addrow" id="addRow">+ Add another payment</button>
        <button type="button" class="mini primary" id="savePay">Save withdrawals and payments</button>
        <span class="state-text" id="payState"></span>
      </div>
    </section>

    <div class="finalbar">
<?php if ($isFinal): ?>
      <span class="state-text">Changes are saved straight away. Nothing else to confirm.</span>
      <a class="btn" href="admin-cashbook.php?month=<?= e(cb_month_url($month)) ?>">Done &mdash; back to the Cash Book</a>
<?php else: ?>
      <span id="progressText" style="font-weight:700;color:var(--navy)">Saved <?= $savedCount ?> of <?= $totalMembers ?> members</span>
      <form method="get" action="admin-cashbook-summary.php">
        <input type="hidden" name="month" value="<?= e(cb_month_url($month)) ?>">
        <button type="submit" class="btn" id="finalizeBtn" disabled>Finalize month</button>
      </form>
      <span class="state-text" id="whyNot"></span>
      <a class="btn secondary" href="admin-dashboard.php">Cancel</a>
<?php endif; ?>
    </div>

<?php
$script = <<<'JS'
<script>
(function () {
  var sheet = document.getElementById('sheet');
  var cfg = { month: sheet.getAttribute('data-month'), url: sheet.getAttribute('data-url'),
              csrf: sheet.getAttribute('data-csrf'), isFinal: sheet.getAttribute('data-final') === '1',
              total: parseInt(sheet.getAttribute('data-total'), 10) };
  var rows = [].slice.call(sheet.querySelectorAll('tbody tr[data-member]'));
  var dateEl = document.getElementById('date');
  var msg = document.getElementById('msg');
  var payDirty = false;
  var RS = '\u20B9';
  var memberIncome = 0;

  function num(v) { v = String(v == null ? '' : v).replace(/,/g, '').trim(); var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function fmt(n) { return n.toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
  function field(tr, f) { return tr.querySelector('[data-f="' + f + '"]'); }
  function inputs(tr) { return [].slice.call(tr.querySelectorAll('input[data-f]')); }
  function isSaved(tr) { return tr.getAttribute('data-saved') === '1'; }
  function isEditing(tr) { return tr.getAttribute('data-editing') === '1'; }

  function showMsg(kind, lines) {
    msg.innerHTML = '';
    if (!lines || !lines.length) { return; }
    var box = document.createElement('div');
    box.className = kind === 'err' ? 'flash flash-err' : (kind === 'warn' ? 'warn' : 'flash');
    box.setAttribute('role', kind === 'err' ? 'alert' : 'status');
    if (lines.length === 1) { box.textContent = lines[0]; }
    else {
      var ul = document.createElement('ul');
      lines.forEach(function (l, i) {
        if (i === 0 && kind === 'err' && /[:]$/.test(l)) { box.appendChild(document.createTextNode(l)); return; }
        var li = document.createElement('li'); li.textContent = l; ul.appendChild(li);
      });
      box.appendChild(ul);
    }
    msg.appendChild(box);
  }

  // ---- amounts on one loan: Pr = Total - Intt, and the interest follows the calculation until it is edited by hand
  function loan(tr, t) {
    var total = field(tr, t + '_total');
    if (!total) { return { total: 0, interest: 0, principal: 0 }; }
    var inte = field(tr, t + '_int');
    var prin = tr.querySelector('[data-p="' + t + '"]');
    var open = num(tr.getAttribute('data-open-' + t));
    var tv = num(total.value);
    if (inte.getAttribute('data-manual') !== '1') {
      inte.value = (tv > 0 || !isSaved(tr)) ? String(inte.getAttribute('data-sug')) : '';
    }
    var iv = tv > 0 ? num(inte.value) : 0;
    var cell = inte.closest('td');
    if (cell) { cell.classList.toggle('over', inte.value !== '' && Math.abs(num(inte.value) - num(inte.getAttribute('data-sug'))) > 0.004); }
    var p = tv - iv;
    prin.textContent = tv > 0 ? fmt(p) : '\u2014';
    prin.className = 'prin' + ((tv > 0 && (p < 0 || p > open + 0.004)) ? ' bad' : '');
    return { total: tv, interest: iv, principal: tv > 0 ? p : 0 };
  }

  function recalc() {
    var t = { thrift: 0, share: 0, mt_total: 0, mt_int: 0, mt_prin: 0, st_total: 0, st_int: 0, st_prin: 0,
              admission: 0, guarantee: 0, others: 0, row: 0 };
    rows.forEach(function (tr) {
      var mt = loan(tr, 'mt'), st = loan(tr, 'st');
      var r = {}; ['thrift', 'share', 'admission', 'guarantee', 'others'].forEach(function (f) { var el = field(tr, f); r[f] = el ? num(el.value) : 0; });
      var rowTotal = r.thrift + r.share + r.admission + r.guarantee + r.others + mt.total + st.total;
      tr.querySelector('[data-rowtotal]').textContent = rowTotal ? RS + fmt(rowTotal) : '\u2014';
      ['thrift', 'share', 'admission', 'guarantee', 'others'].forEach(function (f) { t[f] += r[f]; });
      t.mt_total += mt.total; t.mt_int += mt.interest; t.mt_prin += mt.principal;
      t.st_total += st.total; t.st_int += st.interest; t.st_prin += st.principal; t.row += rowTotal;
    });
    Object.keys(t).forEach(function (k) {
      var el = document.querySelector('[data-tot="' + k + '"]');
      if (el) { el.textContent = t[k] ? (k === 'row' ? RS : '') + fmt(t[k]) : '\u2014'; }
    });
    memberIncome = t.row;
    cash();
  }

  // ---- cash in hand: opening + income - expenses = closing (updates as you type)
  function money(n) { return (n < 0 ? '-' : '') + RS + fmt(Math.abs(n)); }
  function cash() {
    var bar = document.getElementById('cashbar');
    var bank = 0, out = 0;
    document.querySelectorAll('#bankTable [data-b="amount"]').forEach(function (el) { bank += num(el.value); });
    document.querySelectorAll('#payTable [data-p="amount"]').forEach(function (el) { out += num(el.value); });
    var income = memberIncome + bank + num(bar.getAttribute('data-closure-in'));
    var expenses = out + num(bar.getAttribute('data-closure-out'));
    var closing = num(bar.getAttribute('data-opening')) + income - expenses;
    document.getElementById('cashIn').textContent = money(income);
    document.getElementById('cashOut').textContent = money(expenses);
    var c = document.getElementById('cashClose');
    c.textContent = money(closing);
    c.className = closing < -0.004 ? 'neg' : '';
  }

  // ---- row state: pending (not saved) / saved / editing
  function paint(tr) {
    var st = tr.querySelector('.st'), edit = tr.querySelector('.edit'), save = tr.querySelector('.save');
    var editing = isEditing(tr), saved = isSaved(tr);
    st.className = 'st ' + (editing ? '' : (saved ? 'ok' : 'pend'));
    st.textContent = editing ? 'Editing\u2026' : (saved ? 'Saved \u2713' : 'Not saved');
    edit.disabled = editing;
    save.disabled = saved && !editing;
    inputs(tr).forEach(function (i) { if (editing) { i.removeAttribute('readonly'); } else { i.setAttribute('readonly', 'readonly'); } });
  }
  function setEditing(tr, on) { tr.setAttribute('data-editing', on ? '1' : '0'); paint(tr); recalc(); gate(); }

  function dateDirty() { return dateEl.value !== (dateEl.getAttribute('data-saved') || '') || !dateEl.value; }

  function gate() {
    var unsaved = rows.filter(function (tr) { return !isSaved(tr); }).length;
    var editing = rows.filter(isEditing).length;
    var savedCount = rows.length - unsaved;
    var pt = document.getElementById('progressText');
    if (pt) { pt.textContent = 'Saved ' + savedCount + ' of ' + rows.length + ' members'; }
    var ds = document.getElementById('dateState');
    ds.textContent = dateDirty() ? (dateEl.getAttribute('data-saved') ? 'Date changed: press Save date' : 'Date not saved yet') : 'Date saved \u2713';
    var btn = document.getElementById('finalizeBtn');
    if (!btn) { return; }
    var why = '';
    if (unsaved) { why = 'Press Save for ' + unsaved + ' more member' + (unsaved === 1 ? '' : 's') + '.'; }
    else if (editing) { why = 'Finish editing: press Save on the row you are editing.'; }
    else if (dateDirty()) { why = 'Save the date of the collection.'; }
    else if (payDirty) { why = 'Press Save payments.'; }
    btn.disabled = why !== '';
    document.getElementById('whyNot').textContent = why;
  }

  // ---- talking to the server
  function post(fd) {
    return fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, errors: ['The server gave an unexpected reply. Reload the page and check what was saved.'] }; }); })
      .catch(function () { return { ok: false, errors: ['Could not reach the server. Nothing was saved.'] }; });
  }
  function base(part) {
    var fd = new FormData();
    fd.append('csrf', cfg.csrf); fd.append('month', cfg.month); fd.append('part', part); fd.append('date', dateEl.value);
    return fd;
  }
  function afterOk(res) {
    if (res.date) { dateEl.setAttribute('data-saved', res.date); }
    var notes = res.notes || [];
    if (notes.length) { showMsg('warn', ['Later months keep the amounts recorded in them. Please check:'].concat(notes)); }
    else { showMsg('ok', []); }
  }

  function saveRow(tr) {
    var fd = base('row');
    fd.append('member_id', tr.getAttribute('data-member'));
    inputs(tr).forEach(function (i) { fd.append('r[' + i.getAttribute('data-f') + ']', i.value); });
    var buttons = tr.querySelectorAll('button'); buttons.forEach(function (b) { b.disabled = true; });
    tr.querySelector('.st').textContent = 'Saving\u2026';
    return post(fd).then(function (res) {
      tr.classList.remove('err');
      if (res.ok) {
        Object.keys(res.row).forEach(function (k) {
          var el = field(tr, k);
          if (el) {
            el.value = res.row[k];
            if (/_int$/.test(k)) {
              var sug = el.getAttribute('data-sug');
              el.setAttribute('data-manual', (el.value !== '' && num(el.value) !== num(sug)) ? '1' : '0');
            }
          }
        });
        tr.setAttribute('data-saved', '1'); tr.setAttribute('data-editing', '0');
        afterOk(res); paint(tr); recalc(); gate();
      } else {
        tr.classList.add('err');
        showMsg('err', res.errors || ['Not saved.']);
        paint(tr); gate();
      }
      return res;
    });
  }

  rows.forEach(function (tr) {
    ['mt', 'st'].forEach(function (t) {
      var inte = field(tr, t + '_int');
      if (inte && inte.value !== '' && num(inte.value) !== num(inte.getAttribute('data-sug'))) { inte.setAttribute('data-manual', '1'); }
    });
    tr.querySelector('.edit').addEventListener('click', function () { setEditing(tr, true); var f = inputs(tr)[0]; if (f) { f.focus(); } });
    tr.querySelector('.save').addEventListener('click', function () { saveRow(tr); });
    tr.addEventListener('input', function (ev) {
      var el = ev.target;
      if (!el.getAttribute || !el.getAttribute('data-f')) { return; }
      if (/_int$/.test(el.getAttribute('data-f'))) { el.setAttribute('data-manual', el.value.trim() === '' ? '0' : '1'); }
      recalc();
    });
    paint(tr);
  });

  // ---- the date
  document.getElementById('saveDate').addEventListener('click', function () {
    post(base('date')).then(function (res) {
      if (res.ok) { afterOk(res); } else { showMsg('err', res.errors || ['Not saved.']); }
      gate();
    });
  });
  dateEl.addEventListener('input', gate);

  // ---- payments and bank withdrawal
  var payPanel = document.getElementById('payPanel');
  var body = document.querySelector('#payTable tbody');
  function markPayDirty() { payDirty = true; document.getElementById('payState').textContent = 'Not saved yet'; cash(); gate(); }
  payPanel.addEventListener('input', markPayDirty);
  payPanel.addEventListener('change', markPayDirty);
  function addCopy(tbody) {
    var copy = tbody.querySelector('tr:last-child').cloneNode(true);
    copy.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
    copy.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    tbody.appendChild(copy); markPayDirty();
  }
  function removeFrom(tbody, ev, cls) {
    if (!ev.target.classList.contains(cls)) { return; }
    var tr = ev.target.closest('tr');
    if (tbody.querySelectorAll('tr').length > 1) { tr.remove(); }
    else { tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; }); tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
    markPayDirty();
  }
  var bankBody = document.querySelector('#bankTable tbody');
  document.getElementById('addRow').addEventListener('click', function () { addCopy(body); });
  document.getElementById('addBank').addEventListener('click', function () { addCopy(bankBody); });
  body.addEventListener('click', function (ev) { removeFrom(body, ev, 'js-remove-row'); });
  bankBody.addEventListener('click', function (ev) { removeFrom(bankBody, ev, 'js-remove-bank'); });

  document.getElementById('savePay').addEventListener('click', function () {
    var fd = base('payments');
    bankBody.querySelectorAll('tr').forEach(function (tr, i) {
      fd.append('b[' + i + '][date]', tr.querySelector('[data-b="date"]').value);
      fd.append('b[' + i + '][amount]', tr.querySelector('[data-b="amount"]').value);
      fd.append('b[' + i + '][note]', tr.querySelector('[data-b="note"]').value);
    });
    body.querySelectorAll('tr').forEach(function (tr, i) {
      fd.append('p[' + i + '][date]', tr.querySelector('[data-p="date"]').value);
      fd.append('p[' + i + '][head]', tr.querySelector('[data-p="head"]').value);
      fd.append('p[' + i + '][member]', tr.querySelector('[data-p="member"]').value);
      fd.append('p[' + i + '][amount]', tr.querySelector('[data-p="amount"]').value);
      fd.append('p[' + i + '][note]', tr.querySelector('[data-p="note"]').value);
    });
    post(fd).then(function (res) {
      if (res.ok) { payDirty = false; document.getElementById('payState').textContent = 'Saved \u2713'; afterOk(res); }
      else { showMsg('err', res.errors || ['Not saved.']); }
      gate();
    });
  });

  recalc(); gate();
  window.__sheet = { gate: gate, recalc: recalc };
})();
</script>
JS;
admin_close($script);
