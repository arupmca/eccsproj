<?php
declare(strict_types=1);

/** Pass this as $through to get the opening balances only. */
const BALANCES_OPENING = '1900-01-01';

/** Format a number the Indian way: 1,00,000 or 12,34,567.50 */
function inr(float|int|string|null $value, int $decimals = 0): string
{
    $v   = (float) $value;
    $neg = $v < 0;
    $parts = explode('.', number_format(abs($v), $decimals, '.', ''));
    $int   = $parts[0];
    $frac  = $parts[1] ?? '';

    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3));
        $int   = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
}

/** Like inr(), but shows paise only when the amount has them. */
function inr_auto(float|int|string|null $value): string
{
    $v = (float) $value;
    return inr($v, abs($v - round($v)) < 0.005 ? 0 : 2);
}

/** An amount with the rupee sign, for display: 5,000 becomes ₹5,000. (Escape it with e() when printing.) */
function rs(float|int|string|null $value): string
{
    return "\u{20B9}" . inr_auto($value);
}

/** First day of the latest month that has been saved as final, or null. */
function latest_final_month(PDO $pdo): ?string
{
    $m = $pdo->query("SELECT MAX(month_start) FROM cash_months WHERE status = 'final'")->fetchColumn();
    return $m ? (string) $m : null;
}

/** The date of the opening balances (e.g. 2026-04-01), or null. */
function opening_date(PDO $pdo): ?string
{
    $d = $pdo->query('SELECT MAX(as_on) FROM opening_balances')->fetchColumn();
    return $d ? (string) $d : null;
}

/**
 * Position of members: opening balances plus every FINAL month recorded since.
 * Returns [member_id => ['share','thrift','guarantee','mt','st']] as floats.
 *
 *   $memberId  just that member (or null for everyone)
 *   $through   null = all final months; 'YYYY-MM-01' = up to and including that month;
 *              BALANCES_OPENING = the opening balances only
 *
 *   share      = opening + shares bought - share refunds
 *   thrift     = opening (principal + interest) + deposits + interest credited - refunds
 *   guarantee  = opening (principal + interest) + deposits + interest credited - refunds
 *   mt / st    = opening principal + loans issued - principal repaid
 */
function get_balances(PDO $pdo, ?int $memberId = null, ?string $through = null): array
{
    $cLimit = $fLimit = $eLimit = '';
    $lLimit = '';
    $params = [];
    if ($through !== null) {
        $cLimit = ' WHERE c.collection_month <= :t1';
        $fLimit = ' AND f.cash_month <= :t2';
        $eLimit = ' AND e.cash_month <= :t4';
        $lLimit = ' AND issued_on < DATE_ADD(:t3, INTERVAL 1 MONTH)';
        $params = [':t1' => $through, ':t2' => $through, ':t3' => $through, ':t4' => $through];
    }

    $sql = "SELECT m.id AS member_id,
                COALESCE(ob.share, 0) + COALESCE(c.share, 0) - COALESCE(r.share_ref, 0) AS share,
                COALESCE(ob.thrift_principal, 0) + COALESCE(ob.thrift_interest, 0)
                    + COALESCE(c.thrift, 0) + COALESCE(fi.thrift, 0) - COALESCE(r.thrift_ref, 0) AS thrift,
                COALESCE(ob.guarantee_principal, 0) + COALESCE(ob.guarantee_interest, 0)
                    + COALESCE(c.guarantee, 0) + COALESCE(fi.guarantee, 0) - COALESCE(r.guarantee_ref, 0) AS guarantee,
                COALESCE(ob.mt_principal, 0) + COALESCE(l.mt, 0) - COALESCE(c.mt_principal, 0) AS mt,
                COALESCE(ob.st_principal, 0) + COALESCE(l.st, 0) - COALESCE(c.st_principal, 0) AS st
              FROM members m
         LEFT JOIN opening_balances ob
                ON ob.member_id = m.id
               AND ob.as_on = (SELECT MAX(as_on) FROM opening_balances)
         LEFT JOIN (SELECT c.member_id, SUM(c.share) AS share, SUM(c.thrift) AS thrift,
                           SUM(c.guarantee) AS guarantee,
                           SUM(c.mt_principal) AS mt_principal, SUM(c.st_principal) AS st_principal
                      FROM collections c
                      JOIN cash_months cm ON cm.month_start = c.collection_month AND cm.status = 'final'
                     $cLimit
                  GROUP BY c.member_id) c ON c.member_id = m.id
         LEFT JOIN (SELECT f.member_id,
                           SUM(CASE WHEN f.fund = 'thrift'    THEN f.amount END) AS thrift,
                           SUM(CASE WHEN f.fund = 'guarantee' THEN f.amount END) AS guarantee
                      FROM fund_interest f
                      JOIN cash_months cm ON cm.month_start = f.cash_month AND cm.status = 'final'
                     WHERE 1 = 1 $fLimit
                  GROUP BY f.member_id) fi ON fi.member_id = m.id
         LEFT JOIN (SELECT member_id,
                           SUM(CASE WHEN loan_type = 'MT' THEN amount END) AS mt,
                           SUM(CASE WHEN loan_type = 'ST' THEN amount END) AS st
                      FROM loans WHERE status = 'issued' $lLimit
                  GROUP BY member_id) l ON l.member_id = m.id
         LEFT JOIN (SELECT e.member_id,
                           SUM(CASE WHEN h.effect = 'share_refund'     THEN e.amount END) AS share_ref,
                           SUM(CASE WHEN h.effect = 'thrift_refund'    THEN e.amount END) AS thrift_ref,
                           SUM(CASE WHEN h.effect = 'guarantee_refund' THEN e.amount END) AS guarantee_ref
                      FROM cash_book_entries e
                      JOIN payment_heads h ON h.id = e.head_id
                      JOIN cash_months cm ON cm.month_start = e.cash_month AND cm.status = 'final'
                     WHERE e.member_id IS NOT NULL
                       AND h.effect IN ('share_refund','thrift_refund','guarantee_refund') $eLimit
                  GROUP BY e.member_id) r ON r.member_id = m.id";

    if ($memberId !== null) {
        $sql .= ' WHERE m.id = :mid';
        $params[':mid'] = $memberId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['member_id']] = [
            'share'     => round((float) $r['share'], 2),
            'thrift'    => round((float) $r['thrift'], 2),
            'guarantee' => round((float) $r['guarantee'], 2),
            'mt'        => round((float) $r['mt'], 2),
            'st'        => round((float) $r['st'], 2),
        ];
    }
    return $out;
}
