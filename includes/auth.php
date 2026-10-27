<?php
// includes/auth.php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Guarantee CSRF token is seeded in session for all active visitors/admins
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ----------------------------------------------------------------------------
// 1. CSRF Helpers
// ----------------------------------------------------------------------------

if (!function_exists('getCsrfToken')) {
    function getCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        return getCsrfToken();
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

// ----------------------------------------------------------------------------
// 2. Brute Force & Rate Limiting Helpers
// ----------------------------------------------------------------------------

function getLoginLockoutMinutes(PDO $db, string $ip): int {
    $baseLockout = 5;      // Base lockout in minutes
    $maxAttempts = 5;      // Attempts per tier
    $maxCapMinutes = 1440; // 24-hour maximum cap

    $countStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM cow_bingo_login_attempts 
        WHERE ip_address = ? 
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $countStmt->execute([$ip]);
    $totalAttempts = (int) $countStmt->fetchColumn();

    if ($totalAttempts < $maxAttempts) {
        return 0;
    }

    $tier = (int) floor($totalAttempts / $maxAttempts);
    $lockoutMinutes = (int) min($baseLockout * pow(2, $tier - 1), $maxCapMinutes);

    $stmt = $db->prepare("
        SELECT TIMESTAMPDIFF(SECOND, attempted_at, NOW()) AS elapsed
        FROM cow_bingo_login_attempts
        WHERE ip_address = ?
        ORDER BY attempted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$ip]);
    $elapsedSeconds = $stmt->fetchColumn();

    if ($elapsedSeconds === false || $elapsedSeconds === null) {
        return 0;
    }

    $lockoutSeconds = $lockoutMinutes * 60;
    $remainingSeconds = $lockoutSeconds - (int) $elapsedSeconds;

    if ($remainingSeconds <= 0) {
        try {
            $db->prepare("DELETE FROM cow_bingo_login_attempts WHERE ip_address = ?")->execute([$ip]);
        } catch (\Throwable $e) {}
        return 0;
    }

    return (int) ceil($remainingSeconds / 60);
}

function getIpLockoutMinutes(PDO $db, string $ip): int {
    return getLoginLockoutMinutes($db, $ip);
}

function getAttemptsRemaining(PDO $db, string $ip, int $maxAttempts = 5): int {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM cow_bingo_login_attempts 
        WHERE ip_address = ? 
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$ip]);
    $attempts = (int) $stmt->fetchColumn();

    if ($attempts > 0 && $attempts % $maxAttempts === 0) {
        return 0;
    }

    return $maxAttempts - ($attempts % $maxAttempts);
}

function recordFailedLoginAttempt(PDO $db, string $ip, ?string $username = null): void {
    if ($username !== null) {
        try {
            $stmt = $db->prepare("INSERT INTO cow_bingo_login_attempts (ip_address, username) VALUES (?, ?)");
            $stmt->execute([$ip, $username]);
            return;
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '42S22') {
                error_log("Failed to record login attempt (with username): " . $e->getMessage());
                return;
            }
        }
    }

    try {
        $stmt = $db->prepare("INSERT INTO cow_bingo_login_attempts (ip_address) VALUES (?)");
        $stmt->execute([$ip]);
    } catch (\Throwable $e) {
        error_log("Failed to record login attempt: " . $e->getMessage());
    }
}

function clearLoginAttempts(PDO $db, string $ip): void {
    try {
        $stmt = $db->prepare("DELETE FROM cow_bingo_login_attempts WHERE ip_address = ?");
        $stmt->execute([$ip]);
    } catch (\Throwable $e) {
        error_log("Failed to clear login attempts: " . $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// 3. Login UI Rendering
// ----------------------------------------------------------------------------

function showLoginForm(?string $error = null): void {
    $title = defined('SITE_TITLE') ? SITE_TITLE : '🐄 Cow Pat Bingo 🐄';
    $csrf = getCsrfToken();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #2d5016 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px; padding: 32px 28px;
            width: 100%; max-width: 400px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            text-align: center;
        }
        h1 { font-size: 1.6em; color: #2d5016; margin-bottom: 6px; }
        p.sub { font-size: 0.9em; color: #666; margin-bottom: 20px; }
        .alert {
            padding: 10px 14px; border-radius: 6px; font-size: 0.9em;
            margin-bottom: 18px; text-align: left; line-height: 1.4;
            background: #fff3cd; color: #856404; border: 1px solid #ffeeba;
        }
        .form-group { text-align: left; margin-bottom: 16px; }
        label { display: block; font-size: 0.85em; font-weight: bold; color: #333; margin-bottom: 6px; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 12px; border: 1.5px solid #ccc; border-radius: 6px; font-size: 1em; outline: none;
        }
        input:focus { border-color: #2d5016; box-shadow: 0 0 0 2px rgba(45,80,22,0.2); }
        button[type="submit"] {
            width: 100%; background: #2d5016; color: white; padding: 12px;
            border: none; border-radius: 6px; font-size: 1.05em; font-weight: bold;
            cursor: pointer; transition: background 0.2s;
        }
        button[type="submit"]:hover { background: #3d6b20; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="sub">Admin Portal</p>
        <?php if (!empty($error)): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" name="login_submit" value="1">Sign In</button>
        </form>
    </div>
</body>
</html>
    <?php
}

// ----------------------------------------------------------------------------
// 4. Logout Handler
// ----------------------------------------------------------------------------

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ----------------------------------------------------------------------------
// 5. Login Authentication Flow
// ----------------------------------------------------------------------------

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $loginError = "❌ Security token expired. Please refresh and try again.";
    } else {
        $lockoutMinutes = getLoginLockoutMinutes($db, $clientIp);
        if ($lockoutMinutes > 0) {
            $loginError = "❌ Too many failed attempts. Try again in {$lockoutMinutes} minute" . ($lockoutMinutes > 1 ? 's' : '') . ".";
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            $userStmt = $db->prepare("SELECT * FROM cow_bingo_admins WHERE username = ? LIMIT 1");
            $userStmt->execute([$username]);
            $admin = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($admin && password_verify($password, $admin['password_hash'])) {
                clearLoginAttempts($db, $clientIp);
                session_regenerate_id(true);

                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['display_name'];
                $_SESSION['is_super_admin'] = (bool) $admin['is_super_admin'];
                $_SESSION['admin_role'] = $admin['role'] ?? ($admin['is_super_admin'] ? 'super_admin' : 'event_admin');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $target = ($_SESSION['admin_role'] === 'seller') ? 'pos.php' : 'admin.php';
                header("Location: {$target}");
                exit;
            } else {
                recordFailedLoginAttempt($db, $clientIp, $username);
                $newLockout = getLoginLockoutMinutes($db, $clientIp);
                if ($newLockout > 0) {
                    $loginError = "❌ Too many failed attempts. Try again in {$newLockout} minute" . ($newLockout > 1 ? 's' : '') . ".";
                } else {
                    $remaining = getAttemptsRemaining($db, $clientIp);
                    $loginError = "❌ Invalid username or password. {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining.";
                }
            }
        }
    }
}

// ----------------------------------------------------------------------------
// 6. Access Guard & Session Variable Exports
// ----------------------------------------------------------------------------

if (empty($_SESSION['admin_id'])) {
    $activeLockout = getLoginLockoutMinutes($db, $clientIp);
    if ($activeLockout > 0 && empty($loginError)) {
        $loginError = "❌ Too many failed attempts. Try again in {$activeLockout} minute" . ($activeLockout > 1 ? 's' : '') . ".";
    }
    showLoginForm($loginError);
    exit;
}

$currentAdminId = (int) $_SESSION['admin_id'];
$isSuperAdmin = !empty($_SESSION['is_super_admin']);
$currentAdminName = $_SESSION['admin_name'] ?? 'Admin';
$currentAdminRole = $_SESSION['admin_role'] ?? 'admin';

// Restrict iPad sellers to POS and Grid views
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($currentAdminRole === 'seller' && !in_array($currentScript, ['pos.php', 'grid.php'], true)) {
    header('Location: pos.php');
    exit;
}
