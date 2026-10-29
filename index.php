<?php
/**
 * Cow Pat Bingo - Root Entrypoint Router
 * Redirects to the active event's grid if only one exists, or to the public event selector.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php';

$queryString = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';

try {
    $db = getDbConnection();
    $activeEvents = $db->query("SELECT id FROM cow_bingo_events WHERE is_active = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // If exactly one active event exists, go straight to its grid
    if (count($activeEvents) === 1) {
        $targetId = (int)$activeEvents[0]['id'];
        $sep = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING'] . '&id=' . $targetId) : ('?id=' . $targetId);
        header('Location: grid.php' . $sep, true, 302);
        exit;
    }
} catch (\Throwable $e) {}

header('Location: grid.php' . $queryString, true, 302);
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=grid.php<?= htmlspecialchars($queryString) ?>">
    <title>Redirecting to Cow Pat Bingo...</title>
</head>
<body>
    <p>Redirecting to <a href="grid.php<?= htmlspecialchars($queryString) ?>">Cow Pat Bingo</a>...</p>
</body>
</html>
