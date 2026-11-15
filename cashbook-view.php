<?php
declare(strict_types=1);

/** Prints one month's cash book: member receipts, bank withdrawals, payments and the cash in hand. */
function cb_render_report(array $rep, array $opts = []): void
{
    $editLinks = !empty($opts['edit_links']);
    $month     = $rep['month'];
    $cols = [
        'thrift' => 'Thrift', 'share' => 'Share',
        'mt_principal' => 'MT Principal', 'mt_interest' => 'MT Interest',
        'st_principal' => 'ST Principal', 'st_interest' => 'ST Interest',
        'admission' => 'Admission', 'guarantee' => 'Guarantee', 'others' => 'Others',
    ];
    // Income from individual members: no rupee sign in the narrow columns, only in the Total column.
    $plain = static function (float $v): string {
        return abs($v) < 0.005 ? '<td class="dim">&mdash;</td>' : '<td>' . e(inr_auto($v)) . '</td>';
    };
    $money = static function (float $v): string {
        return abs($v) < 0.005 ? '<td class="dim">&mdash;</td>' : '<td>' . e(rs($v)) . '</td>';
    };
    $cash = $rep['cash'];
    $year = $rep['cash_year'];
    $neg  = static fn (float $v): string => $v < -0.004 ? ' style="color:var(--red)"' : '';
    ?>
    <section class="rep-wrap">
      <h2 class="rep-title">Cash in hand &mdash; <?= e(cb_month_label($month)) ?></h2>
      <table class="rep">
        <thead><tr><th></th><th>Opening cash in hand</th><th>Income</th><th>Expenses</th><th>Closing cash in hand</th></tr></thead>
        <tbody>
          <tr>
            <td class="l">This month</td>
            <td><?= e(rs($cash['opening'])) ?></td><td><?= e(rs($cash['in'])) ?></td><td><?= e(rs($cash['out'])) ?></td>
            <td<?= $neg($cash['closing']) ?>><strong><?= e(($cash['closing'] < 0 ? '-' : '') . rs(abs($cash['closing']))) ?></strong></td>
          </tr>
          <tr>
            <td class="l">Year so far (<?= e(date('M', strtotime($year['from']))) ?> &ndash; <?= e(date('M Y', strtotime($year['to']))) ?>)</td>
            <td><?= e(rs($year['opening'])) ?></td><td><?= e(rs($year['in'])) ?></td><td><?= e(rs($year['out'])) ?></td>
            <td<?= $neg($year['closing']) ?>><strong><?= e(($year['closing'] < 0 ? '-' : '') . rs(abs($year['closing']))) ?></strong></td>
          </tr>
        </tbody>
      </table>
      <p style="margin:0;padding:8px 18px 12px;color:var(--text-muted);font-size:.8rem">Closing cash in hand = opening cash in hand + income &minus; expenses.
        Income is everything received (members and bank withdrawals); expenses is every payment.</p>
    </section>

    <section class="rep-wrap">
      <h2 class="rep-title">Income from individual members &mdash; <?= e(cb_month_label($month)) ?><?php if (!empty($rep['meta']['entry_date'])): ?>
        <span style="font-weight:400;color:var(--text-muted)"> &middot; collected on <?= e(date('j M Y', strtotime($rep['meta']['entry_date']))) ?></span><?php endif; ?></h2>
      <table class="rep">
        <thead>
          <tr>
            <th>Member</th>
<?php foreach ($cols as $label): ?>
            <th><?= e($label) ?></th>
<?php endforeach; ?>
            <th>Row Total</th>
<?php if ($editLinks): ?>
            <th></th>
<?php endif; ?>
          </tr>
        </thead>
        <tbody>
<?php foreach ($rep['rows'] as $r): ?>
          <tr>
            <td class="l"><?= (int) $r['no'] ?>. <?= e($r['name']) ?>
              <?php if ($r['closure_id'] !== null): ?><span class="month-tag" style="margin-left:6px">Account closure</span><?php endif; ?></td>
<?php   foreach ($cols as $key => $_): echo '            ' . $plain($r[$key]) . "\n"; endforeach; ?>
            <?= $money($r['total']) ?>
<?php   if ($editLinks): ?>
            <td><?php if ($r['closure_id'] === null): ?><a href="admin-cashbook-entry.php?month=<?= e(cb_month_url($month)) ?>#m<?= (int) $r['id'] ?>" style="color:var(--blue);font-weight:600">Edit</a><?php endif; ?></td>
<?php   endif; ?>
          </tr>
<?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="l">Income from members</td>
<?php foreach ($cols as $key => $_): ?>
            <td><?= e(inr_auto($rep['totals'][$key])) ?></td>
<?php endforeach; ?>
            <td><?= e(rs($rep['member_receipts'])) ?></td>
<?php if ($editLinks): ?>
            <td></td>
<?php endif; ?>
          </tr>
        </tfoot>
      </table>
    </section>

    <section class="rep-wrap">
      <h2 class="rep-title">Bank withdrawals (income) &mdash; <?= e(cb_month_label($month)) ?></h2>
<?php if (!$rep['bank_lines']): ?>
      <p style="margin:0;padding:16px 18px;color:var(--text-muted)">No bank withdrawals this month.</p>
<?php else: ?>
      <table class="rep">
        <thead><tr><th>Date of withdrawal</th><th class="l">Narration</th><th>Amount</th></tr></thead>
        <tbody>
<?php   foreach ($rep['bank_lines'] as $b): ?>
          <tr>
            <td class="l"><?= e(date('j M Y', strtotime($b['entry_date']))) ?></td>
            <td class="l"><?= e((string) $b['narration']) ?></td>
            <td><?= e(rs($b['amount'])) ?></td>
          </tr>
<?php   endforeach; ?>
        </tbody>
        <tfoot><tr><td class="l" colspan="2">Total bank withdrawals</td><td><?= e(rs($rep['bank'])) ?></td></tr></tfoot>
      </table>
<?php endif; ?>
    </section>

    <section class="rep-wrap">
      <h2 class="rep-title">Payments &mdash; <?= e(cb_month_label($month)) ?></h2>
<?php if (!$rep['payments']): ?>
      <p style="margin:0;padding:16px 18px;color:var(--text-muted)">No payments this month.</p>
<?php else: ?>
      <table class="rep">
        <thead><tr><th>Date</th><th class="l">Head</th><th class="l">Member</th><th class="l">Narration</th><th>Amount</th></tr></thead>
        <tbody>
<?php   foreach ($rep['payments'] as $p): ?>
          <tr>
            <td class="l"><?= e(date('j M Y', strtotime($p['entry_date']))) ?></td>
            <td class="l"><?= e($p['head_name']) ?></td>
            <td class="l"><?= $p['full_name'] !== null ? e((int) $p['member_no'] . '. ' . $p['full_name']) : '<span class="dim">&mdash;</span>' ?></td>
            <td class="l"><?= e((string) $p['narration']) ?></td>
            <td><?= e(rs($p['amount'])) ?></td>
          </tr>
<?php   endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td class="l" colspan="4">TOTAL PAYMENTS</td><td><?= e(rs($rep['payments_total'])) ?></td></tr>
        </tfoot>
      </table>
<?php endif; ?>
    </section>

    <section class="rep-wrap">
      <table class="rep">
        <tbody>
          <tr><td class="l">Income from members</td><td><?= e(rs($rep['member_receipts'])) ?></td></tr>
          <tr><td class="l">Bank withdrawals</td><td><?= e(rs($rep['bank'])) ?></td></tr>
          <tr><td class="l"><strong>Total income</strong></td><td><strong><?= e(rs($rep['receipts_total'])) ?></strong></td></tr>
          <tr><td class="l">Total payments (expenses)</td><td><?= e(rs($rep['payments_total'])) ?></td></tr>
        </tbody>
        <tfoot>
          <tr><td class="l">Net for the month (income &minus; expenses)</td><td><?= e(($rep['net'] < 0 ? '-' : '') . rs(abs($rep['net']))) ?></td></tr>
        </tfoot>
      </table>
    </section>
<?php
}
