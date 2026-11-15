<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/balances.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin-dashboard.php');
}
if (!csrf_valid()) {
    set_flash('err', 'Your page expired. Please try again.');
    redirect('admin-dashboard.php');
}

$pdo      = db();
$memberId = (int) ($_POST['member_id'] ?? 0);

$stmt = $pdo->prepare("SELECT id, full_name FROM members WHERE id = :id AND status = 'active'");
$stmt->execute([':id' => $memberId]);
$member = $stmt->fetch();

if (!$member) {
    set_flash('err', 'That member was not found.');
    redirect('admin-dashboard.php');
}

// The rule is enforced here on the server, whatever the button on the page looked like.
$bal = get_balances($pdo, $memberId)[$memberId] ?? null;
$nonZero = [];
if ($bal !== null) {
    $names = ['share' => 'share', 'thrift' => 'thrift fund', 'guarantee' => 'guarantee fund',
              'mt' => 'MT loan', 'st' => 'ST loan'];
    foreach ($names as $key => $label) {
        if (abs($bal[$key]) >= 0.005) {
            $nonZero[] = $label;
        }
    }
}
if ($nonZero) {
    set_flash('err', $member['full_name'] . ' cannot be deleted yet. These balances are not zero: ' . implode(', ', $nonZero) . '.');
    redirect('admin-dashboard.php');
}

$open = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE member_id = :id AND status IN ('applied','approved')");
$open->execute([':id' => $memberId]);
if ((int) $open->fetchColumn() > 0) {
    set_flash('err', $member['full_name'] . ' has a loan application waiting. Settle it before deleting the member.');
    redirect('admin-dashboard.php');
}

// The member leaves the list and can no longer sign in. The record itself is kept,
// so past cash book and ledger entries stay correct.
$pdo->prepare("UPDATE members SET status = 'inactive' WHERE id = :id")->execute([':id' => $memberId]);

set_flash('ok', $member['full_name'] . ' was deleted from the member list.');
redirect('admin-dashboard.php');
