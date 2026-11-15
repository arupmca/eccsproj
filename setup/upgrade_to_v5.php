<?php
// ONE-TIME UPGRADE SCRIPT  (keep it in the folder  eccsproj/setup/ )
// Open in the browser:  http://localhost/eccsproj/setup/upgrade_to_v5.php
// Then DELETE this file.
//
// Small, safe changes to the data you already have. Nothing is deleted:
//   1. the expense head "Donation to Co-Operative Society" is renamed "Co-Op Union Fees"
//      (entries already recorded under it keep their amounts and simply show the new name)
//   2. the expense head "Dividend" is added
//   3. the "Opening cash in hand" rule is added (Rs 10,890 on the day the system starts)

declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This script can only be run locally.');
}

require __DIR__ . '/../config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function page(string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Upgrade</title>'
       . '<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;color:#1d2733}'
       . 'button{margin-top:18px;padding:11px 22px;font-size:16px}.err{color:#b3261e}.ok{color:#1e6a3a}code{background:#f4f7fb;padding:2px 5px}'
       . 'li{margin:6px 0}</style></head><body>' . $body . '</body></html>';
    exit;
}

$pdo = db();

foreach (['payment_heads', 'settings', 'cash_book_entries'] as $t) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $q->execute([':t' => $t]);
    if ((int) $q->fetchColumn() === 0) {
        page('<h1>Cannot upgrade</h1><p class="err">The table <code>' . h($t) . '</code> is missing, so this is not the database this script expects. Nothing was changed.</p>');
    }
}

$count = static fn (string $sql, array $p = []): int => (function () use ($pdo, $sql, $p) { $q = $pdo->prepare($sql); $q->execute($p); return (int) $q->fetchColumn(); })();

$hasOld   = $count("SELECT COUNT(*) FROM payment_heads WHERE name = 'Donation to Co-Operative Society' AND side = 'P'") > 0;
$hasNew   = $count("SELECT COUNT(*) FROM payment_heads WHERE name = 'Co-Op Union Fees' AND side = 'P'") > 0;
$hasDiv   = $count("SELECT COUNT(*) FROM payment_heads WHERE name = 'Dividend' AND side = 'P'") > 0;
$hasCash  = $count("SELECT COUNT(*) FROM settings WHERE setting_key = 'opening_cash'") > 0;

$todo = [];
if ($hasOld && !$hasNew) { $todo[] = 'Rename the expense head <b>Donation to Co-Operative Society</b> to <b>Co-Op Union Fees</b>.'; }
if (!$hasDiv)            { $todo[] = 'Add the expense head <b>Dividend</b>.'; }
if (!$hasCash)           { $todo[] = 'Add the rule <b>Opening cash in hand</b> with ₹10,890 (you can change it later under Settings).'; }
$warn = ($hasOld && $hasNew) ? '<p class="err">Both "Donation to Co-Operative Society" and "Co-Op Union Fees" exist, so the rename is skipped. Tell Claude if you see this.</p>' : '';

if (!$todo) {
    page('<h1>Already up to date</h1><p>Nothing to change.</p>' . $warn . '<p>You can delete <code>setup/upgrade_to_v5.php</code>.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($hasOld && !$hasNew) {
            $pdo->exec("UPDATE payment_heads SET name = 'Co-Op Union Fees' WHERE name = 'Donation to Co-Operative Society' AND side = 'P'");
        }
        if (!$hasDiv) {
            $pdo->exec("INSERT INTO payment_heads (name, side, effect, sort_order) VALUES ('Dividend', 'P', 'none', 95)");
        }
        if (!$hasCash) {
            $pdo->exec("INSERT INTO settings (setting_key, setting_value, label, unit, sort_order)
                        VALUES ('opening_cash', '10890', 'Opening cash in hand on the day the system starts', 'Rs', 160)");
        }
    } catch (Throwable $e) {
        page('<h1>Upgrade stopped</h1><p class="err">' . h($e->getMessage()) . '</p><p>You can run this page again.</p>');
    }
    page('<h1 class="ok">Upgrade complete</h1><ul><li>' . implode('</li><li>', $todo) . '</li></ul>'
       . '<p>Now <b>delete</b> <code>setup/upgrade_to_v5.php</code>.</p>');
}

page('<h1>Small upgrade for the cash book</h1><p>This will:</p><ul><li>' . implode('</li><li>', $todo) . '</li></ul>'
   . '<p>Nothing is deleted and no entries change.</p>' . $warn . '<form method="post"><button type="submit">Upgrade now</button></form>');
