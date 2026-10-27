<?php
/**
 * Cow Pat Bingo - Root Entrypoint Router
 * Redirects root folder requests directly to the public visual grid.
 */

// Preserve any query parameters (e.g. /cow-bingo/?id=2 or /cow-bingo/?event=slug)
$queryString = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';

header('Location: grid.php' . $queryString, true, 302);
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
