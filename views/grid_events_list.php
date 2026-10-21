<?php
// views/grid_events_list.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_TITLE) ?> - Events</title>
    <style>
        body {
            font-family: -apple-system, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #2d5016 100%);
            min-height: 100vh; padding: 40px; color: #333; margin: 0;
        }
        .container { max-width: 700px; margin: 0 auto; }
        h1 { color: white; text-align: center; margin-bottom: 30px; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .event-card {
            background: white; border-radius: 8px; padding: 20px; margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15); display: flex;
            justify-content: space-between; align-items: center;
        }
        .event-card h2 { margin: 0 0 5px; color: #2d5016; font-size: 1.3em; }
        .event-card .meta { color: #666; font-size: 0.9em; }
        .event-card a {
            background: #2d5016; color: white; padding: 10px 20px;
            border-radius: 6px; text-decoration: none; font-weight: bold;
        }
        .event-card a:hover { background: #3d6b20; }
        .empty { text-align: center; color: rgba(255,255,255,0.8); font-size: 1.2em; }
    </style>
</head>
<body>
<div class="container">
    <h1>🐄 Cow Bingo</h1>
    <?php if (empty($events)): ?>
        <p class="empty">No active cow bingo events right now.</p>
    <?php else: ?>
        <?php foreach ($events as $ev): 
            $total = $ev['grid_cols'] * $ev['grid_rows'];
        ?>
        <div class="event-card">
            <div>
                <h2><?= htmlspecialchars($ev['event_name']) ?></h2>
                <div class="meta"><?= $ev['grid_cols'] ?>x<?= $ev['grid_rows'] ?> grid &bull; <?= $ev['sold_count'] ?>/<?= $total ?> sold</div>
            </div>
            <!-- Unambiguous local ID link -->
            <a href="?id=<?= $ev['id'] ?>">View Grid</a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>