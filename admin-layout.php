<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function admin_icon(string $name): string
{
    static $icons = [
        'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'wallet'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
        'trend'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'rupee'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'search'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'plus'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'message'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'edit'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>',
        'delete'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        'signout'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/** Prints the page start: <head>, header bar and the opening <main>. */
function admin_open(array $user, string $title, string $extraCss = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> | Sadya High School Employees' Credit Co-operative Society Ltd.</title>
<link rel="icon" href="images/logo.png" type="image/png">
<link rel="stylesheet" href="admin-dashboard.css">
<style>
  .admin-tab-soon { opacity: .45; cursor: not-allowed; }
  button[disabled] { opacity: .45; cursor: not-allowed; }
  .flash { background:#e8f5ec; border:1px solid #b7dcc4; color:#1e6a3a; border-radius:var(--radius-sm); padding:11px 16px; margin-bottom:18px; font-size:.9rem; }
  .flash-err { background:#fbe6e5; border-color:#f0b8b5; color:#a3231c; }
  form.inline-form { display:inline; margin:0; }

  .month-banner { display:flex; flex-wrap:wrap; align-items:center; gap:10px 18px; margin:0 0 14px; }
  .month-banner h1 { margin:0; font-size:1.7rem; color:var(--navy); }
  .month-tag { display:inline-block; padding:4px 12px; border-radius:999px; background:#e8eef9; color:var(--navy); font-size:.8rem; font-weight:600; }
  .month-tag.final { background:#e8f5ec; color:#1e6a3a; }
  .month-note { flex-basis:100%; margin:0; color:var(--text-muted); font-size:.88rem; }
  .month-strip { display:flex; gap:8px; overflow-x:auto; padding:2px 2px 10px; margin:0 0 18px; }
  .mchip { flex:0 0 auto; min-width:104px; padding:8px 14px; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:#fff;
           text-decoration:none; color:var(--navy); font-weight:600; font-size:.88rem; line-height:1.25; }
  .mchip small { display:block; font-weight:500; font-size:.72rem; color:var(--text-muted); }
  a.mchip:hover { border-color:var(--navy); }
  .mchip.active { background:var(--navy); color:#fff; border-color:var(--navy); }
  .mchip.active small { color:#cfd8ec; }
  .mchip.next { border-color:var(--blue); background:#eef4fc; }
  .mchip.next small { color:var(--blue); font-weight:700; }
  .mchip.draft { border-color:#d9a21b; background:#fff8e6; }
  .mchip.draft small { color:#946a00; font-weight:700; }
  .mchip.locked { opacity:.5; cursor:not-allowed; }
  .mchip a.mlink { color:inherit; text-decoration:none; display:block; }
  .mchip a.mlink:hover { text-decoration:underline; }
  .mchip .mact { display:inline-block; margin-top:5px; font-size:.7rem; font-weight:700; color:#fff; background:var(--blue); padding:2px 10px; border-radius:999px; text-decoration:none; }
  .mchip .mact:hover { background:#264f8e; }
  .btn-sm { display:inline-block; padding:7px 18px; font-size:.82rem; }

  .rep-wrap { background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); overflow-x:auto; margin-bottom:22px; }
  .rep-title { margin:0; padding:14px 18px; font-size:1rem; color:var(--navy); border-bottom:1px solid var(--border-color); }
  table.rep { width:100%; border-collapse:collapse; font-size:.86rem; font-variant-numeric:tabular-nums; }
  table.rep th, table.rep td { padding:9px 12px; text-align:right; white-space:nowrap; border-bottom:1px solid var(--border-color); }
  table.rep th:first-child, table.rep td:first-child, table.rep th.l, table.rep td.l { text-align:left; }
  table.rep thead th { background:var(--navy); color:#fff; font-weight:600; font-size:.78rem; }
  table.rep tfoot td { font-weight:700; background:#f4f6fb; }
  table.rep td.dim { color:#9aa3b2; }
  .btn-row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin:6px 0 24px; }
  .btn { display:inline-block; border:1.5px solid var(--navy); background:var(--navy); color:#fff; border-radius:999px; padding:10px 24px; font:inherit; font-weight:700; font-size:.9rem; cursor:pointer; text-decoration:none; }
  .btn:hover { background:var(--navy-dark); }
  .btn.secondary { background:#fff; color:var(--navy); }
  .btn.secondary:hover { background:#eef2fa; }
  .btn.danger { background:#fff; color:var(--red); border-color:var(--red); }
  .btn[disabled] { opacity:.45; cursor:not-allowed; }
  .warn { background:#fff8e6; border:1px solid #ecd28a; color:#7a5700; border-radius:var(--radius-sm); padding:11px 16px; margin-bottom:12px; font-size:.88rem; }
  .errlist { margin:6px 0 0; padding-left:20px; }
<?= $extraCss ?>
</style>
</head>
<body class="admin-body">

  <header class="admin-header">
    <div class="admin-brand">
      <img src="images/logo.png" alt="Society Logo" class="admin-logo">
      <div class="admin-brand-text">
        <span class="admin-brand-title">SADYA HIGH SCHOOL</span>
        <span class="admin-brand-subtitle">EMPLOYEES' CO-OPERATIVE CREDIT SOCIETY LTD.</span>
      </div>
      <span class="admin-divider"></span>
      <span class="admin-portal-label">SECRETARY PORTAL</span>
    </div>

    <div class="admin-user">
      <div class="admin-user-text">
        <span class="admin-user-name"><?= e($user['name']) ?></span>
        <span class="admin-user-role">ADMIN</span>
      </div>
      <a href="logout.php" class="admin-signout"><?= admin_icon('signout') ?> Sign out</a>
    </div>
  </header>

  <main class="admin-main">
<?php
}

/** Prints the tab bar. $active is one of: members, loans, cashbook, ledgers, requests, settings. */
function admin_tabs(string $active): void
{
    // href = null means the tab is not built yet.
    $tabs = [
        'members'  => ['Members',           'admin-dashboard.php', null],
        'loans'    => ['Loan Applications', null,                  null],
        'cashbook' => ['Cash Book',         'admin-cashbook.php',  null],
        'ledgers'  => ['Ledgers',           'admin-ledgers.php',   null],
        'requests' => ['Requests',          null,                  'message'],
        'settings' => ['Settings',          'admin-settings.php',  'settings'],
    ];
    echo '    <nav class="admin-tabs" aria-label="Sections">' . "\n";
    foreach ($tabs as $key => [$label, $href, $icon]) {
        $iconHtml = $icon ? admin_icon($icon) . ' ' : '';
        if ($href === null) {
            echo '      <span class="admin-tab admin-tab-soon" title="Coming soon" aria-disabled="true">' . $iconHtml . e($label) . "</span>\n";
        } else {
            $cls = 'admin-tab' . ($key === $active ? ' admin-tab-active' : '');
            echo '      <a href="' . e($href) . '" class="' . $cls . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . $iconHtml . e($label) . "</a>\n";
        }
    }
    echo "    </nav>\n";
}

/** Prints the end of the page. $beforeBody can hold a <script> block. */
function admin_close(string $beforeBody = ''): void
{
    echo "  </main>\n" . $beforeBody . "\n</body>\n</html>\n";
}

/** Remember a one-time message to show on the next admin page. */
function set_flash(string $type, string $text): void
{
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

/** Print and clear the one-time message, if any. */
function show_flash(): void
{
    start_secure_session();
    if (empty($_SESSION['flash'])) {
        return;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = $f['type'] === 'err' ? 'flash flash-err' : 'flash';
    echo '    <div class="' . $cls . '" role="' . ($f['type'] === 'err' ? 'alert' : 'status') . '">' . e($f['text']) . "</div>\n";
}

/**
 * The row of month chips. $active is 'opening' or 'YYYY-MM-01' (the month being viewed) or null.
 * Saved months link to $viewUrl?month=YYYY-MM; the open month links to the entry page.
 */
function admin_month_strip(array $states, ?string $active, string $viewUrl, ?string $openingDate, string $mode = 'book'): void
{
    if ($mode === 'balances') {
        // Each chip shows the balances on the 1st of that month (= the closing balances of the month before).
        // A month can be viewed once the month before it is saved; the open month also gets an Enter data button.
        echo '    <div class="month-strip" role="navigation" aria-label="Months">' . "\n";
        foreach ($states as $i => $st) {
            $m        = $st['month'];
            $label    = date('M Y', strtotime($m));
            $param    = substr($m, 0, 7);
            $viewable = $i === 0 || $states[$i - 1]['state'] === 'final';
            $state    = $st['state'];
            if (!$viewable) {
                echo '      <span class="mchip locked" title="Save the earlier month first" aria-disabled="true">' . e($label) . '<small>Locked</small></span>' . "\n";
                continue;
            }
            $cls  = 'mchip' . ($active === $m ? ' active' : '') . ($state === 'draft' ? ' draft' : ($state === 'next' ? ' next' : ''));
            $text = ['final' => 'Saved', 'draft' => 'Draft in progress', 'next' => 'Not entered yet'][$state] ?? '';
            echo '      <div class="' . $cls . '"><a class="mlink" href="' . e($viewUrl) . '?month=' . e($param) . '">' . e($label) . '</a><small>' . e($text) . '</small>';
            if ($state === 'draft' || $state === 'next') {
                echo '<a class="mact" href="admin-cashbook-entry.php?month=' . e($param) . '">' . ($state === 'draft' ? 'Continue' : 'Enter data') . '</a>';
            }
            echo "</div>\n";
        }
        echo "    </div>\n";
        return;
    }

    echo '    <div class="month-strip" role="navigation" aria-label="Months">' . "\n";
    if ($openingDate !== null) {
        $cls = 'mchip' . ($active === 'opening' ? ' active' : '');
        echo '      <a class="' . $cls . '" href="' . e($viewUrl) . '?month=opening">Opening<small>as on '
           . e(date('j M Y', strtotime($openingDate))) . '</small></a>' . "\n";
    }
    foreach ($states as $st) {
        $m = $st['month'];
        $label = date('M Y', strtotime($m));
        $param = substr($m, 0, 7);
        switch ($st['state']) {
            case 'final':
                $cls = 'mchip' . ($active === $m ? ' active' : '');
                echo '      <a class="' . $cls . '" href="' . e($viewUrl) . '?month=' . e($param) . '">' . e($label) . '<small>Saved</small></a>' . "\n";
                break;
            case 'draft':
                echo '      <a class="mchip draft" href="admin-cashbook-entry.php?month=' . e($param) . '">' . e($label) . '<small>Draft &middot; continue</small></a>' . "\n";
                break;
            case 'next':
                echo '      <a class="mchip next" href="admin-cashbook-entry.php?month=' . e($param) . '">' . e($label) . '<small>Enter data</small></a>' . "\n";
                break;
            default:
                echo '      <span class="mchip locked" title="Save the earlier month first" aria-disabled="true">' . e($label) . '<small>Locked</small></span>' . "\n";
        }
    }
    echo "    </div>\n";
}
