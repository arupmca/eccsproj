<?php
declare(strict_types=1);

require_once __DIR__ . '/balances.php';

// ------------------------------------------------------------ dates / months

function cb_month_label(string $m): string { return date('F Y', strtotime($m)); }
function cb_month_end(string $m): string   { return date('Y-m-t', strtotime($m)); }
function cb_days_in_month(string $m): int  { return (int) date('t', strtotime($m)); }
function cb_add_month(string $m, int $n = 1): string
{
    return date('Y-m-01', strtotime($m . ' ' . ($n >= 0 ? '+' : '') . $n . ' month'));
}
function cb_month_start(string $date): string { return date('Y-m-01', strtotime($date)); }

/** Accepts 'YYYY-MM' (from a URL) and returns 'YYYY-MM-01', or null if it is not a real month. */
function cb_month_param(?string $p): ?string
{
    if (!is_string($p) || !preg_match('/^(\d{4})-(\d{2})$/', $p, $x) || !checkdate((int) $x[2], 1, (int) $x[1])) {
        return null;
    }
    return $x[1] . '-' . $x[2] . '-01';
}
function cb_month_url(string $m): string { return substr($m, 0, 7); }

function cb_first_month(PDO $pdo): ?string
{
    $d = opening_date($pdo);
    return $d ? cb_month_start($d) : null;
}

function cb_rules(PDO $pdo): array
{
    return $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Every month from the first month up to the current month, with its state:
 *   final   saved and locked
 *   draft   being entered
 *   next    the one month that may be started now
 *   locked  cannot be entered until the month before it is saved
 * Months cannot be skipped, so at most one month is 'draft' or 'next'.
 */
function cb_month_states(PDO $pdo, ?string $today = null): array
{
    $first = cb_first_month($pdo);
    if ($first === null) {
        return [];
    }
    $current = cb_month_start($today ?? 'today');
    $status  = $pdo->query('SELECT month_start, status FROM cash_months')->fetchAll(PDO::FETCH_KEY_PAIR);

    $out = [];
    $open = false;
    for ($m = $first; $m <= $current; $m = cb_add_month($m)) {
        $st = $status[$m] ?? null;
        if (!$open && $st === 'final') {
            $state = 'final';
        } elseif (!$open) {
            $state = $st === 'draft' ? 'draft' : 'next';
            $open  = true;
        } else {
            $state = 'locked';
        }
        $out[] = ['month' => $m, 'state' => $state];
    }
    return $out;
}

/** The month that can be entered right now (draft or next), or null. */
function cb_open_month(PDO $pdo): ?array
{
    foreach (cb_month_states($pdo) as $s) {
        if ($s['state'] === 'draft' || $s['state'] === 'next') {
            return $s;
        }
    }
    return null;
}

function cb_ensure_draft(PDO $pdo, string $month, int $userId): void
{
    $pdo->prepare("INSERT IGNORE INTO cash_months (month_start, status, started_by) VALUES (:m, 'draft', :u)")
        ->execute([':m' => $month, ':u' => $userId]);
}

function cb_suggest_interest(float $principal, float $ratePct, int $days): float
{
    return round($principal * $ratePct / 100 * $days / 365);
}

/** Loans issued during the month before $month: [member_id]['MT'|'ST'] => list of ['amount', 'issued_on']. */
function cb_new_loans_prev(PDO $pdo, string $month): array
{
    $q = $pdo->prepare(
        "SELECT member_id, loan_type, amount, issued_on FROM loans
          WHERE status = 'issued' AND issued_on >= :a AND issued_on < :b ORDER BY issued_on, id"
    );
    $q->execute([':a' => cb_add_month($month, -1), ':b' => $month]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[(int) $r['member_id']][$r['loan_type']][] = ['amount' => (float) $r['amount'], 'issued_on' => $r['issued_on']];
    }
    return $out;
}

/**
 * Interest collected with a month's deposit, on a reducing balance. It is the interest for the PREVIOUS month:
 *   (balance on the 1st of the month, without loans issued last month) x rate x days in the previous month / 365
 *   + for every loan issued last month: amount x rate x days it was active last month / 365 (issue day counted)
 * Each part is rounded to the nearest rupee, then the parts are added.
 * Returns ['amount' => 402.0, 'formula' => '69,808 × 7% × 30 ÷ 365 = 402'].
 */
function cb_interest_calc(float $balance, float $ratePct, string $prevMonth, array $newLoans = []): array
{
    $days  = cb_days_in_month($prevMonth);
    $added = 0.0;
    foreach ($newLoans as $l) {
        $added += (float) $l['amount'];
    }
    $old = max(0.0, $balance - $added);

    $parts = [];   // [base, days, amount]
    if ($old > 0.004) {
        $parts[] = [$old, $days, round($old * $ratePct / 100 * $days / 365)];
    }
    foreach ($newLoans as $l) {
        $active = max(1, $days - (int) date('j', strtotime($l['issued_on'])) + 1);
        $parts[] = [(float) $l['amount'], $active, round((float) $l['amount'] * $ratePct / 100 * $active / 365)];
    }
    if (!$parts) {
        return ['amount' => 0.0, 'formula' => ''];
    }

    $rate     = rtrim(rtrim(number_format($ratePct, 4, '.', ''), '0'), '.');
    $amount   = array_sum(array_column($parts, 2));
    $segments = array_map(static fn ($p) => inr_auto($p[0]) . ' × ' . $rate . '% × ' . $p[1] . ' ÷ 365', $parts);
    $values   = array_map(static fn ($p) => inr_auto($p[2]), $parts);
    $formula  = count($parts) === 1
        ? $segments[0] . ' = ' . $values[0]
        : '(' . implode(') + (', $segments) . ') = ' . implode(' + ', $values) . ' = ' . inr_auto($amount);
    return ['amount' => (float) $amount, 'formula' => $formula];
}

/** The interest due with a member's deposit for a loan, as the sheet suggests it (the Detailed List's figure in the first month). */
function cb_arrears_interest(PDO $pdo, int $memberId, string $type, string $month, float $balance): array
{
    $rules = cb_rules($pdo);
    $calc  = cb_interest_calc($balance, (float) $rules[$type . '_rate'], cb_add_month($month, -1),
                              cb_new_loans_prev($pdo, $month)[$memberId][strtoupper($type)] ?? []);
    if ($month === cb_first_month($pdo) && $balance > 0) {
        $q = $pdo->prepare("SELECT {$type}_interest_due FROM opening_balances WHERE member_id = :m AND as_on = :d");
        $q->execute([':m' => $memberId, ':d' => opening_date($pdo)]);
        $sheet = (float) $q->fetchColumn();
        if ($sheet > 0) {
            return ['amount' => $sheet, 'formula' => abs($calc['amount'] - $sheet) < 0.5 ? $calc['formula'] : 'As printed in the Detailed List'];
        }
    }
    return $calc;
}

/** Cuts text to at most $n characters (not bytes), without needing the mbstring extension. */
function cb_trunc(string $s, int $n): string
{
    if (preg_match('/^.{0,' . $n . '}/su', $s, $m)) {
        return $m[0];
    }
    return substr($s, 0, $n);
}

function cb_label(array $m): string
{
    return (int) $m['no'] . '. ' . $m['name'];
}

// ---------------------------------------------------------- shared context

/**
 * Everything the entry sheet, the checks and the summary need for one month. Balances are the position at the
 * START of the month (all earlier months), so this works for the open month and for a saved month alike.
 */
function cb_ctx(PDO $pdo, string $month): array
{
    $rules = cb_rules($pdo);
    $first = cb_first_month($pdo);
    $prev  = cb_add_month($month, -1);
    $bal   = get_balances($pdo, null, $prev < $first ? BALANCES_OPENING : $prev);
    $zero  = ['share' => 0.0, 'thrift' => 0.0, 'guarantee' => 0.0, 'mt' => 0.0, 'st' => 0.0];

    $opening = [];
    if ($month === $first) {
        $q = $pdo->prepare('SELECT member_id, mt_interest_due, st_interest_due FROM opening_balances WHERE as_on = :d');
        $q->execute([':d' => opening_date($pdo)]);
        foreach ($q->fetchAll() as $r) {
            $opening[(int) $r['member_id']] = $r;
        }
    }

    $prevMonth = cb_add_month($month, -1);
    $newLoans  = cb_new_loans_prev($pdo, $month);
    $members = [];
    $sug     = [];
    $formula = [];
    // members who are active, plus anyone who has an entry in this month (e.g. an account closed later)
    $q = $pdo->prepare(
        "SELECT m.id, m.member_no, m.full_name FROM members m
          WHERE m.status = 'active'
             OR EXISTS (SELECT 1 FROM collections c WHERE c.member_id = m.id AND c.collection_month = :m)
          ORDER BY m.member_no"
    );
    $q->execute([':m' => $month]);
    foreach ($q->fetchAll() as $r) {
        $id = (int) $r['id'];
        $b  = $bal[$id] ?? $zero;
        $members[$id] = ['id' => $id, 'no' => (int) $r['member_no'], 'name' => $r['full_name'], 'bal' => $b];

        foreach (['mt' => 'mt_rate', 'st' => 'st_rate'] as $t => $rateKey) {
            $sheetVal = isset($opening[$id]) ? (float) $opening[$id][$t . '_interest_due'] : 0.0;
            $calc = cb_interest_calc($b[$t], (float) $rules[$rateKey], $prevMonth, $newLoans[$id][strtoupper($t)] ?? []);
            if ($sheetVal > 0 && $b[$t] > 0) {
                // First month: the interest printed in the Detailed List.
                $sug[$id][$t]     = $sheetVal;
                $formula[$id][$t] = abs($calc['amount'] - $sheetVal) < 0.5 ? $calc['formula'] : 'As printed in the Detailed List';
            } else {
                $sug[$id][$t]     = $calc['amount'];
                $formula[$id][$t] = $calc['formula'];
            }
        }
    }

    $closing = [];
    $q = $pdo->prepare('SELECT member_id FROM member_closures WHERE cash_month = :m');
    $q->execute([':m' => $month]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        $closing[] = (int) $mid;
    }

    $heads = [];
    foreach ($pdo->query('SELECT id, name, side, effect FROM payment_heads WHERE active = 1 ORDER BY sort_order') as $h) {
        $heads[(int) $h['id']] = ['id' => (int) $h['id'], 'name' => $h['name'], 'side' => $h['side'], 'effect' => $h['effect']];
    }

    return ['month' => $month, 'rules' => $rules, 'members' => $members, 'sug' => $sug, 'formula' => $formula,
            'closing' => $closing, 'heads' => $heads];
}

/** What each member paid on each loan in the latest saved month before $month (for pre-filling). */
function cb_prev_totals(PDO $pdo, string $month): array
{
    $q = $pdo->prepare(
        "SELECT member_id, mt_principal + mt_interest AS mt, st_principal + st_interest AS st
           FROM collections
          WHERE closure_id IS NULL
            AND collection_month = (SELECT MAX(month_start) FROM cash_months WHERE status = 'final' AND month_start < :m)"
    );
    $q->execute([':m' => $month]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[(int) $r['member_id']] = ['mt' => (float) $r['mt'], 'st' => (float) $r['st']];
    }
    return $out;
}

/** The saved row of every member for a month (only members whose Save has been pressed have one). */
function cb_saved_rows(PDO $pdo, string $month): array
{
    $q = $pdo->prepare('SELECT * FROM collections WHERE collection_month = :m');
    $q->execute([':m' => $month]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[(int) $r['member_id']] = [
            'thrift' => (float) $r['thrift'], 'share' => (float) $r['share'],
            'mt_principal' => (float) $r['mt_principal'], 'mt_interest' => (float) $r['mt_interest'],
            'mt_total' => (float) $r['mt_principal'] + (float) $r['mt_interest'],
            'st_principal' => (float) $r['st_principal'], 'st_interest' => (float) $r['st_interest'],
            'st_total' => (float) $r['st_principal'] + (float) $r['st_interest'],
            'admission' => (float) $r['admission'], 'guarantee' => (float) $r['guarantee'], 'others' => (float) $r['others'],
            'closure_id' => $r['closure_id'] !== null ? (int) $r['closure_id'] : null,
        ];
    }
    return $out;
}

/**
 * The values shown for a member before Save is pressed: thrift for everyone, and for loan holders
 * last month's instalment as the Total with the calculated interest.
 */
function cb_default_row(array $m, array $ctx, array $prevTotals): array
{
    $id = $m['id'];
    $row = ['thrift' => cb_fmt_input((float) $ctx['rules']['thrift_monthly']), 'share' => '', 'admission' => '',
            'guarantee' => '', 'others' => '', 'mt_total' => '', 'mt_int' => '', 'st_total' => '', 'st_int' => ''];
    foreach (['mt', 'st'] as $t) {
        if ($m['bal'][$t] > 0) {
            $row[$t . '_total'] = isset($prevTotals[$id]) ? cb_fmt_input($prevTotals[$id][$t]) : '';
            $row[$t . '_int']   = cb_fmt_input((float) $ctx['sug'][$id][$t]);
        }
    }
    return $row;
}

/** A saved row, as the strings shown in the boxes. */
function cb_row_strings(array $r): array
{
    $loan = static fn (string $t): array => $r[$t . '_total'] > 0
        ? [$t . '_total' => cb_fmt_input($r[$t . '_total']), $t . '_int' => cb_fmt_input($r[$t . '_interest'])]
        : [$t . '_total' => '', $t . '_int' => ''];
    return ['thrift' => cb_fmt_input($r['thrift']), 'share' => cb_fmt_input($r['share']),
            'admission' => cb_fmt_input($r['admission']), 'guarantee' => cb_fmt_input($r['guarantee']),
            'others' => cb_fmt_input($r['others'])] + $loan('mt') + $loan('st');
}

// ----------------------------------------------------- reading what was typed

function cb_amount(mixed $raw, string $label, array &$errors): float
{
    $s = str_replace([',', ' '], '', trim((string) $raw));
    if ($s === '') {
        return 0.0;
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) {
        $errors[] = $label . ': enter a valid amount (digits only, up to 2 decimals).';
        return 0.0;
    }
    return round((float) $s, 2);
}

/** A date typed for the month: real, inside the month, and not in the future. Returns '' with an error otherwise. */
function cb_parse_date(mixed $raw, string $month, array &$errors): string
{
    $date = trim((string) $raw);
    $ts   = strtotime($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $ts === false || date('Y-m-d', $ts) !== $date) {
        $errors[] = 'Enter the date the collection was taken.';
        return '';
    }
    if (cb_month_start($date) !== $month) {
        $errors[] = 'The collection date must fall in ' . cb_month_label($month) . '.';
        return '';
    }
    if ($date > date('Y-m-d')) {
        $errors[] = 'The collection date cannot be in the future.';
        return '';
    }
    return $date;
}

/**
 * Reads one member's typed row. Principal is never read from the form: it is Total - Interest.
 * A loan with no Total means no instalment was received, so its interest is zero too.
 */
function cb_parse_row(array $in, array $m, array $sug, array &$errors): array
{
    $lab = cb_label($m);
    $row = [];
    foreach (['thrift' => 'thrift', 'share' => 'share', 'admission' => 'admission',
              'guarantee' => 'guarantee fund', 'others' => 'others'] as $f => $name) {
        $row[$f] = cb_amount($in[$f] ?? '', $lab . ' ' . $name, $errors);
    }
    foreach (['mt' => 'MT', 'st' => 'ST'] as $t => $T) {
        $row[$t . '_total'] = $row[$t . '_interest'] = $row[$t . '_principal'] = 0.0;
        if ($m['bal'][$t] <= 0) {
            continue; // no such loan: nothing can be received
        }
        $total = cb_amount($in[$t . '_total'] ?? '', $lab . ' ' . $T . ' total', $errors);
        if ($total <= 0) {
            continue;
        }
        $intBlank = trim((string) ($in[$t . '_int'] ?? '')) === '';
        $interest = $intBlank ? (float) $sug[$t] : cb_amount($in[$t . '_int'], $lab . ' ' . $T . ' interest', $errors);
        $row[$t . '_total']     = $total;
        $row[$t . '_interest']  = $interest;
        $row[$t . '_principal'] = round($total - $interest, 2);
    }
    return $row;
}

/**
 * A date typed on a payment or a bank withdrawal: mandatory, real, inside the month and not in the future.
 * Returns '' (and adds an error) otherwise.
 */
function cb_line_date(mixed $raw, string $month, string $label, string $what, array &$errors): string
{
    $date = trim((string) $raw);
    if ($date === '') {
        $errors[] = $label . ': enter the date of the ' . $what . '.';
        return '';
    }
    $ts = strtotime($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $ts === false || date('Y-m-d', $ts) !== $date) {
        $errors[] = $label . ': the date is not a valid date.';
        return '';
    }
    if (cb_month_start($date) !== $month) {
        $errors[] = $label . ': the date must fall in ' . cb_month_label($month) . '.';
        return '';
    }
    if ($date > date('Y-m-d')) {
        $errors[] = $label . ': the date cannot be in the future.';
        return '';
    }
    return $date;
}

/**
 * Reads the bank withdrawals and the payment lines. Every line needs its own date.
 * Returns [payments, bankLines, errors].
 */
function cb_parse_payments(array $post, string $month): array
{
    $errors = [];

    $bankLines = [];
    $n = 0;
    foreach (is_array($post['b'] ?? null) ? $post['b'] : [] as $b) {
        if (!is_array($b)) {
            continue;
        }
        $amtRaw = trim((string) ($b['amount'] ?? ''));
        $note   = cb_trunc(trim((string) ($b['note'] ?? '')), 255);
        if ($amtRaw === '' && $note === '') {
            continue; // an empty line (a date on its own does not make a line)
        }
        $n++;
        $label  = 'Bank withdrawal ' . $n;
        $before = count($errors);
        $amount = cb_amount($amtRaw, $label . ' amount', $errors);
        if ($amtRaw === '') {
            $errors[] = $label . ': enter the amount.';
        } elseif (count($errors) === $before && $amount <= 0) {
            $errors[] = $label . ': the amount must be more than zero.';
        }
        $bankLines[] = ['line' => $n, 'date' => cb_line_date($b['date'] ?? '', $month, $label, 'withdrawal', $errors),
                        'amount' => $amount, 'narration' => $note];
    }

    $payments = [];
    $lineNo   = 0;
    foreach (is_array($post['p'] ?? null) ? $post['p'] : [] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $head   = (int) ($p['head'] ?? 0);
        $member = (int) ($p['member'] ?? 0);
        $amtRaw = trim((string) ($p['amount'] ?? ''));
        $note   = cb_trunc(trim((string) ($p['note'] ?? '')), 255);
        if ($head === 0 && $member === 0 && $amtRaw === '' && $note === '') {
            continue; // an empty line
        }
        $lineNo++;
        $label = 'Payment line ' . $lineNo;
        $payments[] = [
            'line'      => $lineNo,
            'head_id'   => $head,
            'member_id' => $member > 0 ? $member : null,
            'amount'    => cb_amount($amtRaw, $label, $errors),
            'date'      => cb_line_date($p['date'] ?? '', $month, $label, 'expense', $errors),
            'narration' => $note,
        ];
    }
    return [$payments, $bankLines, $errors];
}

// ------------------------------------------------------- business rules

/** The rules for one member's row. */
function cb_check_row(array $row, array $m, array $rules): array
{
    $errors = [];
    $lab = cb_label($m);
    $shareValue = (float) $rules['share_value'];
    $shareMax   = (float) $rules['share_max_per_member'];

    foreach (['mt' => 'MT', 'st' => 'ST'] as $t => $T) {
        $total = $row[$t . '_total'];
        $int   = $row[$t . '_interest'];
        $prin  = $row[$t . '_principal'];
        if ($total <= 0) {
            continue;
        }
        if ($int > $total + 0.004) {
            $errors[] = $lab . ': ' . $T . ' interest ₹' . inr_auto($int) . ' is more than the total ₹' . inr_auto($total) . '.';
        } elseif ($prin > $m['bal'][$t] + 0.004) {
            $errors[] = $lab . ': ' . $T . ' principal ₹' . inr_auto($prin) . ' is more than the loan outstanding ₹' . inr_auto($m['bal'][$t]) . '.';
        }
    }

    if ($row['share'] > 0 && abs(round($row['share'] / $shareValue) * $shareValue - $row['share']) > 0.004) {
        $errors[] = $lab . ': share money must be in multiples of ₹' . inr_auto($shareValue) . '.';
    }
    if ($m['bal']['share'] + $row['share'] > $shareMax + 0.004) {
        $errors[] = $lab . ': shares would become ₹' . inr_auto($m['bal']['share'] + $row['share'])
                  . ', above the limit of ₹' . inr_auto($shareMax) . '.';
    }
    return $errors;
}

function cb_check_rows(array $parsed, array $ctx): array
{
    $errors = [];
    foreach ($parsed['rows'] as $id => $row) {
        $errors = array_merge($errors, cb_check_row($row, $ctx['members'][$id], $ctx['rules']));
    }
    return $errors;
}

/** The rules for payment lines (loans issued, refunds). Uses the saved rows of the month. */
function cb_check_payments(array $parsed, array $ctx): array
{
    $errors = [];
    $rules  = $ctx['rules'];

    $refunds = [];
    foreach ($parsed['payments'] as $p) {
        $n    = $p['line'];
        $head = $ctx['heads'][$p['head_id']] ?? null;
        if ($head === null || $head['side'] !== 'P') {
            $errors[] = 'Payment line ' . $n . ': choose a head.';
            continue;
        }
        if ($p['amount'] <= 0) {
            $errors[] = 'Payment line ' . $n . ' (' . $head['name'] . '): enter an amount.';
            continue;
        }
        $effect = $head['effect'];
        if ($effect === 'none') {
            continue;
        }

        $id = $p['member_id'];
        if ($id === null || !isset($ctx['members'][$id])) {
            $errors[] = 'Payment line ' . $n . ' (' . $head['name'] . '): choose the member.';
            continue;
        }
        if (in_array($id, $ctx['closing'], true)) {
            $errors[] = 'Payment line ' . $n . ': ' . $ctx['members'][$id]['name'] . ' is being closed this month; remove this line.';
            continue;
        }
        $m   = $ctx['members'][$id];
        $lab = cb_label($m);
        $row = $parsed['rows'][$id];

        if ($effect === 'mt_loan' || $effect === 'st_loan') {
            $t   = $effect === 'mt_loan' ? 'mt' : 'st';
            $T   = strtoupper($t);
            $max = (float) $rules[$t . '_max_amount'];
            if ($p['amount'] > $max + 0.004) {
                $errors[] = $lab . ': ' . $T . ' loan ₹' . inr_auto($p['amount']) . ' is above the maximum ₹' . inr_auto($max) . '.';
            }
            $needShare = round($p['amount'] * (float) $rules['loan_min_share_pct'] / 100, 2);
            $haveShare = $m['bal']['share'] + $row['share'];
            if ($haveShare + 0.004 < $needShare) {
                $errors[] = $lab . ': a ' . $T . ' loan of ₹' . inr_auto($p['amount']) . ' needs shares of at least ₹' . inr_auto($needShare)
                          . ' (' . inr_auto((float) $rules['loan_min_share_pct']) . '%); this member will have ₹' . inr_auto($haveShare)
                          . '. Save ₹' . inr_auto($needShare - $haveShare) . ' more in this member\'s Share box first.';
            }
            $needGuar = round($p['amount'] * (float) $rules['loan_min_guarantee_pct'] / 100, 2);
            $haveGuar = $m['bal']['guarantee'] + $row['guarantee'];
            if ($haveGuar + 0.004 < $needGuar) {
                $errors[] = $lab . ': a ' . $T . ' loan of ₹' . inr_auto($p['amount']) . ' needs guarantee fund of at least ₹' . inr_auto($needGuar)
                          . ' (' . inr_auto((float) $rules['loan_min_guarantee_pct']) . '%); this member will have ₹' . inr_auto($haveGuar)
                          . '. Save ₹' . inr_auto($needGuar - $haveGuar) . ' more in this member\'s G.Fund box first.';
            }
        } else {
            $refunds[$id][$effect] = ($refunds[$id][$effect] ?? 0) + $p['amount'];
        }
    }

    $limits = ['share_refund' => ['share', 'share', 'Share refund'],
               'thrift_refund' => ['thrift', 'thrift', 'Thrift fund refund'],
               'guarantee_refund' => ['guarantee', 'guarantee', 'Guarantee fund refund']];
    foreach ($refunds as $id => $byEffect) {
        $m = $ctx['members'][$id];
        foreach ($byEffect as $effect => $sum) {
            [$balKey, $rowKey, $name] = $limits[$effect];
            $available = $m['bal'][$balKey] + $parsed['rows'][$id][$rowKey];
            if ($sum > $available + 0.004) {
                $errors[] = cb_label($m) . ': ' . $name . ' ₹' . inr_auto($sum) . ' is more than the balance ₹' . inr_auto($available) . '.';
            }
        }
    }
    return $errors;
}

/** All the rules for a whole month (used when finalising). */
function cb_check(array $parsed, array $ctx): array
{
    return array_merge(cb_check_rows($parsed, $ctx), cb_check_payments($parsed, $ctx));
}

/** Things worth a second look but not wrong. */
function cb_warnings(array $parsed, array $ctx): array
{
    $w = [];
    $thrift = (float) $ctx['rules']['thrift_monthly'];
    $diff = [];
    $unpaid = [];
    foreach ($parsed['rows'] as $id => $row) {
        $m = $ctx['members'][$id];
        if (abs($row['thrift'] - $thrift) > 0.004) {
            $diff[] = $m['name'] . ' (₹' . inr_auto($row['thrift']) . ')';
        }
        foreach (['mt' => 'MT', 'st' => 'ST'] as $t => $T) {
            if ($m['bal'][$t] > 0 && $row[$t . '_total'] <= 0) {
                $unpaid[] = $m['name'] . ' (' . $T . ')';
            }
        }
    }
    if ($diff) {
        $w[] = 'Thrift is not ₹' . inr_auto($thrift) . ' for: ' . implode(', ', $diff) . '.';
    }
    if ($unpaid) {
        $w[] = 'Loan holders with no instalment entered: ' . implode(', ', $unpaid) . '.';
    }
    return $w;
}

// ------------------------------------------------------------- saving

function cb_fmt_input(float $v): string
{
    return abs($v) < 0.005 ? '' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

function cb_meta(PDO $pdo, string $month): ?array
{
    $q = $pdo->prepare('SELECT status, entry_date FROM cash_months WHERE month_start = :m');
    $q->execute([':m' => $month]);
    return $q->fetch() ?: null;
}

/** Saves one member's row (it is "saved" once it exists, even if every amount is zero). */
function cb_row_save(PDO $pdo, string $month, int $memberId, array $row, string $date, int $userId): void
{
    $pdo->prepare('DELETE FROM collections WHERE member_id = :m AND collection_month = :mo AND closure_id IS NULL')
        ->execute([':m' => $memberId, ':mo' => $month]);
    $pdo->prepare(
        'INSERT INTO collections (member_id, collection_month, received_on, thrift, share, mt_principal, mt_interest,
                                  st_principal, st_interest, admission, guarantee, others, entered_by)
         VALUES (:mid, :m, :d, :thrift, :share, :mtp, :mti, :stp, :sti, :adm, :guar, :oth, :u)'
    )->execute([
        ':mid' => $memberId, ':m' => $month, ':d' => $date, ':thrift' => $row['thrift'], ':share' => $row['share'],
        ':mtp' => $row['mt_principal'], ':mti' => $row['mt_interest'], ':stp' => $row['st_principal'], ':sti' => $row['st_interest'],
        ':adm' => $row['admission'], ':guar' => $row['guarantee'], ':oth' => $row['others'], ':u' => $userId,
    ]);
}

/** Loan records for the loans issued in a month's payment lines that have none yet (dated as the payment line). */
function cb_create_loans(PDO $pdo, string $month, array $rules): void
{
    $q = $pdo->prepare(
        "SELECT e.id, e.member_id, e.amount, e.entry_date, h.effect
           FROM cash_book_entries e JOIN payment_heads h ON h.id = e.head_id
          WHERE e.cash_month = :m AND h.effect IN ('mt_loan','st_loan') AND e.loan_id IS NULL"
    );
    $q->execute([':m' => $month]);
    $ins = $pdo->prepare(
        "INSERT INTO loans (member_id, loan_type, amount, interest_rate, installments, applied_on, issued_on, status)
         VALUES (:mid, :t, :a, :r, :n, :d, :d2, 'issued')"
    );
    $set = $pdo->prepare('UPDATE cash_book_entries SET loan_id = :l WHERE id = :id');
    foreach ($q->fetchAll() as $e) {
        $t = $e['effect'] === 'mt_loan' ? 'MT' : 'ST';
        $k = strtolower($t);
        $ins->execute([':mid' => $e['member_id'], ':t' => $t, ':a' => $e['amount'], ':r' => $rules[$k . '_rate'],
                       ':n' => $rules[$k . '_installments'], ':d' => $e['entry_date'], ':d2' => $e['entry_date']]);
        $set->execute([':l' => $pdo->lastInsertId(), ':id' => $e['id']]);
    }
}

/**
 * Replaces the month's bank withdrawals and payment lines (closures are untouched). Every line keeps its own date.
 * In a saved month the loan records are rebuilt too; the caller then checks the later months.
 */
function cb_payments_save(PDO $pdo, string $month, array $payments, array $bankLines, int $userId, bool $isFinal, array $rules): void
{
    $q = $pdo->prepare('SELECT loan_id FROM cash_book_entries WHERE cash_month = :m AND closure_id IS NULL AND loan_id IS NOT NULL');
    $q->execute([':m' => $month]);
    $oldLoans = $q->fetchAll(PDO::FETCH_COLUMN);

    $pdo->prepare('DELETE FROM cash_book_entries WHERE cash_month = :m AND closure_id IS NULL')->execute([':m' => $month]);
    foreach ($oldLoans as $lid) {
        $pdo->prepare('DELETE FROM loans WHERE id = :id')->execute([':id' => $lid]);
    }

    $bankHead = (int) $pdo->query("SELECT id FROM payment_heads WHERE name = 'Bank Withdrawal' AND side = 'R'")->fetchColumn();
    $entry = $pdo->prepare(
        'INSERT INTO cash_book_entries (cash_month, entry_date, side, head_id, amount, member_id, narration, entered_by)
         VALUES (:m, :d, :side, :h, :a, :mid, :n, :u)'
    );
    foreach ($bankLines as $b) {
        $entry->execute([':m' => $month, ':d' => $b['date'], ':side' => 'R', ':h' => $bankHead, ':a' => $b['amount'],
                         ':mid' => null, ':n' => $b['narration'] ?: null, ':u' => $userId]);
    }
    foreach ($payments as $p) {
        $entry->execute([':m' => $month, ':d' => $p['date'], ':side' => 'P', ':h' => $p['head_id'], ':a' => $p['amount'],
                         ':mid' => $p['member_id'], ':n' => $p['narration'] ?: null, ':u' => $userId]);
    }
    if ($isFinal) {
        cb_create_loans($pdo, $month, $rules);
    }
}

/** Changes the date the member collection was taken. Payments and bank withdrawals keep their own dates. */
function cb_set_date(PDO $pdo, string $month, string $date): void
{
    $pdo->prepare('UPDATE cash_months SET entry_date = :d WHERE month_start = :m')->execute([':d' => $date, ':m' => $month]);
    $pdo->prepare('UPDATE collections SET received_on = :d WHERE collection_month = :m AND closure_id IS NULL')->execute([':d' => $date, ':m' => $month]);
}

/** Rebuilds the parsed structure from what is stored for the month (closure rows excluded). */
function cb_parsed_from_db(PDO $pdo, string $month): array
{
    $meta = cb_meta($pdo, $month);
    $date = (string) ($meta['entry_date'] ?? '');

    $rows = [];
    foreach (cb_saved_rows($pdo, $month) as $id => $r) {
        if ($r['closure_id'] === null) {
            unset($r['closure_id']);
            $rows[$id] = $r;
        }
    }

    $bankLines = [];
    $bank = 0.0;
    $payments = [];
    $line = 0;
    $bl = 0;
    $q = $pdo->prepare(
        "SELECT e.side, e.head_id, e.member_id, e.amount, e.narration, e.entry_date
           FROM cash_book_entries e WHERE e.cash_month = :m AND e.closure_id IS NULL ORDER BY e.entry_date, e.id"
    );
    $q->execute([':m' => $month]);
    foreach ($q->fetchAll() as $e) {
        if ($e['side'] === 'R') {
            $bank += (float) $e['amount'];
            $bankLines[] = ['line' => ++$bl, 'date' => (string) $e['entry_date'], 'amount' => (float) $e['amount'], 'narration' => (string) $e['narration']];
        } else {
            $payments[] = ['line' => ++$line, 'head_id' => (int) $e['head_id'],
                           'member_id' => $e['member_id'] !== null ? (int) $e['member_id'] : null,
                           'amount' => (float) $e['amount'], 'date' => (string) $e['entry_date'], 'narration' => (string) $e['narration']];
        }
    }

    return ['date' => $date, 'rows' => $rows, 'bank' => $bank, 'bank_lines' => $bankLines, 'payments' => $payments];
}

/** Fills in zero rows so every member has an entry (the checks expect that). */
function cb_complete_rows(array $parsed, array $ctx): array
{
    $blank = ['thrift' => 0.0, 'share' => 0.0, 'mt_total' => 0.0, 'mt_interest' => 0.0, 'mt_principal' => 0.0,
              'st_total' => 0.0, 'st_interest' => 0.0, 'st_principal' => 0.0,
              'admission' => 0.0, 'guarantee' => 0.0, 'others' => 0.0];
    foreach ($ctx['members'] as $id => $_) {
        if (in_array($id, $ctx['closing'], true)) {
            unset($parsed['rows'][$id]);
        } elseif (!isset($parsed['rows'][$id])) {
            $parsed['rows'][$id] = $blank;
        }
    }
    return $parsed;
}

/** Members whose row has not been saved yet for the month (closing members count as saved). */
function cb_unsaved_members(PDO $pdo, string $month, array $ctx): array
{
    $have = cb_saved_rows($pdo, $month);
    $out = [];
    foreach ($ctx['members'] as $id => $m) {
        if (!isset($have[$id])) {
            $out[$id] = $m;
        }
    }
    return $out;
}

// --------------------------------------------- keeping every month consistent

/**
 * Checks the saved months from $fromMonth onwards against each other. Run after any change to a saved month,
 * inside the transaction, so a change that would break a later month is refused and undone:
 *   - no loan repayment is more than the loan outstanding at the start of that month
 *   - no refund is more than the balance
 *   - no balance (share, thrift, guarantee, MT, ST) is ever below zero
 *   - a closed account stays at exactly zero
 */
function cb_chain_check(PDO $pdo, string $fromMonth): array
{
    $errors = [];
    $first  = cb_first_month($pdo);
    $names  = [];
    foreach ($pdo->query('SELECT id, full_name FROM members') as $r) {
        $names[(int) $r['id']] = $r['full_name'];
    }
    $q = $pdo->prepare("SELECT month_start FROM cash_months WHERE status = 'final' AND month_start >= :f ORDER BY month_start");
    $q->execute([':f' => $fromMonth]);
    $months = $q->fetchAll(PDO::FETCH_COLUMN);

    $end = [];
    $endOf = function (string $m) use ($pdo, &$end): array {
        return $end[$m] ??= get_balances($pdo, null, $m);
    };
    $labels = ['share' => 'share', 'thrift' => 'thrift fund', 'guarantee' => 'guarantee fund', 'mt' => 'MT loan', 'st' => 'ST loan'];

    foreach ($months as $m) {
        $prev = cb_add_month($m, -1);
        $open = $prev < $first ? get_balances($pdo, null, BALANCES_OPENING) : $endOf($prev);
        $ml   = cb_month_label($m);

        $c = $pdo->prepare('SELECT member_id, share, thrift, guarantee, mt_principal, st_principal FROM collections WHERE collection_month = :m');
        $c->execute([':m' => $m]);
        $paid = [];
        foreach ($c->fetchAll() as $r) {
            $id = (int) $r['member_id'];
            $paid[$id] = $r;
            foreach (['mt' => 'MT', 'st' => 'ST'] as $t => $T) {
                if ((float) $r[$t . '_principal'] > ($open[$id][$t] ?? 0) + 0.004) {
                    $errors[] = $ml . ': ' . $names[$id] . ' would repay ₹' . inr_auto((float) $r[$t . '_principal']) . ' of ' . $T
                              . ' principal, but only ₹' . inr_auto(max(0.0, $open[$id][$t] ?? 0)) . ' is outstanding at the start of the month.';
                }
            }
        }

        $f = $pdo->prepare("SELECT member_id, fund, SUM(amount) AS a FROM fund_interest WHERE cash_month = :m GROUP BY member_id, fund");
        $f->execute([':m' => $m]);
        $fi = [];
        foreach ($f->fetchAll() as $r) {
            $fi[(int) $r['member_id']][$r['fund']] = (float) $r['a'];
        }

        $e = $pdo->prepare(
            "SELECT e.member_id, h.effect, SUM(e.amount) AS a
               FROM cash_book_entries e JOIN payment_heads h ON h.id = e.head_id
              WHERE e.cash_month = :m AND e.member_id IS NOT NULL AND h.effect IN ('share_refund','thrift_refund','guarantee_refund')
           GROUP BY e.member_id, h.effect"
        );
        $e->execute([':m' => $m]);
        foreach ($e->fetchAll() as $r) {
            $id = (int) $r['member_id'];
            $k  = str_replace('_refund', '', $r['effect']);
            $available = ($open[$id][$k] ?? 0) + (float) ($paid[$id][$k] ?? 0) + ($fi[$id][$k] ?? 0);
            if ((float) $r['a'] > $available + 0.004) {
                $errors[] = $ml . ': ' . $names[$id] . ' would be refunded ₹' . inr_auto((float) $r['a']) . ' of ' . $labels[$k]
                          . ', but the balance is only ₹' . inr_auto(max(0.0, $available)) . '.';
            }
        }

        foreach ($endOf($m) as $id => $b) {
            foreach ($labels as $k => $label) {
                if ($b[$k] < -0.004) {
                    $errors[] = $ml . ': ' . $names[$id] . '\'s ' . $label . ' would end the month at −₹' . inr_auto(abs($b[$k])) . '.';
                }
            }
        }
    }

    // a closed account must stay at zero
    $cl = $pdo->query("SELECT member_id, cash_month FROM member_closures WHERE status = 'final'")->fetchAll();
    foreach ($cl as $r) {
        $id = (int) $r['member_id'];
        $b  = $endOf($r['cash_month'])[$id] ?? null;
        if ($b === null) {
            continue;
        }
        $left = [];
        foreach ($labels as $k => $label) {
            if (abs($b[$k]) >= 0.005) {
                $left[] = $label . ' ₹' . inr_auto($b[$k]);
            }
        }
        if ($left) {
            $errors[] = $names[$id] . '\'s account was closed in ' . cb_month_label($r['cash_month'])
                      . '; this change would leave ' . implode(', ', $left) . ' on it. The closing settlement would have to be redone.';
        }
    }

    $errors = array_values(array_unique($errors));
    if (count($errors) > 12) {
        $more = count($errors) - 12;
        $errors = array_slice($errors, 0, 12);
        $errors[] = '…and ' . $more . ' more.';
    }
    return $errors;
}

/**
 * After a change to a saved month: later months where a recorded loan interest differs from what the rate would give
 * on that month's opening balance. Information only; recorded amounts are never changed automatically.
 */
function cb_interest_notes(PDO $pdo, string $afterMonth): array
{
    $first = cb_first_month($pdo);
    $rules = cb_rules($pdo);
    $names = [];
    foreach ($pdo->query('SELECT id, full_name FROM members') as $r) {
        $names[(int) $r['id']] = $r['full_name'];
    }
    $q = $pdo->prepare("SELECT month_start FROM cash_months WHERE status = 'final' AND month_start > :f ORDER BY month_start");
    $q->execute([':f' => $afterMonth]);
    $notes = [];
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $prev = cb_add_month($m, -1);
        $open = get_balances($pdo, null, $prev < $first ? BALANCES_OPENING : $prev);
        $newLoans = cb_new_loans_prev($pdo, $m);
        $c = $pdo->prepare('SELECT member_id, mt_principal + mt_interest AS mt_total, mt_interest, st_principal + st_interest AS st_total, st_interest
                              FROM collections WHERE collection_month = :m AND closure_id IS NULL');
        $c->execute([':m' => $m]);
        foreach ($c->fetchAll() as $r) {
            $id = (int) $r['member_id'];
            foreach (['mt' => 'MT', 'st' => 'ST'] as $t => $T) {
                if ((float) $r[$t . '_total'] <= 0) {
                    continue;
                }
                $calc = cb_interest_calc((float) ($open[$id][$t] ?? 0), (float) $rules[$t . '_rate'], $prev, $newLoans[$id][strtoupper($t)] ?? [])['amount'];
                if (abs($calc - (float) $r[$t . '_interest']) > 1.5) {
                    $notes[] = cb_month_label($m) . ': ' . $names[$id] . ' ' . $T . ' interest recorded ₹' . inr_auto((float) $r[$t . '_interest'])
                             . '; the rate now gives ₹' . inr_auto($calc) . '.';
                }
            }
        }
    }
    if (count($notes) > 10) {
        $more = count($notes) - 10;
        $notes = array_slice($notes, 0, 10);
        $notes[] = '…and ' . $more . ' more.';
    }
    return $notes;
}

// ------------------------------------------------------------ cash in hand

/** Money received and paid out in every month that has any entry (draft or saved). */
function cb_cash_flows(PDO $pdo): array
{
    $out = [];
    $q = $pdo->query(
        'SELECT collection_month AS m,
                SUM(thrift + share + mt_principal + mt_interest + st_principal + st_interest + admission + guarantee + others) AS a
           FROM collections GROUP BY collection_month'
    );
    foreach ($q->fetchAll() as $r) {
        $out[$r['m']]['in'] = (float) $r['a'];
    }
    $q = $pdo->query('SELECT cash_month AS m, side, SUM(amount) AS a FROM cash_book_entries GROUP BY cash_month, side');
    foreach ($q->fetchAll() as $r) {
        $k = $r['side'] === 'R' ? 'in' : 'out';
        $out[$r['m']][$k] = ($out[$r['m']][$k] ?? 0.0) + (float) $r['a'];
    }
    foreach ($out as $m => $_) {
        $out[$m] += ['in' => 0.0, 'out' => 0.0];
    }
    return $out;
}

/**
 * Cash in hand at the start of a month: the opening cash in hand (a rule, for the day the system starts)
 * plus everything received and minus everything paid in the saved months before it.
 */
function cb_cash_opening(PDO $pdo, string $month): float
{
    $cash  = (float) (cb_rules($pdo)['opening_cash'] ?? 0);
    $flows = cb_cash_flows($pdo);
    $q = $pdo->prepare("SELECT month_start FROM cash_months WHERE status = 'final' AND month_start < :m ORDER BY month_start");
    $q->execute([':m' => $month]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $cash += ($flows[$m]['in'] ?? 0) - ($flows[$m]['out'] ?? 0);
    }
    return round($cash, 2);
}

/** One month: opening + income - expenses = closing cash in hand. */
function cb_cash_month(PDO $pdo, string $month): array
{
    $open = cb_cash_opening($pdo, $month);
    $f    = cb_cash_flows($pdo)[$month] ?? ['in' => 0.0, 'out' => 0.0];
    return ['opening' => $open, 'in' => round($f['in'], 2), 'out' => round($f['out'], 2),
            'closing' => round($open + $f['in'] - $f['out'], 2)];
}

/**
 * The financial year up to and including a month: cash in hand at the start of the year, income and expenses
 * of the year so far, and the closing cash in hand (opening + income - expenses).
 */
function cb_cash_year(PDO $pdo, string $month): array
{
    $first = (string) cb_first_month($pdo);
    $y     = (int) date('Y', strtotime($month));
    $fy    = ((int) date('n', strtotime($month)) >= 4) ? sprintf('%04d-04-01', $y) : sprintf('%04d-04-01', $y - 1);
    $from  = max($fy, $first);
    $flows = cb_cash_flows($pdo);

    $in = $out = 0.0;
    for ($m = $from; $m <= $month; $m = cb_add_month($m)) {
        $in  += $flows[$m]['in'] ?? 0;
        $out += $flows[$m]['out'] ?? 0;
    }
    $open = cb_cash_opening($pdo, $from);
    return ['from' => $from, 'to' => $month, 'opening' => $open, 'in' => round($in, 2), 'out' => round($out, 2),
            'closing' => round($open + $in - $out, 2)];
}

// ------------------------------------------------------------- reporting

/** Everything recorded for a month (draft or final), for the summary and the Cash Book view. */
function cb_report(PDO $pdo, string $month): array
{
    $meta = $pdo->prepare('SELECT * FROM cash_months WHERE month_start = :m');
    $meta->execute([':m' => $month]);
    $meta = $meta->fetch() ?: null;

    $q = $pdo->prepare(
        "SELECT m.id, m.member_no, m.full_name, m.status,
                c.thrift, c.share, c.mt_principal, c.mt_interest, c.st_principal, c.st_interest,
                c.admission, c.guarantee, c.others, c.closure_id
           FROM members m
      LEFT JOIN collections c ON c.member_id = m.id AND c.collection_month = :m
          WHERE m.status = 'active' OR c.id IS NOT NULL
       ORDER BY m.member_no"
    );
    $q->execute([':m' => $month]);
    $cols = ['thrift', 'share', 'mt_principal', 'mt_interest', 'st_principal', 'st_interest', 'admission', 'guarantee', 'others'];
    $rows = [];
    $tot  = array_fill_keys($cols, 0.0);
    foreach ($q->fetchAll() as $r) {
        $row = ['id' => (int) $r['id'], 'no' => (int) $r['member_no'], 'name' => $r['full_name'],
                'closure_id' => $r['closure_id'] !== null ? (int) $r['closure_id'] : null];
        foreach ($cols as $c) {
            $row[$c] = (float) $r[$c];
            $tot[$c] += $row[$c];
        }
        $row['total'] = $row['thrift'] + $row['share'] + $row['mt_principal'] + $row['mt_interest'] + $row['st_principal']
                      + $row['st_interest'] + $row['admission'] + $row['guarantee'] + $row['others'];
        $rows[] = $row;
    }
    $memberReceipts = array_sum($tot);

    $q = $pdo->prepare(
        "SELECT e.id, e.entry_date, e.amount, e.narration FROM cash_book_entries e
          WHERE e.cash_month = :m AND e.side = 'R' ORDER BY e.entry_date, e.id"
    );
    $q->execute([':m' => $month]);
    $bankLines = $q->fetchAll();
    $bank = 0.0;
    foreach ($bankLines as $b) {
        $bank += (float) $b['amount'];
    }

    $q = $pdo->prepare(
        "SELECT e.id, e.entry_date, e.amount, e.narration, e.closure_id, h.name AS head_name, h.effect, m.member_no, m.full_name
           FROM cash_book_entries e
           JOIN payment_heads h ON h.id = e.head_id
      LEFT JOIN members m ON m.id = e.member_id
          WHERE e.cash_month = :m AND e.side = 'P'
       ORDER BY e.entry_date, e.id"
    );
    $q->execute([':m' => $month]);
    $payments = $q->fetchAll();
    $paymentsTotal = 0.0;
    $byHead = [];
    foreach ($payments as $p) {
        $paymentsTotal += (float) $p['amount'];
        $byHead[$p['head_name']] = ($byHead[$p['head_name']] ?? 0) + (float) $p['amount'];
    }

    $q = $pdo->prepare(
        'SELECT cl.*, m.full_name, m.member_no FROM member_closures cl JOIN members m ON m.id = cl.member_id WHERE cl.cash_month = :m'
    );
    $q->execute([':m' => $month]);

    return ['month' => $month, 'meta' => $meta, 'rows' => $rows, 'totals' => $tot,
            'member_receipts' => $memberReceipts, 'bank' => $bank, 'bank_lines' => $bankLines,
            'receipts_total' => $memberReceipts + $bank,
            'payments' => $payments, 'payments_total' => $paymentsTotal, 'by_head' => $byHead,
            'net' => $memberReceipts + $bank - $paymentsTotal,
            'closures' => $q->fetchAll(), 'cash' => cb_cash_month($pdo, $month), 'cash_year' => cb_cash_year($pdo, $month)];
}

// ------------------------------------------------------------- finalising

/** Saves a draft month for good. Returns a list of errors (empty = saved). */
function cb_finalize(PDO $pdo, string $month, int $userId): array
{
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT status, entry_date FROM cash_months WHERE month_start = :m FOR UPDATE');
        $q->execute([':m' => $month]);
        $meta = $q->fetch();
        if (!$meta || $meta['status'] !== 'draft') {
            $pdo->rollBack();
            return ['This month is not open for saving.'];
        }
        $open = cb_open_month($pdo);
        if (!$open || $open['month'] !== $month) {
            $pdo->rollBack();
            return ['Earlier months must be saved first.'];
        }
        if (empty($meta['entry_date'])) {
            $pdo->rollBack();
            return ['Save the date of the collection first.'];
        }

        $ctx = cb_ctx($pdo, $month);
        $missing = cb_unsaved_members($pdo, $month, $ctx);
        if ($missing) {
            $pdo->rollBack();
            return ['Press Save for every member first. Still to save: ' . implode(', ', array_map(fn ($m) => $m['name'], $missing)) . '.'];
        }

        $parsed = cb_complete_rows(cb_parsed_from_db($pdo, $month), $ctx);
        $errors = cb_check($parsed, $ctx);
        if ($errors) {
            $pdo->rollBack();
            return $errors;
        }

        cb_create_loans($pdo, $month, $ctx['rules']);

        // Closed accounts leave the member list.
        $pdo->prepare("UPDATE members SET status = 'inactive'
                        WHERE id IN (SELECT member_id FROM member_closures WHERE cash_month = :m)")->execute([':m' => $month]);
        $pdo->prepare("UPDATE member_closures SET status = 'final' WHERE cash_month = :m")->execute([':m' => $month]);

        $pdo->prepare("UPDATE cash_months SET status = 'final', finalized_by = :u, finalized_at = NOW() WHERE month_start = :m")
            ->execute([':u' => $userId, ':m' => $month]);

        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ------------------------------------------------------- account closure

/** Interest accrued on a fund from the start of the financial year to the closing date. */
function cb_fund_interest_accrued(PDO $pdo, int $memberId, string $fund, float $ratePct, string $closingDate): float
{
    $closeMonth = cb_month_start($closingDate);
    $y  = (int) date('Y', strtotime($closingDate));
    $fy = ((int) date('n', strtotime($closingDate)) >= 4) ? sprintf('%04d-04-01', $y) : sprintf('%04d-04-01', $y - 1);
    $first = cb_first_month($pdo);
    $m = max($fy, $first);

    $total = 0.0;
    for (; $m <= $closeMonth; $m = cb_add_month($m)) {
        $through = cb_add_month($m, -1);
        $bal = get_balances($pdo, $memberId, $through < $first ? BALANCES_OPENING : $through)[$memberId][$fund] ?? 0.0;
        $days = ($m === $closeMonth) ? (int) date('j', strtotime($closingDate)) : cb_days_in_month($m);
        $total += $bal * $ratePct / 100 * $days / 365;
    }
    return round($total);
}

/** The settlement for closing a member's account. $override may carry edited interest amounts. */
function cb_closure_compute(PDO $pdo, int $memberId, string $closingDate, array $override = []): array
{
    $rules = cb_rules($pdo);
    $b     = get_balances($pdo, $memberId)[$memberId];
    $day   = (int) date('j', strtotime($closingDate));

    $ti = $override['thrift_interest']    ?? cb_fund_interest_accrued($pdo, $memberId, 'thrift', (float) $rules['thrift_interest_rate'], $closingDate);
    $gi = $override['guarantee_interest'] ?? cb_fund_interest_accrued($pdo, $memberId, 'guarantee', (float) $rules['guarantee_interest_rate'], $closingDate);
    // Loan interest at closing: last month's interest as the sheet would collect it, plus the days of this month up to the closing date.
    $month = cb_month_start($closingDate);
    $mi = $override['mt_interest'] ?? (cb_arrears_interest($pdo, $memberId, 'mt', $month, $b['mt'])['amount']
                                       + cb_suggest_interest($b['mt'], (float) $rules['mt_rate'], $day));
    $si = $override['st_interest'] ?? (cb_arrears_interest($pdo, $memberId, 'st', $month, $b['st'])['amount']
                                       + cb_suggest_interest($b['st'], (float) $rules['st_rate'], $day));

    $c = [
        'share_refund' => $b['share'],
        'thrift_balance' => $b['thrift'], 'thrift_interest' => (float) $ti,
        'guarantee_balance' => $b['guarantee'], 'guarantee_interest' => (float) $gi,
        'mt_principal' => $b['mt'], 'mt_interest' => (float) $mi,
        'st_principal' => $b['st'], 'st_interest' => (float) $si,
    ];
    $c['refund_total'] = round($c['share_refund'] + $c['thrift_balance'] + $c['thrift_interest']
                             + $c['guarantee_balance'] + $c['guarantee_interest'], 2);
    $c['dues_total']   = round($c['mt_principal'] + $c['mt_interest'] + $c['st_principal'] + $c['st_interest'], 2);
    $c['net_payable']  = round($c['refund_total'] - $c['dues_total'], 2);
    return $c;
}

/** Adds a member's closure to the open month's cash book. Returns the closure id. */
function cb_closure_create(PDO $pdo, int $memberId, string $month, string $closingDate, array $c, int $userId): int
{
    $heads = $pdo->query("SELECT effect, id FROM payment_heads WHERE side = 'P' AND effect IN ('share_refund','thrift_refund','guarantee_refund')")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);

    $pdo->beginTransaction();
    try {
        cb_ensure_draft($pdo, $month, $userId);

        // Anything already typed for this member this month is replaced by the closure.
        $pdo->prepare('DELETE FROM collections WHERE member_id = :mid AND collection_month = :m AND closure_id IS NULL')
            ->execute([':mid' => $memberId, ':m' => $month]);
        $pdo->prepare('DELETE FROM cash_book_entries WHERE member_id = :mid AND cash_month = :m AND closure_id IS NULL')
            ->execute([':mid' => $memberId, ':m' => $month]);

        $pdo->prepare(
            'INSERT INTO member_closures (member_id, cash_month, closing_date, share_refund, thrift_balance, thrift_interest,
                    guarantee_balance, guarantee_interest, mt_principal, mt_interest, st_principal, st_interest, net_payable, created_by)
             VALUES (:mid, :m, :d, :sr, :tb, :ti, :gb, :gi, :mp, :mi, :sp, :si, :net, :u)'
        )->execute([':mid' => $memberId, ':m' => $month, ':d' => $closingDate, ':sr' => $c['share_refund'],
                    ':tb' => $c['thrift_balance'], ':ti' => $c['thrift_interest'], ':gb' => $c['guarantee_balance'],
                    ':gi' => $c['guarantee_interest'], ':mp' => $c['mt_principal'], ':mi' => $c['mt_interest'],
                    ':sp' => $c['st_principal'], ':si' => $c['st_interest'], ':net' => $c['net_payable'], ':u' => $userId]);
        $closureId = (int) $pdo->lastInsertId();

        // Loan dues recovered from the settlement.
        $pdo->prepare(
            'INSERT INTO collections (member_id, collection_month, received_on, mt_principal, mt_interest, st_principal, st_interest,
                                      closure_id, remarks, entered_by)
             VALUES (:mid, :m, :d, :mp, :mi, :sp, :si, :cl, :r, :u)'
        )->execute([':mid' => $memberId, ':m' => $month, ':d' => $closingDate, ':mp' => $c['mt_principal'], ':mi' => $c['mt_interest'],
                    ':sp' => $c['st_principal'], ':si' => $c['st_interest'], ':cl' => $closureId, ':r' => 'Account closure', ':u' => $userId]);

        // Interest credited to the funds, so the refunds clear them to zero.
        $fi = $pdo->prepare(
            'INSERT INTO fund_interest (member_id, cash_month, fund, credited_on, amount, closure_id, narration)
             VALUES (:mid, :m, :f, :d, :a, :cl, :n)'
        );
        foreach (['thrift' => $c['thrift_interest'], 'guarantee' => $c['guarantee_interest']] as $fund => $amt) {
            if ($amt > 0) {
                $fi->execute([':mid' => $memberId, ':m' => $month, ':f' => $fund, ':d' => $closingDate, ':a' => $amt,
                              ':cl' => $closureId, ':n' => 'Interest on account closure']);
            }
        }

        // The refunds.
        $pay = $pdo->prepare(
            "INSERT INTO cash_book_entries (cash_month, entry_date, side, head_id, amount, member_id, closure_id, narration, entered_by)
             VALUES (:m, :d, 'P', :h, :a, :mid, :cl, 'Account closure', :u)"
        );
        $lines = ['share_refund' => $c['share_refund'],
                  'thrift_refund' => $c['thrift_balance'] + $c['thrift_interest'],
                  'guarantee_refund' => $c['guarantee_balance'] + $c['guarantee_interest']];
        foreach ($lines as $effect => $amt) {
            if ($amt > 0) {
                $pay->execute([':m' => $month, ':d' => $closingDate, ':h' => $heads[$effect], ':a' => round($amt, 2),
                               ':mid' => $memberId, ':cl' => $closureId, ':u' => $userId]);
            }
        }

        $pdo->commit();
        return $closureId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Removes a draft closure and all the lines it added. */
function cb_closure_cancel(PDO $pdo, int $closureId): void
{
    $st = $pdo->prepare('SELECT status FROM member_closures WHERE id = :id');
    $st->execute([':id' => $closureId]);
    if ($st->fetchColumn() !== 'draft') {
        return; // a saved closure can never be removed
    }
    $pdo->beginTransaction();
    try {
        foreach (['collections', 'cash_book_entries', 'fund_interest'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE closure_id = :id")->execute([':id' => $closureId]);
        }
        $pdo->prepare("DELETE FROM member_closures WHERE id = :id AND status = 'draft'")->execute([':id' => $closureId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
