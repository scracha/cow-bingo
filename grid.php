<?php
/**
 * Cow Bingo - Visual Grid Display (Multi-Event)
 * 
 * Usage: grid.php?event=HIEVENTS_EVENT_ID
 * Example: grid.php?event=42
 */

require_once __DIR__ . '/config.php';

$db = getDbConnection();

// Get the event ID from the URL
$hieventsEventId = $_GET['event'] ?? null;

if (!$hieventsEventId) {
    // No event specified - show list of active events
    $events = $db->query(
        "SELECT e.*, COUNT(c.id) as sold_count 
         FROM cow_bingo_events e 
         LEFT JOIN cow_bingo_cells c ON c.event_id = e.id 
         WHERE e.is_active = 1 
         GROUP BY e.id 
         ORDER BY e.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    showEventList($events);
    exit;
}

// Load the specific event
$eventStmt = $db->prepare(
    "SELECT * FROM cow_bingo_events WHERE hievents_event_id = ? AND is_active = 1"
);
$eventStmt->execute([$hieventsEventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    die('Event not found or not active.');
}

$gridCols = (int) $event['grid_cols'];
$gridRows = (int) $event['grid_rows'];
$eventName = $event['event_name'];
$eventSlug = $event['event_slug'] ?? '';
$eventStart = $event['event_start'] ?? null;
$eventVenue = $event['event_venue'] ?? '';
$bingoEventId = (int) $event['id'];

// Format the start date/time for display
$startDisplay = '';
if ($eventStart) {
    $dt = new DateTime($eventStart);
    $dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
    $startDisplay = $dt->format('l j M Y, g:ia');
}

// Build the buy tickets URL
$buyTicketsUrl = HIEVENTS_BASE_URL . '/event/' . $hieventsEventId;
if ($eventSlug) {
    $buyTicketsUrl .= '/' . $eventSlug;
}

// Fetch booked cells for this event
$cellStmt = $db->prepare(
    "SELECT cell_reference, ticket_id, buyer_name, buyer_email, prize, created_at 
     FROM cow_bingo_cells WHERE event_id = ?"
);
$cellStmt->execute([$bingoEventId]);
$bookings = [];
while ($row = $cellStmt->fetch(PDO::FETCH_ASSOC)) {
    $bookings[$row['cell_reference']] = $row;
}

$cols = array_slice(range('A', 'Z'), 0, $gridCols);
$totalCells = $gridCols * $gridRows;
$soldCount = count($bookings);
$availableCount = $totalCells - $soldCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($eventName) ?> - Cow Bingo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #2d5016 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 20px; color: white; }
        header h1 { font-size: 2.2em; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); margin-bottom: 5px; }
        header p { font-size: 1.1em; opacity: 0.9; }
        .stats { display: flex; justify-content: center; gap: 30px; margin-bottom: 20px; }
        .stat-box {
            background: rgba(255,255,255,0.95); border-radius: 8px;
            padding: 12px 24px; text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .stat-box .number { font-size: 1.8em; font-weight: bold; color: #2d5016; }
        .stat-box .label { font-size: 0.85em; color: #666; text-transform: uppercase; }
        .buy-link {
            background: #2d5016 !important; text-decoration: none;
            transition: background 0.2s, transform 0.2s;
        }
        .buy-link .number { color: white; }
        .buy-link .label { color: rgba(255,255,255,0.9); font-weight: bold; }
        .buy-link:hover { background: #3d6b20 !important; transform: scale(1.05); }
        .grid-wrapper {
            background: rgba(255,255,255,0.95); border-radius: 12px;
            padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); overflow-x: auto;
        }
        .grid-header {
            display: grid;
            grid-template-columns: 35px repeat(<?= $gridCols ?>, 1fr);
            gap: 2px; margin-bottom: 2px;
        }
        .grid-header .col-label {
            text-align: center; font-weight: bold; font-size: 12px; color: #555; padding: 4px 0;
        }
        .grid-row {
            display: grid;
            grid-template-columns: 35px repeat(<?= $gridCols ?>, 1fr);
            gap: 2px; margin-bottom: 2px;
        }
        .row-label {
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 12px; color: #555;
        }
        .cell {
            aspect-ratio: 1; min-width: 40px;
            background-color: #7bc67b; border: 1px solid #5aa35a; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: bold; color: #2c5e2c;
            cursor: default; transition: all 0.15s ease; position: relative;
        }
        .cell.taken {
            background-color: #e8a87c; border-color: #d4845a; color: #6b3a1a; cursor: pointer;
        }
        .cell.winner {
            background-color: #ffd700; border-color: #daa520; color: #5c4a00;
            animation: winner-glow 2s ease-in-out infinite;
        }
        .cell.winner .poo-icon { width: 100%; height: 100%; pointer-events: none; border: none; }
        @keyframes winner-glow {
            0%, 100% { box-shadow: 0 0 6px rgba(255, 215, 0, 0.6); }
            50% { box-shadow: 0 0 14px rgba(255, 215, 0, 1); }
        }
        .cell:hover { transform: scale(1.1); z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .cell.taken:hover { background-color: #f0b88a; }
        .cell.winner:hover { background-color: #ffe44d; }
        .tooltip {
            display: none; position: fixed; background: #333; color: white;
            padding: 10px 14px; border-radius: 6px; font-size: 13px; line-height: 1.5;
            z-index: 9999; pointer-events: none; white-space: nowrap;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .tooltip::after {
            content: ''; position: absolute; top: 100%; left: 50%;
            transform: translateX(-50%); border: 6px solid transparent; border-top-color: #333;
        }
        .legend { display: flex; justify-content: center; gap: 20px; margin-top: 15px; font-size: 0.9em; }
        .legend-item { display: flex; align-items: center; gap: 6px; }
        .legend-swatch { width: 18px; height: 18px; border-radius: 3px; border: 1px solid #999; }
        .legend-swatch.available { background-color: #7bc67b; border-color: #5aa35a; }
        .legend-swatch.sold { background-color: #e8a87c; border-color: #d4845a; }
        .legend-swatch.winner { background-color: #ffd700; border-color: #daa520; }

        /* Search bar */
        .search-bar {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px; background: rgba(255,255,255,0.95);
            padding: 12px 20px; border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            flex-wrap: wrap;
        }
        .search-bar input {
            flex: 1; min-width: 200px; padding: 10px 14px;
            border: 2px solid #ddd; border-radius: 6px; font-size: 0.95em;
            outline: none; transition: border-color 0.2s;
        }
        .search-bar input:focus { border-color: #2d5016; }
        .search-bar button {
            padding: 10px 20px; border: none; border-radius: 6px;
            font-weight: bold; cursor: pointer; font-size: 0.9em;
        }
        #searchBtn { background: #2d5016; color: white; }
        #searchBtn:hover { background: #3d6b20; }
        #clearBtn { background: #dc3545; color: white; }
        #clearBtn:hover { background: #c82333; }
        #searchResult { font-size: 0.9em; color: #555; margin-left: 5px; }

        /* Highlighted cells */
        .cell.highlight {
            border: 3px solid #ffd700 !important;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.8) !important;
            transform: scale(1.15);
            z-index: 20;
            animation: pulse-highlight 1.5s ease-in-out infinite;
        }
        @keyframes pulse-highlight {
            0%, 100% { box-shadow: 0 0 12px rgba(255, 215, 0, 0.8); }
            50% { box-shadow: 0 0 20px rgba(255, 215, 0, 1); }
        }
        /* Dim non-matching cells when searching */
        .cell.dimmed { opacity: 0.3; }

        .legend-swatch.highlight { background-color: #e8a87c; border: 3px solid #ffd700; }
        @media (max-width: 700px) {
            .cell { min-width: 28px; font-size: 10px; }
            header h1 { font-size: 1.5em; }
            .grid-wrapper { padding: 10px; }
        }

        /* Winners summary */
        .winners-summary {
            background: rgba(255,255,255,0.95); border-radius: 12px;
            padding: 20px; margin-top: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        .winners-summary h2 {
            margin: 0 0 15px; color: #5c4a00; font-size: 1.3em;
        }
        .winners-list {
            display: flex; flex-wrap: wrap; gap: 12px;
        }
        .winner-card {
            display: flex; align-items: center; gap: 12px;
            background: linear-gradient(135deg, #fff8e1, #fffde7);
            border: 2px solid #ffd700; border-radius: 8px;
            padding: 12px 16px; min-width: 200px;
            box-shadow: 0 2px 6px rgba(255,215,0,0.3);
        }
        .winner-cell {
            background: #ffd700; color: #5c4a00; font-weight: bold;
            font-size: 1.1em; padding: 6px 10px; border-radius: 6px;
            min-width: 40px; text-align: center;
        }
        .winner-prize { font-weight: bold; color: #333; font-size: 1em; }
        .winner-name { color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1><?= htmlspecialchars(SITE_TITLE) ?></h1>
        <p><?= htmlspecialchars($eventName) ?></p>
        <?php if ($startDisplay || $eventVenue): ?>
            <p style="margin-top:5px; font-size:0.95em; opacity:0.9;">
                <?php if ($startDisplay): ?>📅 <?= htmlspecialchars($startDisplay) ?><?php endif; ?>
                <?php if ($startDisplay && $eventVenue): ?> &mdash; <?php endif; ?>
                <?php if ($eventVenue): ?>📍 <?= htmlspecialchars($eventVenue) ?><?php endif; ?>
            </p>
        <?php endif; ?>
    </header>
    <div class="stats">
        <div class="stat-box"><div class="number"><?= $soldCount ?></div><div class="label">Sold</div></div>
        <div class="stat-box"><div class="number"><?= $availableCount ?></div><div class="label">Available</div></div>
        <a href="<?= htmlspecialchars($buyTicketsUrl) ?>" target="_blank" class="stat-box buy-link"><div class="number">🎟️</div><div class="label">Buy More Tickets</div></a>
    </div>

    <!-- Find My Cells search -->
    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="🔍 Find my cells (enter your name or order ID)..." />
        <button id="searchBtn" onclick="findCells()">Find</button>
        <button id="clearBtn" onclick="clearSearch()" style="display:none;">Clear</button>
        <span id="searchResult"></span>
    </div>
    <div class="grid-wrapper">
        <div class="grid-header">
            <div></div>
            <?php foreach ($cols as $col): ?>
                <div class="col-label"><?= $col ?></div>
            <?php endforeach; ?>
        </div>
        <?php for ($row = 1; $row <= $gridRows; $row++): ?>
            <div class="grid-row">
                <div class="row-label"><?= $row ?></div>
                <?php foreach ($cols as $col):
                    $cellRef = $col . $row;
                    $isTaken = isset($bookings[$cellRef]);
                    $isWinner = $isTaken && !empty($bookings[$cellRef]['prize']);
                    $dataAttrs = '';
                    $classes = 'cell';
                    if ($isTaken) {
                        $b = $bookings[$cellRef];
                        $classes .= ' taken';
                        if ($isWinner) $classes .= ' winner';
                        $dataAttrs = sprintf(
                            'data-cell="%s" data-name="%s" data-ticket="%s" data-prize="%s"',
                            htmlspecialchars($cellRef),
                            htmlspecialchars($b['buyer_name']),
                            htmlspecialchars($b['ticket_id']),
                            htmlspecialchars($b['prize'] ?? '')
                        );
                    }
                ?>
                    <div class="<?= $classes ?>" <?= $dataAttrs ?>><?= $isWinner ? '<object data="cow_poo_2.svg" type="image/svg+xml" class="poo-icon"></object>' : $cellRef ?></div>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
        <div class="legend">
            <div class="legend-item"><div class="legend-swatch available"></div><span>Available</span></div>
            <div class="legend-item"><div class="legend-swatch sold"></div><span>Sold</span></div>
            <div class="legend-item"><div class="legend-swatch winner"></div><span>Winner <img src="cow_poo_2.svg" style="width:36px;height:36px;vertical-align:middle;"></span></div>
            <div class="legend-item"><div class="legend-swatch highlight"></div><span>Your Cells</span></div>
        </div>
    </div>

    <?php
    // Collect winners for the summary
    $winners = array_filter($bookings, fn($b) => !empty($b['prize']));
    ?>
    <?php if (!empty($winners)): ?>
    <div class="winners-summary">
        <h2><img src="cow_poo_2.svg" style="width:56px;height:56px;vertical-align:middle;"> Winners</h2>
        <div class="winners-list">
            <?php foreach ($winners as $ref => $w): ?>
            <div class="winner-card">
                <div class="winner-cell"><?= htmlspecialchars($ref) ?></div>
                <div class="winner-details">
                    <div class="winner-prize"><?= htmlspecialchars($w['prize']) ?></div>
                    <div class="winner-name"><?= htmlspecialchars($w['buyer_name']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="tooltip" id="tooltip"></div>
<script>
    const tooltip = document.getElementById('tooltip');
    const allCells = document.querySelectorAll('.cell');
    const takenCells = document.querySelectorAll('.cell.taken');

    // Tooltip
    takenCells.forEach(cell => {
        cell.addEventListener('mouseenter', (e) => {
            let html = `<strong>${cell.dataset.cell}</strong><br>🎫 Ticket: ${cell.dataset.ticket}<br>👤 ${cell.dataset.name}`;
            if (cell.dataset.prize) {
                html += `<br><img src="cow_poo_2.svg" style="width:16px;height:16px;vertical-align:middle;display:inline;"> <strong>${cell.dataset.prize}</strong>`;
            }
            tooltip.innerHTML = html;
            tooltip.style.display = 'block';
            positionTooltip(e);
        });
        cell.addEventListener('mousemove', positionTooltip);
        cell.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
    });
    function positionTooltip(e) {
        const rect = tooltip.getBoundingClientRect();
        tooltip.style.left = (e.clientX - rect.width / 2) + 'px';
        tooltip.style.top = (e.clientY - rect.height - 15) + 'px';
    }

    // Find cells
    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('keyup', (e) => { if (e.key === 'Enter') findCells(); });

    function findCells() {
        const query = searchInput.value.trim().toLowerCase();
        if (!query) return;

        let matchCount = 0;
        allCells.forEach(cell => {
            cell.classList.remove('highlight', 'dimmed');
            if (cell.classList.contains('taken')) {
                const name = (cell.dataset.name || '').toLowerCase();
                const ticket = (cell.dataset.ticket || '').toLowerCase();
                if (name.includes(query) || ticket.includes(query)) {
                    cell.classList.add('highlight');
                    matchCount++;
                } else {
                    cell.classList.add('dimmed');
                }
            } else {
                cell.classList.add('dimmed');
            }
        });

        const resultEl = document.getElementById('searchResult');
        if (matchCount > 0) {
            resultEl.innerHTML = `✅ Found <strong>${matchCount}</strong> cell${matchCount > 1 ? 's' : ''}`;
        } else {
            resultEl.innerHTML = `❌ No cells found for "${searchInput.value.trim()}"`;
        }
        document.getElementById('clearBtn').style.display = 'inline-block';
    }

    function clearSearch() {
        searchInput.value = '';
        allCells.forEach(cell => { cell.classList.remove('highlight', 'dimmed'); });
        document.getElementById('searchResult').innerHTML = '';
        document.getElementById('clearBtn').style.display = 'none';
    }

    // Stagger poo animations by delaying object load
    document.querySelectorAll('.cell.winner .poo-icon').forEach((obj, i) => {
        const src = obj.getAttribute('data');
        obj.removeAttribute('data');
        setTimeout(() => { obj.setAttribute('data', src); }, i * 400 + Math.random() * 600);
    });
</script>
</body>
</html>
<?php

/**
 * Shows a list of active events when no specific event is requested.
 */
function showEventList(array $events): void
{
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
            min-height: 100vh; padding: 40px; color: #333;
        }
        .container { max-width: 700px; margin: 0 auto; }
        h1 { color: white; text-align: center; margin-bottom: 30px; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .event-card {
            background: white; border-radius: 8px; padding: 20px; margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15); display: flex;
            justify-content: space-between; align-items: center;
        }
        .event-card h2 { margin: 0 0 5px; color: #2d5016; }
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
            <a href="?event=<?= htmlspecialchars($ev['hievents_event_id']) ?>">View Grid</a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php
}
