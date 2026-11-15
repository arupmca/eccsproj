<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/balances.php';
require_once __DIR__ . '/cashbook-lib.php';

// Only members may open this page; the figures shown are always the signed-in member's own.
$user = require_login('member');

$pdo  = db();
$mine = get_balances($pdo, (int) $user['member_id'])[(int) $user['member_id']]
     ?? ['share' => 0, 'thrift' => 0, 'guarantee' => 0, 'mt' => 0, 'st' => 0];

$latest = latest_final_month($pdo);
$asOn   = $latest !== null
    ? 'Position at the end of ' . cb_month_label($latest) . ' (as on ' . date('j F Y', strtotime(cb_month_end($latest))) . ').'
    : 'Opening balances as on ' . date('j F Y', strtotime((string) opening_date($pdo))) . '. Monthly entries have not been saved yet.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Account | Sadya High School Employees' Credit Co-operative Society Ltd.</title>
<link rel="icon" href="images/logo.png" type="image/png">
<style>
  :root { --navy:#1f3864; --blue:#2f5fa8; --green:#2e7d4f; --red:#d0342c; --grey:#5a6472; --line:#dbe3ee; --bg:#f4f7fb; }
  * { box-sizing: border-box; }
  body { margin:0; font-family:"Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; background:var(--bg); color:#1d2733; line-height:1.5; }
  header { background:#fff; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:12px 32px; }
  .brand { display:flex; align-items:center; gap:12px; }
  .brand img { width:44px; height:44px; object-fit:contain; }
  .brand b { display:block; color:var(--navy); }
  .brand small { color:var(--grey); font-size:11px; }
  nav { display:flex; align-items:center; gap:20px; }
  nav a { color:var(--navy); font-weight:600; font-size:14px; text-decoration:none; }
  nav a:hover { color:var(--blue); }
  main { max-width:900px; margin:0 auto; padding:32px 20px 56px; }
  h1 { margin:0 0 4px; color:var(--navy); font-size:28px; }
  .sub { margin:0 0 24px; color:var(--grey); }
  .panel { background:#fff; border:1px solid var(--line); border-radius:12px; padding:22px; margin-bottom:20px; }
  .panel h2 { margin:0 0 14px; font-size:17px; color:var(--navy); }
  dl { margin:0; display:grid; grid-template-columns:max-content 1fr; gap:10px 28px; }
  dt { color:var(--grey); }
  dd { margin:0; font-weight:600; color:var(--navy); }
  .cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px; }
  .card { background:var(--bg); border-radius:10px; padding:14px 16px; }
  .card span { display:block; color:var(--grey); font-size:13px; }
  .card strong { display:block; color:var(--navy); font-size:22px; margin-top:2px; }
  .card strong.loan { color:var(--red); }
  .card strong.none { color:#97a1af; font-weight:600; }
  .note { margin:14px 0 0; color:var(--grey); font-size:13px; }
</style>
</head>
<body>

<header>
  <div class="brand">
    <img src="images/logo.png" alt="Society logo" onerror="this.style.display='none'">
    <div>
      <b>SADYA HIGH SCHOOL</b>
      <small>EMPLOYEES' CREDIT CO-OPERATIVE SOCIETY LTD.</small>
    </div>
  </div>
  <nav aria-label="Main">
    <a href="member-ledgers.php">My ledgers</a>
    <a href="emi-calculator.html">EMI Calculator</a>
    <a href="change-password.php">Change password</a>
    <a href="logout.php">Sign out</a>
  </nav>
</header>

<main>
  <h1>Welcome, <?= e($user['name']) ?></h1>
  <p class="sub">Your personal account page.</p>

  <section class="panel" aria-labelledby="profile-h">
    <h2 id="profile-h">Membership</h2>
    <dl>
      <dt>Name</dt><dd><?= e($user['name']) ?></dd>
      <dt>Member no.</dt><dd><?= e(display_member_no((int) $user['member_no'])) ?></dd>
      <dt>Userid</dt><dd><?= e($user['login_id']) ?></dd>
    </dl>
  </section>

  <section class="panel" aria-labelledby="acct-h">
    <h2 id="acct-h">My balances</h2>
    <div class="cards">
      <div class="card"><span>Share</span><strong>&#8377;<?= e(inr($mine['share'])) ?></strong></div>
      <div class="card"><span>Thrift fund</span><strong>&#8377;<?= e(inr($mine['thrift'])) ?></strong></div>
      <div class="card"><span>Guarantee fund</span>
        <?= $mine['guarantee'] > 0 ? '<strong>&#8377;' . e(inr($mine['guarantee'])) . '</strong>' : '<strong class="none">&mdash;</strong>' ?></div>
      <div class="card"><span>MT loan outstanding</span>
        <?= $mine['mt'] > 0 ? '<strong class="loan">&#8377;' . e(inr($mine['mt'])) . '</strong>' : '<strong class="none">No loan</strong>' ?></div>
      <div class="card"><span>ST loan outstanding</span>
        <?= $mine['st'] > 0 ? '<strong class="loan">&#8377;' . e(inr($mine['st'])) . '</strong>' : '<strong class="none">No loan</strong>' ?></div>
    </div>
    <p class="note"><?= e($asOn) ?> <a href="member-ledgers.php" style="color:var(--blue);font-weight:600">See my month-by-month history &rarr;</a></p>
  </section>
</main>

</body>
</html>
