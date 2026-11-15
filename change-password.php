<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user  = require_login(null, true); // allowed even while a password change is pending
$forced = (int) $user['must_change_password'] === 1;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $row = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $row->execute([':id' => $user['id']]);
    $hash = (string) $row->fetchColumn();

    if (!csrf_valid()) {
        $error = 'Your page expired. Please try again.';
    } elseif (!password_verify($current, $hash)) {
        $error = 'Your current password is not correct.';
    } elseif (strlen($new) < 8) {
        $error = 'The new password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        $error = 'The new password must contain at least one letter and one number.';
    } elseif ($new === $current) {
        $error = 'The new password must be different from the current one.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and its confirmation do not match.';
    } else {
        db()->prepare('UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id')
            ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
        session_regenerate_id(true);
        redirect(home_for_role((string) $user['role']));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password | Sadya High School Employees' Credit Co-operative Society Ltd.</title>
<link rel="icon" href="images/logo.png" type="image/png">
<link rel="stylesheet" href="login.css">
</head>
<body class="login-body">

  <div class="login-page">

    <div class="login-visual">
      <div class="login-visual-image">
        <img src="images/login.jpg" alt="Sadya High School campus">
      </div>
      <div class="login-visual-caption">
        <h2>Keep your account<br>secure</h2>
        <p>Choose a password only you know. Do not share it with anyone, including office-bearers.</p>
      </div>
    </div>

    <div class="login-form-side">
      <form class="login-card" action="change-password.php" method="post" novalidate>
        <?= csrf_field() ?>

        <div class="login-card-header">
          <div>
            <h1 class="login-title">Set a new password</h1>
            <p class="login-subtitle">
              <?= $forced ? 'You must change your password before continuing.' : 'Signed in as ' . e($user['name']) ?>
            </p>
          </div>
          <img src="images/logo.png" alt="Society Logo" class="login-logo">
        </div>

        <label class="login-label" for="current_password">Current password</label>
        <input class="login-input" type="password" id="current_password" name="current_password"
               autocomplete="current-password" autofocus>

        <label class="login-label" for="new_password">New password</label>
        <input class="login-input" type="password" id="new_password" name="new_password"
               autocomplete="new-password">

        <label class="login-label" for="confirm_password">Confirm new password</label>
        <input class="login-input" type="password" id="confirm_password" name="confirm_password"
               autocomplete="new-password">

        <p class="login-error" role="alert"><?= e($error) ?></p>

        <button type="submit" class="login-submit">Save password</button>

        <a href="logout.php" class="login-back">Sign out</a>
      </form>
    </div>

  </div>

</body>
</html>
