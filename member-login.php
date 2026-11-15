<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
start_secure_session();

// Already signed in? Go straight to the right dashboard.
if (!empty($_SESSION['uid']) && !empty($_SESSION['role'])) {
    redirect(home_for_role((string) $_SESSION['role']));
}

const MAX_FAILED_ATTEMPTS = 5; // then locked for 15 minutes

$error  = '';
$userid = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userid   = trim((string) ($_POST['userid'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_valid()) {
        $error = 'Your page expired. Please try again.';
    } elseif ($userid === '' || $password === '') {
        $error = 'Enter your userid and password.';
    } else {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.role, u.password_hash, u.must_change_password,
                    (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked,
                    (u.role = \'admin\' OR m.status = \'active\') AS is_active
               FROM users u
          LEFT JOIN members m ON m.id = u.member_id
              WHERE u.login_id = :login'
        );
        $stmt->execute([':login' => $userid]);
        $user = $stmt->fetch();

        // A deleted member is treated exactly like an unknown userid.
        if ($user && (int) $user['is_active'] !== 1) {
            $user = false;
        }

        if ($user && (int) $user['is_locked'] === 1) {
            $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        } else {
            // Always run a password check, so unknown userids take the same time as known ones.
            $hash = $user['password_hash'] ?? password_hash('unused-placeholder', PASSWORD_DEFAULT);
            $ok   = password_verify($password, $hash) && $user !== false;

            if ($ok) {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                        ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }
                $pdo->prepare(
                    'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id'
                )->execute([':id' => $user['id']]);

                session_regenerate_id(true);
                $_SESSION['uid']         = (int) $user['id'];
                $_SESSION['role']        = (string) $user['role'];
                $_SESSION['last_active'] = time();

                if ((int) $user['must_change_password'] === 1) {
                    redirect('change-password.php');
                }
                redirect(home_for_role((string) $user['role']));
            }

            if ($user) {
                // locked_until is assigned first so it sees the attempt count before this failure.
                $pdo->prepare(
                    'UPDATE users
                        SET locked_until    = IF(failed_attempts + 1 >= :max, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until),
                            failed_attempts = IF(failed_attempts + 1 >= :max2, 0, failed_attempts + 1)
                      WHERE id = :id'
                )->execute([':max' => MAX_FAILED_ATTEMPTS, ':max2' => MAX_FAILED_ATTEMPTS, ':id' => $user['id']]);
            }
            $error = 'Invalid userid or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Member Login | Sadya High School Employees' Credit Co-operative Society Ltd.</title>
<link rel="icon" href="images/logo.png" type="image/png">
<link rel="stylesheet" href="login.css">
</head>
<body class="login-body">

  <div class="login-page">

    <!-- Left panel: photo + caption -->
    <div class="login-visual">
      <div class="login-visual-image">
        <img src="images/login.jpg" alt="Sadya High School campus">
      </div>
      <div class="login-visual-caption">
        <h2>A Co-Operative you can<br>log into with confidence</h2>
        <p>Access your loan schedules, balances, and statements &ndash; all in one secure place.</p>
      </div>
    </div>

    <!-- Right panel: sign-in form -->
    <div class="login-form-side">
      <form class="login-card" id="loginForm" action="member-login.php" method="post" novalidate>
        <?= csrf_field() ?>

        <div class="login-card-header">
          <div>
            <h1 class="login-title">Sign In</h1>
            <p class="login-subtitle">Member, Admin</p>
          </div>
          <img src="images/logo.png" alt="Society Logo" class="login-logo">
        </div>

        <label class="login-label" for="userid">Userid</label>
        <input class="login-input" type="text" id="userid" name="userid" autocomplete="username"
               value="<?= e($userid) ?>" autofocus>

        <label class="login-label" for="password">Password</label>
        <input class="login-input" type="password" id="password" name="password" autocomplete="current-password">

        <p class="login-error" id="loginError" role="alert"><?= e($error) ?></p>

        <button type="submit" class="login-submit">Sign in</button>

        <a href="index.html" class="login-back">&larr; Back to home</a>
      </form>
    </div>

  </div>

</body>
</html>
