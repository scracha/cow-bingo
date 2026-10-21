<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loginMessage = '';

// 2. Handle Login Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $lockoutMinutes = getLoginLockoutMinutes($db, $ip);

    if ($lockoutMinutes > 0) {
        $loginMessage = "❌ Too many failed attempts. Try again in $lockoutMinutes minute" . ($lockoutMinutes > 1 ? 's' : '') . ".";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = $db->prepare("SELECT * FROM cow_bingo_admins WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_name'] = $user['display_name'];
            $_SESSION['is_super_admin'] = (bool) $user['is_super_admin'];
            $_SESSION['admin_role'] = $user['role'] ?? ($user['is_super_admin'] ? 'super_admin' : 'event_admin');

            $db->prepare("DELETE FROM cow_bingo_login_attempts WHERE ip_address = ?")->execute([$ip]);

            if ($_SESSION['admin_role'] === 'seller') {
                header('Location: pos.php');
                exit;
            }

            header('Location: admin.php');
            exit;
        } else {
            $db->prepare("INSERT INTO cow_bingo_login_attempts (ip_address) VALUES (?)")->execute([$ip]);
            $remaining = getAttemptsRemaining($db, $ip);
            $loginMessage = ($remaining > 0)
                ? "❌ Invalid username or password. $remaining attempt" . ($remaining > 1 ? 's' : '') . " remaining."
                : "❌ Too many failed attempts. Locked out for " . getLoginLockoutMinutes($db, $ip) . " minutes.";
        }
    }
}

// 3. Prompt Login If Unauthenticated
if (!isset($_SESSION['admin_id'])) {
    showLoginForm($loginMessage);
    exit;
}

// 4. Force Sellers to POS
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'seller') {
    header('Location: pos.php');
    exit;
}

$currentAdminId = (int) $_SESSION['admin_id'];
$isSuperAdmin = (bool) ($_SESSION['is_super_admin'] ?? false);

function getLoginLockoutMinutes(PDO $db, string $ip): int {
    $maxAttempts = 5;
    $baseLockout = 5;
    $stmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute([$ip]);
    $totalAttempts = (int) $stmt->fetchColumn();
    if ($totalAttempts < $maxAttempts) return 0;

    $tier = (int) floor(($totalAttempts - 1) / $maxAttempts);
    $lockoutMinutes = min($baseLockout * pow(2, $tier - 1), 1440);

    $stmt = $db->prepare("SELECT attempted_at FROM cow_bingo_login_attempts WHERE ip_address = ? ORDER BY attempted_at DESC LIMIT 1");
    $stmt->execute([$ip]);
    $lastAttempt = $stmt->fetchColumn();
    if (!$lastAttempt) return 0;

    $remaining = (strtotime($lastAttempt) + ($lockoutMinutes * 60)) - time();
    return ($remaining <= 0) ? 0 : (int) ceil($remaining / 60);
}

function getAttemptsRemaining(PDO $db, string $ip): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute([$ip]);
    return max(0, 5 - (int) $stmt->fetchColumn());
}

function showLoginForm(string $message = ''): void {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cow Bingo Admin - Login</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #2d5016 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); width: 320px; }
        .login-box h1 { margin: 0 0 20px; color: #2d5016; font-size: 1.4em; text-align: center; }
        .login-box input { width: 100%; padding: 10px 12px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
        .login-box button { width: 100%; padding: 12px; background: #2d5016; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .error { color: #dc3545; font-size: 0.9em; margin-bottom: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>🐄 Cow Bingo Admin</h1>
        <?php if ($message): ?><div class="error"><?= $message ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log In</button>
        </form>
    </div>
</body>
</html>
<?php
}