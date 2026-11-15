<?php
declare(strict_types=1);

require_once __DIR__ . '/cashbook-lib.php';

// ------------------------------------------------------- financial years

/** Financial year (starting April) a date belongs to, as the starting year: 2026 means 2026-27. */
function lg_fy_year(string $date): int
{
    $t = strtotime($date);
    return (int) date('n', $t) >= 4 ? (int) date('Y', $t) : (int) date('Y', $t) - 1;
}
function lg_fy_start(int $fy): string { return sprintf('%04d-04-01', $fy); }
function lg_fy_label(int $fy): string { return sprintf('%04d-%02d', $fy, ($fy + 1) % 100); }

/** The twelve month starts of a financial year. */
function lg_months(int $fy): array
{
    $out = [];
    $m = lg_fy_start($fy);
    for ($i = 0; $i < 12; $i++, $m = cb_add_month($m)) {
        $out[] = $m;
    }
    return $out;
}

/** Financial years the ledgers can show: from the one the system started in to the current one. */
function lg_fy_options(PDO $pdo): array
{
    $first = cb_first_month($pdo);
    if ($first === null) {
        return [];
    }
    $a = lg_fy_year($first);
    return range($a, max($a, lg_fy_year(date('Y-m-d'))));
}

/**
 * Where a year's opening position comes from:
 *   BALANCES_OPENING  the opening balances (the system starts in or before this year)
 *   'YYYY-MM-01'      the last month before the year (it must be saved)
 *   null              earlier months are not saved yet, so the opening is not known
 */
function lg_opening_through(PDO $pdo, int $fy): ?string
{
    $first = cb_first_month($pdo);
    if ($first === null) {
        return null;
    }
    $start = lg_fy_start($fy);
    if ($start <= $first) {
        return BALANCES_OPENING;
    }
    $prev  = cb_add_month($start, -1);
    $final = latest_final_month($pdo);
    return ($final !== null && $final >= $prev) ? $prev : null;
}

// ------------------------------------------------------------ the data

/**
 * Everything recorded in the saved months of a financial year, grouped for the ledgers.
 * $memberId limits it to one member (null = everyone).
 *   final[month]                 = collection date
 *   coll[member][month][column]  = what the member paid in
 *   loans[member]['MT'|'ST'][month]
 *   refund[member][month]['share'|'thrift'|'guarantee']
 *   fi[member][month]['thrift'|'guarantee']   interest credited to a fund
 */
function lg_data(PDO $pdo, int $fy, ?int $memberId): array
{
    $from = lg_fy_start($fy);
    $to   = cb_add_month($from, 11);
    $p    = [':f' => $from, ':t' => $to];
    $mid  = $memberId !== null ? [':mid' => $memberId] : [];

    $out = ['final' => [], 'coll' => [], 'loans' => [], 'refund' => [], 'fi' => []];

    $q = $pdo->prepare("SELECT month_start, entry_date FROM cash_months WHERE status = 'final' AND month_start BETWEEN :f AND :t");
    $q->execute($p);
    foreach ($q->fetchAll() as $r) {
        $out['final'][$r['month_start']] = $r['entry_date'];
    }

    $q = $pdo->prepare(
        "SELECT c.member_id, c.collection_month AS m,
                SUM(c.thrift) AS thrift, SUM(c.share) AS share,
                SUM(c.mt_principal) AS mt_principal, SUM(c.mt_interest) AS mt_interest,
                SUM(c.st_principal) AS st_principal, SUM(c.st_interest) AS st_interest,
                SUM(c.admission) AS admission, SUM(c.guarantee) AS guarantee, SUM(c.others) AS others
           FROM collections c
           JOIN cash_months cm ON cm.month_start = c.collection_month AND cm.status = 'final'
          WHERE c.collection_month BETWEEN :f AND :t" . ($memberId !== null ? ' AND c.member_id = :mid' : '') . "
       GROUP BY c.member_id, c.collection_month"
    );
    $q->execute($p + $mid);
    foreach ($q->fetchAll() as $r) {
        $out['coll'][(int) $r['member_id']][$r['m']] = array_map('floatval', array_diff_key($r, ['member_id' => 1, 'm' => 1]));
    }

    $q = $pdo->prepare(
        "SELECT member_id, loan_type, DATE_FORMAT(issued_on, '%Y-%m-01') AS m, SUM(amount) AS a
           FROM loans
          WHERE status = 'issued' AND issued_on >= :f AND issued_on < DATE_ADD(:t, INTERVAL 1 MONTH)"
        . ($memberId !== null ? ' AND member_id = :mid' : '') . "
       GROUP BY member_id, loan_type, m"
    );
    $q->execute($p + $mid);
    foreach ($q->fetchAll() as $r) {
        $out['loans'][(int) $r['member_id']][$r['loan_type']][$r['m']] = (float) $r['a'];
    }

    $q = $pdo->prepare(
        "SELECT e.member_id, e.cash_month AS m, h.effect, SUM(e.amount) AS a
           FROM cash_book_entries e
           JOIN payment_heads h ON h.id = e.head_id
           JOIN cash_months cm ON cm.month_start = e.cash_month AND cm.status = 'final'
          WHERE e.member_id IS NOT NULL AND h.effect IN ('share_refund','thrift_refund','guarantee_refund')
            AND e.cash_month BETWEEN :f AND :t" . ($memberId !== null ? ' AND e.member_id = :mid' : '') . "
       GROUP BY e.member_id, e.cash_month, h.effect"
    );
    $q->execute($p + $mid);
    foreach ($q->fetchAll() as $r) {
        $k = str_replace('_refund', '', $r['effect']);
        $out['refund'][(int) $r['member_id']][$r['m']][$k] = (float) $r['a'];
    }

    $q = $pdo->prepare(
        "SELECT f.member_id, f.cash_month AS m, f.fund, SUM(f.amount) AS a
           FROM fund_interest f
           JOIN cash_months cm ON cm.month_start = f.cash_month AND cm.status = 'final'
          WHERE f.cash_month BETWEEN :f AND :t" . ($memberId !== null ? ' AND f.member_id = :mid' : '') . "
       GROUP BY f.member_id, f.cash_month, f.fund"
    );
    $q->execute($p + $mid);
    foreach ($q->fetchAll() as $r) {
        $out['fi'][(int) $r['member_id']][$r['m']][$r['fund']] = (float) $r['a'];
    }

    return $out;
}

// ------------------------------------------------------ member ledgers

/** What each kind of member ledger shows: its columns and which balance it follows. */
function lg_types(): array
{
    return [
        'mt'        => ['title' => 'MT Loan Ledger',        'key' => 'mt',        'cols' => ['issued' => 'Loan issued', 'principal' => 'Principal repaid', 'interest' => 'Interest paid', 'total' => 'Total received']],
        'st'        => ['title' => 'ST Loan Ledger',        'key' => 'st',        'cols' => ['issued' => 'Loan issued', 'principal' => 'Principal repaid', 'interest' => 'Interest paid', 'total' => 'Total received']],
        'share'     => ['title' => 'Share Ledger',          'key' => 'share',     'cols' => ['bought' => 'Shares bought', 'refunded' => 'Refunded']],
        'guarantee' => ['title' => 'Guarantee Fund Ledger', 'key' => 'guarantee', 'cols' => ['deposit' => 'Deposited', 'interest' => 'Interest credited', 'refunded' => 'Refunded']],
        'thrift'    => ['title' => 'Thrift Fund Ledger',    'key' => 'thrift',    'cols' => ['deposit' => 'Deposited', 'interest' => 'Interest credited', 'refunded' => 'Refunded']],
    ];
}

/**
 * One member's ledger for a financial year. Every saved month is one line:
 * opening + additions - reductions = closing. Months not saved yet show as pending.
 */
function lg_build(string $type, array $data, int $memberId, float $opening, int $fy, string $first): array
{
    $cfg  = lg_types()[$type];
    $cols = $cfg['cols'];
    $tot  = array_fill_keys(array_keys($cols), 0.0);
    $run  = $opening;
    $rows = [];

    foreach (lg_months($fy) as $m) {
        if ($m < $first) {
            continue; // before the system started
        }
        $row = ['month' => $m, 'final' => isset($data['final'][$m]), 'date' => $data['final'][$m] ?? null,
                'opening' => $run, 'v' => [], 'closing' => $run];
        if ($row['final']) {
            $c  = $data['coll'][$memberId][$m] ?? [];
            $rf = $data['refund'][$memberId][$m] ?? [];
            $fi = $data['fi'][$memberId][$m] ?? [];
            $g  = static fn (array $a, string $k): float => (float) ($a[$k] ?? 0);

            switch ($type) {
                case 'mt':
                case 'st':
                    $T = strtoupper($type);
                    $v = ['issued' => (float) ($data['loans'][$memberId][$T][$m] ?? 0),
                          'principal' => $g($c, $type . '_principal'), 'interest' => $g($c, $type . '_interest')];
                    $v['total'] = $v['principal'] + $v['interest'];
                    $closing = $run + $v['issued'] - $v['principal'];
                    break;
                case 'share':
                    $v = ['bought' => $g($c, 'share'), 'refunded' => $g($rf, 'share')];
                    $closing = $run + $v['bought'] - $v['refunded'];
                    break;
                default: // thrift, guarantee
                    $v = ['deposit' => $g($c, $type), 'interest' => $g($fi, $type), 'refunded' => $g($rf, $type)];
                    $closing = $run + $v['deposit'] + $v['interest'] - $v['refunded'];
            }
            $row['v'] = $v;
            $row['closing'] = round($closing, 2);
            $run = $row['closing'];
            foreach ($v as $k => $x) {
                $tot[$k] += $x;
            }
        }
        $rows[] = $row;
    }

    return ['type' => $type, 'cols' => $cols, 'opening' => $opening, 'rows' => $rows,
            'totals' => array_map(static fn ($x) => round($x, 2), $tot), 'closing' => round($run, 2)];
}

/** The opening position (every member) for a financial year, or null if it is not known yet. */
function lg_opening_all(PDO $pdo, int $fy): ?array
{
    $through = lg_opening_through($pdo, $fy);
    return $through === null ? null : get_balances($pdo, null, $through);
}

/** Opening principal and interest split (thrift and guarantee), only for the year the system starts in. */
function lg_opening_split(PDO $pdo, int $fy, int $memberId, string $type): ?array
{
    $first = cb_first_month($pdo);
    if ($first === null || lg_fy_start($fy) > $first || ($type !== 'thrift' && $type !== 'guarantee')) {
        return null;
    }
    $q = $pdo->prepare("SELECT {$type}_principal AS p, {$type}_interest AS i FROM opening_balances WHERE member_id = :m AND as_on = :d");
    $q->execute([':m' => $memberId, ':d' => opening_date($pdo)]);
    $r = $q->fetch();
    return $r ? ['principal' => (float) $r['p'], 'interest' => (float) $r['i']] : null;
}

/** All members, including those whose account is closed, for the ledger drop-down. */
function lg_members(PDO $pdo): array
{
    return $pdo->query('SELECT id, member_no, full_name, status FROM members ORDER BY member_no')->fetchAll();
}

// ----------------------------------------------------- general ledger

/**
 * Account-wise summary of a financial year, built from the saved months.
 * Every posting has two sides, so the debits and credits always agree:
 *   a member receipt        Dr Cash          Cr the fund / loan / income account
 *   a payment               Dr the account   Cr Cash
 *   a bank withdrawal       Dr Cash          Cr Bank
 *   a deposit to the bank   Dr Bank          Cr Cash
 *   interest on a fund      Dr Interest on funds     Cr the fund
 */
function lg_general(PDO $pdo, int $fy): array
{
    $first = cb_first_month($pdo);
    $months = array_values(array_filter(lg_months($fy), fn ($m) => $m >= $first));
    $data  = lg_data($pdo, $fy, null);

    // per-month sums across all members
    $S = []; $R = []; $F = []; $L = [];
    foreach ($data['coll'] as $byMonth) {
        foreach ($byMonth as $m => $c) {
            foreach ($c as $k => $x) { $S[$m][$k] = ($S[$m][$k] ?? 0) + $x; }
        }
    }
    foreach ($data['refund'] as $byMonth) {
        foreach ($byMonth as $m => $c) {
            foreach ($c as $k => $x) { $R[$m][$k] = ($R[$m][$k] ?? 0) + $x; }
        }
    }
    foreach ($data['fi'] as $byMonth) {
        foreach ($byMonth as $m => $c) {
            foreach ($c as $k => $x) { $F[$m][$k] = ($F[$m][$k] ?? 0) + $x; }
        }
    }
    foreach ($data['loans'] as $byType) {
        foreach ($byType as $t => $byMonth) {
            foreach ($byMonth as $m => $x) { $L[$m][$t] = ($L[$m][$t] ?? 0) + $x; }
        }
    }

    // payments and bank withdrawals, by head
    $P = []; $payTotal = []; $bankW = [];
    $q = $pdo->prepare(
        "SELECT e.cash_month AS m, e.side, h.name, h.effect, SUM(e.amount) AS a
           FROM cash_book_entries e
           JOIN payment_heads h ON h.id = e.head_id
           JOIN cash_months cm ON cm.month_start = e.cash_month AND cm.status = 'final'
          WHERE e.cash_month BETWEEN :f AND :t
       GROUP BY e.cash_month, e.side, h.name, h.effect"
    );
    $q->execute([':f' => lg_fy_start($fy), ':t' => cb_add_month(lg_fy_start($fy), 11)]);
    foreach ($q->fetchAll() as $r) {
        $m = $r['m']; $a = (float) $r['a'];
        if ($r['side'] === 'R') {
            $bankW[$m] = ($bankW[$m] ?? 0) + $a;
        } else {
            $payTotal[$m] = ($payTotal[$m] ?? 0) + $a;
            if ($r['effect'] === 'none') { $P[$r['name']][$m] = ($P[$r['name']][$m] ?? 0) + $a; }
        }
    }

    $opening = lg_opening_all($pdo, $fy);
    $open = ['share' => 0.0, 'thrift' => 0.0, 'guarantee' => 0.0, 'mt' => 0.0, 'st' => 0.0];
    foreach ($opening ?? [] as $b) {
        foreach ($open as $k => $_) { $open[$k] += $b[$k]; }
    }

    $g = static fn (array $a, string $m, string $k): float => (float) ($a[$m][$k] ?? 0);

    // account definitions: key => [name, section, nature (cr|dr), opening, closure computing debit and credit for a month]
    $accounts = [];
    $add = function (string $key, string $name, string $section, string $nature, float $op, callable $dc) use (&$accounts) {
        $accounts[$key] = ['key' => $key, 'name' => $name, 'section' => $section, 'nature' => $nature, 'opening' => $op, 'dc' => $dc];
    };

    $add('share', 'Share Capital', 'Funds and capital', 'cr', $open['share'],
        fn ($m) => [$g($R, $m, 'share'), $g($S, $m, 'share')]);
    $add('thrift', 'Thrift Fund', 'Funds and capital', 'cr', $open['thrift'],
        fn ($m) => [$g($R, $m, 'thrift'), $g($S, $m, 'thrift') + $g($F, $m, 'thrift')]);
    $add('guarantee', 'Guarantee Fund', 'Funds and capital', 'cr', $open['guarantee'],
        fn ($m) => [$g($R, $m, 'guarantee'), $g($S, $m, 'guarantee') + $g($F, $m, 'guarantee')]);
    $add('mt', 'MT Loans', 'Loans to members', 'dr', $open['mt'],
        fn ($m) => [$g($L, $m, 'MT'), $g($S, $m, 'mt_principal')]);
    $add('st', 'ST Loans', 'Loans to members', 'dr', $open['st'],
        fn ($m) => [$g($L, $m, 'ST'), $g($S, $m, 'st_principal')]);
    $add('int_mt', 'Interest on MT Loans', 'Income', 'cr', 0.0, fn ($m) => [0.0, $g($S, $m, 'mt_interest')]);
    $add('int_st', 'Interest on ST Loans', 'Income', 'cr', 0.0, fn ($m) => [0.0, $g($S, $m, 'st_interest')]);
    $add('admission', 'Admission Fees', 'Income', 'cr', 0.0, fn ($m) => [0.0, $g($S, $m, 'admission')]);
    $add('others_in', 'Other Receipts', 'Income', 'cr', 0.0, fn ($m) => [0.0, $g($S, $m, 'others')]);
    $add('int_funds', 'Interest on Thrift and Guarantee Fund', 'Expenses', 'dr', 0.0,
        fn ($m) => [$g($F, $m, 'thrift') + $g($F, $m, 'guarantee'), 0.0]);
    foreach (array_keys($P) as $i => $head) {
        if ($head === 'Bank Savings Deposit') { continue; }
        $add('exp' . $i, $head, 'Expenses', 'dr', 0.0, fn ($m) => [(float) ($P[$head][$m] ?? 0), 0.0]);
    }
    $cashOpen = 0.0;
    if ($opening !== null && $months) {
        $cashOpen = cb_cash_opening($pdo, $months[0]);
    }
    $add('bank', 'Bank', 'Cash and bank', 'dr', 0.0,
        fn ($m) => [(float) ($P['Bank Savings Deposit'][$m] ?? 0), (float) ($bankW[$m] ?? 0)]);
    $add('cash', 'Cash in hand', 'Cash and bank', 'dr', $cashOpen, function ($m) use ($S, $bankW, $payTotal) {
        $receipts = array_sum($S[$m] ?? []);
        return [$receipts + (float) ($bankW[$m] ?? 0), (float) ($payTotal[$m] ?? 0)];
    });

    // run every account through the year
    $out = [];
    $totDr = $totCr = 0.0;
    foreach ($accounts as $key => $a) {
        $run = $a['opening'];
        $rows = [];
        $dr = $cr = 0.0;
        foreach ($months as $m) {
            $final = isset($data['final'][$m]);
            [$d, $c] = $final ? ($a['dc'])($m) : [0.0, 0.0];
            $run += $a['nature'] === 'dr' ? ($d - $c) : ($c - $d);
            $rows[] = ['month' => $m, 'final' => $final, 'dr' => round($d, 2), 'cr' => round($c, 2), 'closing' => round($run, 2)];
            $dr += $d; $cr += $c;
        }
        $out[$key] = ['key' => $key, 'name' => $a['name'], 'section' => $a['section'], 'nature' => $a['nature'],
                      'opening' => round($a['opening'], 2), 'dr' => round($dr, 2), 'cr' => round($cr, 2),
                      'closing' => round($run, 2), 'rows' => $rows];
        $totDr += $dr; $totCr += $cr;
    }

    return ['accounts' => $out, 'total_dr' => round($totDr, 2), 'total_cr' => round($totCr, 2),
            'opening_known' => $opening !== null, 'months' => $months, 'final' => $data['final']];
}
