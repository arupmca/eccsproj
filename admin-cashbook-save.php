<?php
declare(strict_types=1);

// The save endpoint behind the Cash Book sheet. It answers with JSON.
//   part=row       one member's row (the Save button at the end of the row)
//   part=payments  the bank withdrawals and the payment lines (each with its own date)
//   part=date      the date of the collection
// In a month still being entered, a save is simply stored. In a month that is already saved, a save is applied
// at once, but only if every later month still adds up; otherwise it is undone and the reasons are returned.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cashbook-lib.php';

$user = require_login('admin', false, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'errors' => ['Use the Save buttons on the sheet.']], 405);
}
if (!csrf_valid()) {
    json_out(['ok' => false, 'errors' => ['Your page expired. Reload the page and try again.']], 400);
}

$pdo   = db();
$uid   = (int) $user['id'];
$month = cb_month_param((string) ($_POST['month'] ?? ''));
$part  = (string) ($_POST['part'] ?? '');

$state = null;
foreach (cb_month_states($pdo) as $s) {
    if ($s['month'] === $month) {
        $state = $s['state'];
    }
}
if ($month === null || !in_array($state, ['final', 'draft', 'next'], true)) {
    json_out(['ok' => false, 'errors' => ['That month cannot be edited now.']], 409);
}
$isFinal = $state === 'final';
$ctx     = cb_ctx($pdo, $month);
$meta    = cb_meta($pdo, $month);

/** Makes sure the month exists as a draft and has its collection date; returns the date. */
$prepare = function (array &$errors) use ($pdo, $month, $uid, $isFinal, $meta): string {
    $date = (string) ($meta['entry_date'] ?? '');
    if ($date === '') {
        $date = cb_parse_date($_POST['date'] ?? '', $month, $errors);
    }
    if ($errors) {
        return '';
    }
    if (!$isFinal) {
        cb_ensure_draft($pdo, $month, $uid);
    }
    if (($meta['entry_date'] ?? '') === '') {
        $pdo->prepare('UPDATE cash_months SET entry_date = :d WHERE month_start = :m')->execute([':d' => $date, ':m' => $month]);
    }
    return $date;
};

/** For a saved month: check that every later month still adds up, or undo. */
$guard = function () use ($pdo, $month, $isFinal): array {
    return $isFinal ? cb_chain_check($pdo, $month) : [];
};

$progress = function () use ($pdo, $month, $ctx): array {
    $have = cb_saved_rows($pdo, $month);
    $n = 0;
    foreach ($ctx['members'] as $id => $_) {
        $n += isset($have[$id]) ? 1 : 0;
    }
    return ['saved' => $n, 'total' => count($ctx['members'])];
};

$refuse = function (array $errors, string $why = '') {
    json_out(['ok' => false, 'errors' => $why !== '' ? array_merge([$why], $errors) : $errors], 422);
};
$notBroken = 'This change was not saved, because it would make a later month inconsistent:';

try {
    // ------------------------------------------------------------------ one member's row
    if ($part === 'row') {
        $id = (int) ($_POST['member_id'] ?? 0);
        $m  = $ctx['members'][$id] ?? null;
        if ($m === null || in_array($id, $ctx['closing'], true)) {
            json_out(['ok' => false, 'errors' => ['That member cannot be edited in this month.']], 422);
        }
        $errors = [];
        $in  = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
        $row = cb_parse_row($in, $m, $ctx['sug'][$id], $errors);
        $errors = array_merge($errors, cb_check_row($row, $m, $ctx['rules']));
        $date = $prepare($errors);
        if ($errors) {
            $refuse($errors);
        }

        $pdo->beginTransaction();
        cb_row_save($pdo, $month, $id, $row, $date, $uid);
        $broken = $guard();
        if ($broken) {
            $pdo->rollBack();
            $refuse($broken, $notBroken);
        }
        $pdo->commit();

        $strings = cb_row_strings($row);
        $strings['mt_pr'] = $row['mt_total'] > 0 ? cb_fmt_input($row['mt_principal']) : '';
        $strings['st_pr'] = $row['st_total'] > 0 ? cb_fmt_input($row['st_principal']) : '';
        json_out(['ok' => true, 'row' => $strings, 'date' => $date, 'progress' => $progress(),
                  'notes' => $isFinal ? cb_interest_notes($pdo, $month) : []]);
    }

    // ------------------------------------------------------------------ bank withdrawals and payments
    // Each line carries its own date, so the collection date is not needed here.
    if ($part === 'payments') {
        [$payments, $bankLines, $errors] = cb_parse_payments($_POST, $month);
        if (!$errors) {
            $parsed = cb_complete_rows(cb_parsed_from_db($pdo, $month), $ctx);
            $parsed['payments'] = $payments;
            $errors = cb_check_payments($parsed, $ctx);
        }
        if ($errors) {
            $refuse($errors);
        }

        $pdo->beginTransaction();
        if (!$isFinal) {
            cb_ensure_draft($pdo, $month, $uid);
        }
        cb_payments_save($pdo, $month, $payments, $bankLines, $uid, $isFinal, $ctx['rules']);
        $broken = $guard();
        if ($broken) {
            $pdo->rollBack();
            $refuse($broken, $notBroken);
        }
        $pdo->commit();
        json_out(['ok' => true, 'date' => (string) ($meta['entry_date'] ?? ''), 'progress' => $progress(),
                  'cash' => cb_cash_month($pdo, $month),
                  'notes' => $isFinal ? cb_interest_notes($pdo, $month) : []]);
    }

    // ------------------------------------------------------------------ the date
    if ($part === 'date') {
        $errors = [];
        $date = cb_parse_date($_POST['date'] ?? '', $month, $errors);
        if ($errors) {
            $refuse($errors);
        }
        $pdo->beginTransaction();
        if (!$isFinal) {
            cb_ensure_draft($pdo, $month, $uid);
        }
        cb_set_date($pdo, $month, $date);
        $pdo->commit();
        json_out(['ok' => true, 'date' => $date, 'progress' => $progress(), 'notes' => []]);
    }

    json_out(['ok' => false, 'errors' => ['Unknown action.']], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('cashbook save failed: ' . $e->getMessage());
    json_out(['ok' => false, 'errors' => ['Something went wrong and nothing was saved. Please try again.']], 500);
}
