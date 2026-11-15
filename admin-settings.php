<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/admin-layout.php';

$user = require_login('admin');
$pdo  = db();

// [type, minimum, maximum] for every setting the administrator may change.
const SETTING_RULES = [
    'thrift_monthly'          => ['int',      0,   100000],
    'share_value'             => ['int',      1,   100000],
    'share_max_per_member'    => ['int',      0, 10000000],
    'admission_fee'           => ['int',      0,   100000],
    'min_shares_new_member'   => ['int',      0,     1000],
    'loan_min_share_pct'      => ['decimal',  0,      100],
    'loan_min_guarantee_pct'  => ['decimal',  0,      100],
    'mt_rate'                 => ['decimal',  0,       36],
    'mt_installments'         => ['int',      1,      360],
    'mt_max_amount'           => ['int',      0, 10000000],
    'st_rate'                 => ['decimal',  0,       36],
    'st_installments'         => ['int',      1,      360],
    'st_max_amount'           => ['int',      0, 10000000],
    'thrift_interest_rate'    => ['decimal',  0,       36],
    'guarantee_interest_rate' => ['decimal',  0,       36],
    'opening_cash'            => ['money',    0, 100000000],
];

/** Returns the cleaned value, or null if it is not a valid number in range. */
function clean_setting(string $key, string $raw): ?string
{
    [$type, $min, $max] = SETTING_RULES[$key];
    $raw = trim(str_replace(',', '', $raw));

    if ($type === 'int') {
        if (!preg_match('/^\d{1,9}$/', $raw)) {
            return null;
        }
        $n = (int) $raw;
        return ($n >= $min && $n <= $max) ? (string) $n : null;
    }

    if ($type === 'money') {
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
            return null;
        }
        $n = (float) $raw;
        return ($n >= $min && $n <= $max) ? rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0' : null;
    }

    if (!preg_match('/^\d{1,3}(\.\d{1,4})?$/', $raw)) {
        return null;
    }
    $n = (float) $raw;
    if ($n < $min || $n > $max) {
        return null;
    }
    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
}

$rows = $pdo->query(
    'SELECT setting_key, setting_value, label, unit, updated_at FROM settings ORDER BY sort_order'
)->fetchAll();

$current = [];
$labels  = [];
foreach ($rows as $r) {
    $current[$r['setting_key']] = $r['setting_value'];
    $labels[$r['setting_key']]  = $r['label'];
}

$errors = [];
$posted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors['_form'] = 'Your page expired. Please try again.';
    } else {
        $input   = is_array($_POST['s'] ?? null) ? $_POST['s'] : [];
        $changes = [];

        foreach ($rows as $r) {
            $key = $r['setting_key'];
            if (!isset(SETTING_RULES[$key])) {
                continue;
            }
            $raw = isset($input[$key]) ? (string) $input[$key] : $r['setting_value'];
            $posted[$key] = $raw;

            $value = clean_setting($key, $raw);
            if ($value === null) {
                [$type, $min, $max] = SETTING_RULES[$key];
                $errors[$key] = 'Enter a number from ' . $min . ' to ' . $max . '.';
            } elseif ($value !== $r['setting_value']) {
                $changes[$key] = $value;
            }
        }

        if (!$errors) {
            if ($changes) {
                $pdo->beginTransaction();
                try {
                    $upd  = $pdo->prepare('UPDATE settings SET setting_value = :v, updated_by = :u WHERE setting_key = :k');
                    $hist = $pdo->prepare(
                        'INSERT INTO settings_history (setting_key, old_value, new_value, changed_by)
                         VALUES (:k, :o, :n, :u)'
                    );
                    foreach ($changes as $key => $value) {
                        $upd->execute([':v' => $value, ':u' => $user['id'], ':k' => $key]);
                        $hist->execute([':k' => $key, ':o' => $current[$key], ':n' => $value, ':u' => $user['id']]);
                    }
                    $pdo->commit();
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    throw $ex;
                }
            }
            redirect('admin-settings.php?saved=' . count($changes));
        }
    }
}

$history = $pdo->query(
    'SELECT h.setting_key, h.old_value, h.new_value, h.changed_at, u.login_id
       FROM settings_history h
  LEFT JOIN users u ON u.id = h.changed_by
   ORDER BY h.id DESC LIMIT 10'
)->fetchAll();

$saved = isset($_GET['saved']) ? (int) $_GET['saved'] : null;

$css = <<<CSS
  .set-panel { background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); box-shadow:var(--shadow-card); padding:24px; margin-bottom:22px; }
  .set-panel h2 { margin:0 0 6px; font-size:1.05rem; color:var(--navy); }
  .set-panel p.note { margin:0 0 18px; color:var(--text-muted); font-size:.88rem; }
  .set-row { display:grid; grid-template-columns:minmax(0,1fr) 190px; gap:8px 24px; align-items:center; padding:12px 0; border-top:1px solid var(--border-color); }
  .set-row label { font-weight:600; font-size:.92rem; }
  .set-row .meta { display:block; font-weight:400; font-size:.76rem; color:var(--text-faint); }
  .set-field { display:flex; align-items:center; gap:8px; }
  .set-field input { width:100%; padding:9px 12px; font:inherit; text-align:right; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:#fff; }
  .set-field input:focus-visible { outline:3px solid #9dbbe6; outline-offset:1px; border-color:var(--blue); }
  .set-field input[aria-invalid="true"] { border-color:var(--red); }
  .set-field .unit { color:var(--text-muted); font-size:.82rem; white-space:nowrap; min-width:56px; }
  .set-err { grid-column:1 / -1; color:var(--red); font-size:.82rem; text-align:right; }
  .set-actions { display:flex; gap:14px; align-items:center; margin-top:18px; }
  .set-save { background:var(--navy); color:#fff; border:none; border-radius:999px; padding:11px 26px; font-weight:700; font-size:.9rem; cursor:pointer; }
  .set-save:hover { background:var(--navy-dark); }
  .hist { width:100%; border-collapse:collapse; font-size:.86rem; }
  .hist th, .hist td { text-align:left; padding:9px 8px; border-top:1px solid var(--border-color); }
  .hist th { color:var(--text-muted); font-size:.74rem; letter-spacing:.03em; }
  @media (max-width:700px) { .set-row { grid-template-columns:1fr; } .set-err { text-align:left; } }
CSS;

admin_open($user, 'Settings', $css);
admin_tabs('settings');
?>

    <?php if ($saved !== null): ?>
      <div class="flash" role="status">
        <?= $saved > 0 ? e((string) $saved) . ' setting' . ($saved === 1 ? '' : 's') . ' saved.' : 'Nothing was changed.' ?>
      </div>
    <?php endif; ?>
    <?php if (isset($errors['_form'])): ?>
      <div class="flash flash-err" role="alert"><?= e($errors['_form']) ?></div>
    <?php elseif ($errors): ?>
      <div class="flash flash-err" role="alert">Nothing was saved. Please correct the highlighted values.</div>
    <?php endif; ?>

    <form method="post" action="admin-settings.php" novalidate>
      <?= csrf_field() ?>

      <section class="set-panel" aria-labelledby="rules-h">
        <h2 id="rules-h">Society rules</h2>
        <p class="note">These values are used for new entries from now on. Entries already recorded keep the amounts they were recorded with. Every change is logged below.</p>

<?php foreach ($rows as $r):
        $key = $r['setting_key'];
        if (!isset(SETTING_RULES[$key])) { continue; }
        $val = $posted[$key] ?? $r['setting_value'];
        $bad = isset($errors[$key]);
?>
        <div class="set-row">
          <label for="s_<?= e($key) ?>">
            <?= e($r['label']) ?>
            <span class="meta">Last changed <?= e(date('d M Y', strtotime($r['updated_at']))) ?></span>
          </label>
          <div class="set-field">
            <input type="text" inputmode="decimal" id="s_<?= e($key) ?>" name="s[<?= e($key) ?>]"
                   value="<?= e($val) ?>" autocomplete="off"<?= $bad ? ' aria-invalid="true"' : '' ?>>
            <span class="unit"><?= e($r['unit'] === 'Rs' ? "\u{20B9}" : $r['unit']) ?></span>
          </div>
<?php   if ($bad): ?>
          <div class="set-err"><?= e($errors[$key]) ?></div>
<?php   endif; ?>
        </div>
<?php endforeach; ?>

        <div class="set-actions">
          <button type="submit" class="set-save">Save changes</button>
          <a href="change-password.php" style="color:var(--blue);font-weight:600;font-size:.88rem">Change my password</a>
        </div>
      </section>
    </form>

    <section class="set-panel" aria-labelledby="hist-h">
      <h2 id="hist-h">Recent changes</h2>
<?php if (!$history): ?>
      <p class="note" style="margin:0">No changes yet.</p>
<?php else: ?>
      <table class="hist">
        <thead><tr><th>When</th><th>Setting</th><th>From</th><th>To</th><th>By</th></tr></thead>
        <tbody>
<?php   foreach ($history as $h): ?>
          <tr>
            <td><?= e(date('d M Y, H:i', strtotime($h['changed_at']))) ?></td>
            <td><?= e($labels[$h['setting_key']] ?? $h['setting_key']) ?></td>
            <td><?= e($h['old_value']) ?></td>
            <td><?= e($h['new_value']) ?></td>
            <td><?= e($h['login_id'] ?? '') ?></td>
          </tr>
<?php   endforeach; ?>
        </tbody>
      </table>
<?php endif; ?>
    </section>

<?php admin_close(); ?>
