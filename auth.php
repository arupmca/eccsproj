<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const SESSION_IDLE_SECONDS = 1800; // sign out after 30 minutes of inactivity

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('SHSECCSSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,   // becomes true automatically once the site runs on HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Escape text for safe output in HTML. */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    start_secure_session();
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Ends a script that answers with JSON (used by the save endpoints). */
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function home_for_role(string $role): string
{
    return $role === 'admin' ? 'admin-dashboard.php' : 'member-dashboard.php';
}

function logout_session(): void
{
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Call at the top of every protected page.
 *  $role = 'admin' or 'member' to restrict the page, or null for any signed-in user.
 *  $allowPasswordChange = true only on the change-password page itself.
 * Returns the signed-in user's details, read fresh from the database.
 */
function require_login(?string $role = null, bool $allowPasswordChange = false, bool $json = false): array
{
    start_secure_session();
    header('Cache-Control: no-store'); // keeps private pages out of the back-button cache

    $uid  = $_SESSION['uid'] ?? null;
    $last = (int) ($_SESSION['last_active'] ?? 0);

    if (!$uid || (time() - $last) > SESSION_IDLE_SECONDS) {
        logout_session();
        if ($json) {
            json_out(['ok' => false, 'errors' => ['Your session has ended. Please sign in again.']], 401);
        }
        redirect('member-login.php');
    }
    $_SESSION['last_active'] = time();

    $stmt = db()->prepare(
        'SELECT u.id, u.role, u.login_id, u.must_change_password, u.member_id,
                COALESCE(m.full_name, u.display_name) AS name,
                m.member_no, m.status
           FROM users u
      LEFT JOIN members m ON m.id = u.member_id
          WHERE u.id = :id'
    );
    $stmt->execute([':id' => $uid]);
    $user = $stmt->fetch();

    if (!$user || ($user['role'] === 'member' && $user['status'] !== 'active')) {
        logout_session();
        if ($json) {
            json_out(['ok' => false, 'errors' => ['Your session has ended. Please sign in again.']], 401);
        }
        redirect('member-login.php');
    }

    if ($role !== null && $user['role'] !== $role) {
        if ($json) {
            json_out(['ok' => false, 'errors' => ['You do not have access to this.']], 403);
        }
        http_response_code(403);
        exit('You do not have access to this page.');
    }

    if ((int) $user['must_change_password'] === 1 && !$allowPasswordChange) {
        if ($json) {
            json_out(['ok' => false, 'errors' => ['Please change your password first.']], 403);
        }
        redirect('change-password.php');
    }

    return $user;
}

/** Member number as shown to people, e.g. member 1 -> SDY-1001. */
function display_member_no(int $memberNo): string
{
    return 'SDY-' . (1000 + $memberNo);
}
