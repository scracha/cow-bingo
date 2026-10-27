<?php
// views/grid_view.php

if (!function_exists('makeClickableLinks')) {
    function makeClickableLinks(string $html): string {
        return preg_replace(
            '/(https?:\/\/[^\s<]+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $html
        );
    }
}

if (!function_exists('formatCellRef')) {
    function formatCellRef(string $ref, int $gridCols = 16, string $mode = 'alpha'): string {
        if ($mode !== 'numeric') {
            return strtoupper($ref);
        }
        if (preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
            $colLetters = strtoupper($m[1]);
            $row = (int)$m[2];
            $col = 0;
            for ($i = 0; $i < strlen($colLetters); $i++) {
                $col = $col * 26 + (ord($colLetters[$i]) - 64);
            }
            return (string)(($row - 1) * $gridCols + $col);
        }
        return $ref;
    }
}

$event = $event ?? [];
$eventName = $event['event_name'] ?? 'Cow Pat Bingo';
$gridCols = max(2, (int)($event['grid_cols'] ?? 16));
$gridRows = max(2, (int)($event['grid_rows'] ?? 16));
$totalCells = $gridCols * $gridRows;
$cellNaming = $event['cell_naming'] ?? 'alpha';
$eventDesc = $event['event_description'] ?? '';
$eventVenue = $event['event_venue'] ?? '';
$eventStart = $event['event_start'] ?? null;
$startDisplay = !empty($eventStart) ? date('D, d M Y, g:ia', strtotime($eventStart)) : '';
$isActive = !empty($event['is_active']);
$isAdmin = !empty($_SESSION['admin_id']);

// Generate column letters (A, B, C...)
if (empty($cols) || !is_array($cols)) {
    $cols = [];
    for ($c = 1; $c <= $gridCols; $c++) {
        $letters = '';
        $temp = $c;
        while ($temp > 0) {
            $rem = ($temp - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $temp = (int)(($temp - $rem - 1) / 26);
        }
        $cols[] = $letters;
    }
}

$bookings = $bookings ?? [];
$soldCount = count($bookings);
$availableCount = max(0, $totalCells - $soldCount);
$winners = array_filter($bookings, fn($b) => !empty($b['prize']));

$hasHiEvents = !empty($event['hievents_event_id']);
$buyTicketsUrl = $buyTicketsUrl ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?= htmlspecialchars($eventName) ?> - Cow Pat Bingo</title>
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

        .event-details-card {
            background: rgba(255,255,255,0.96);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            line-height: 1.6;
            font-size: 0.95em;
            color: #2b3a1a;
        }
        .event-details-card a { color: #15803d; font-weight: bold; text-decoration: underline; }

        .stats { display: flex; justify-content: center; gap: 30px; margin-bottom: 20px; flex-wrap: wrap; }
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
        .btn-print-judges {
            background: #ffffff; border: 1.5px solid #2d5016; border-radius: 8px;
            padding: 10px 16px; font-weight: bold; color: #2d5016; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em;
        }
        .btn-print-judges:hover { background: #f0fdf4; }

        .grid-wrapper {
            background: rgba(255,255,255,0.95); border-radius: 12px;
            padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); overflow-x: auto;
            -webkit-overflow-scrolling: touch;
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
            background: radial-gradient(circle, #fffbeb 10%, #fde047 55%, #eab308 100%) !important;
            border: 2px solid #854d0e !important;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: winner-glow 2s ease-in-out infinite;
        }
        .cell.winner .winner-ref {
            position: absolute;
            top: 2px;
            left: 2px;
            font-size: 9px;
            font-weight: 900;
            color: #713f12;
            background: rgba(255, 255, 255, 0.85);
            padding: 0 3px;
            border-radius: 2px;
            line-height: 1.1;
            pointer-events: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .cell.winner .poo-icon {
            width: 78%;
            height: 78%;
            max-width: 32px;
            max-height: 32px;
            object-fit: contain;
            pointer-events: none;
            filter: drop-shadow(0 2px 3px rgba(0,0,0,0.4));
        }
        @keyframes winner-glow {
            0%, 100% { box-shadow: 0 0 6px rgba(202, 138, 4, 0.7); }
            50% { box-shadow: 0 0 16px rgba(234, 179, 8, 1); }
        }
        .cell:hover { transform: scale(1.1); z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        .cell.taken:hover { background-color: #f0b88a; }
        .cell.winner:hover { background: radial-gradient(circle, #ffffff 10%, #fef08a 55%, #facc15 100%) !important; }

        .cell.taken.admin-unpaid {
            background-image: repeating-linear-gradient(
                45deg,
                rgba(220, 53, 69, 0.3),
                rgba(220, 53, 69, 0.3) 6px,
                transparent 6px,
                transparent 12px
            ) !important;
            border: 2px dashed #dc3545 !important;
        }

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
        .legend { display: flex; justify-content: center; gap: 20px; margin-top: 15px; font-size: 0.9em; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: 6px; }
        .legend-swatch { width: 18px; height: 18px; border-radius: 3px; border: 1px solid #999; }
        .legend-swatch.available { background-color: #7bc67b; border-color: #5aa35a; }
        .legend-swatch.sold { background-color: #e8a87c; border-color: #d4845a; }
        .legend-swatch.winner { background: radial-gradient(circle, #fffbeb 10%, #fde047 55%, #eab308 100%); border-color: #854d0e; }
        .legend-swatch.highlight { background-color: #e8a87c; border: 3px solid #ffd700; }
        .legend-swatch.admin-unpaid {
            background-color: #e8a87c;
            background-image: repeating-linear-gradient(45deg, rgba(220, 53, 69, 0.4), rgba(220, 53, 69, 0.4) 3px, transparent 3px, transparent 6px);
            border: 2px dashed #dc3545;
        }

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
            outline: none;
        }
        .search-bar button {
            padding: 10px 20px; border: none; border-radius: 6px;
            font-weight: bold; cursor: pointer; font-size: 0.9em;
        }
        #searchBtn { background: #2d5016; color: white; }
        #clearBtn { background: #dc3545; color: white; }
        #searchResult { font-size: 0.9em; color: #555; margin-left: 5px; }

        .cell.highlight {
            border: 3px solid #ffd700 !important;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.8) !important;
            transform: scale(1.15);
            z-index: 20;
        }
        .cell.dimmed { opacity: 0.3; }

        .winners-summary {
            background: rgba(255,255,255,0.95); border-radius: 12px;
            padding: 20px; margin-top: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        .winners-summary h2 { margin: 0 0 15px; color: #5c4a00; font-size: 1.3em; }
        .winners-list { display: flex; flex-wrap: wrap; gap: 12px; }
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

        /* PRINT STYLES FOR OFFICIAL PADDOCK JUDGES */
        .print-only { display: none; }
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .container { max-width: 100% !important; margin: 0 !important; width: 100% !important; }
            header, .stats, .search-bar, .tooltip, .buy-link, .no-print, .event-details-card { display: none !important; }
            .grid-wrapper { box-shadow: none !important; padding: 0 !important; border: none !important; }
            .cell {
                border: 1px solid #222 !important;
                background: transparent !important;
                color: #000 !important;
                box-shadow: none !important;
            }
            .cell.taken { background: #e5e5e5 !important; font-size: 9px !important; }
            .cell.winner { font-weight: 900 !important; text-decoration: underline !important; }
            .print-only { display: block !important; }
            .print-judges-box {
                margin-top: 25px;
                border: 2px solid #000;
                padding: 15px;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <a href="grid.php" style="color:white; text-decoration:none; font-weight:bold; font-size:0.9em;">&larr; Back to Events</a>
        <button type="button" class="btn-print-judges" onclick="window.print()">📋 Print Judges Clipboard Sheet</button>
    </div>

    <header>
        <h1><?= htmlspecialchars(defined('SITE_TITLE') ? SITE_TITLE : '🐄 Cow Pat Bingo 🐄') ?></h1>
        <p style="font-size:1.3em; font-weight:bold; margin-top:4px;"><?= htmlspecialchars($eventName) ?></p>
        <?php if ($startDisplay || $eventVenue): ?>
            <p style="margin-top:5px; font-size:0.95em; opacity:0.9;">
                <?php if ($startDisplay): ?>📅 <?= htmlspecialchars($startDisplay) ?><?php endif; ?>
                <?php if ($startDisplay && $eventVenue): ?> &mdash; <?php endif; ?>
                <?php if ($eventVenue): ?>📍 <?= htmlspecialchars($eventVenue) ?><?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (!$isActive): ?>
            <div class="no-print" style="display:inline-block; margin-top:8px; background:#fef3c7; color:#92400e; padding:4px 12px; border-radius:12px; font-weight:bold; font-size:0.85em;">
                🏁 Event Concluded / Inactive
            </div>
        <?php endif; ?>
    </header>

    <?php if (!empty($eventDesc)): ?>
    <div class="event-details-card no-print">
        <strong>📍 Event Info &amp; Instructions:</strong><br>
        <?= makeClickableLinks(nl2br(htmlspecialchars($eventDesc))) ?>
    </div>
    <?php endif; ?>

    <div class="stats no-print">
        <div class="stat-box"><div class="number"><?= $soldCount ?></div><div class="label">Sold</div></div>
        <div class="stat-box"><div class="number"><?= $availableCount ?></div><div class="label">Available</div></div>
        <?php if ($hasHiEvents && $buyTicketsUrl && $isActive): ?>
            <a href="<?= htmlspecialchars($buyTicketsUrl) ?>" target="_blank" class="stat-box buy-link">
                <div class="number">🎟️</div><div class="label">Buy More Tickets</div>
            </a>
        <?php endif; ?>
    </div>

    <div class="search-bar no-print">
        <input type="text" id="searchInput" placeholder="🔍 Find my cells (enter your name, ticket ID, or square <?= $cellNaming === 'numeric' ? '# 16' : 'D2' ?>)..." />
        <button id="searchBtn" onclick="findCells()">Find</button>
        <button id="clearBtn" onclick="clearSearch()" style="display:none;">Clear</button>
        <span id="searchResult"></span>
    </div>

    <div class="print-only" style="margin-bottom:12px;">
        <h2 style="margin:0;">🐮 <?= htmlspecialchars($eventName) ?> - Field Grid</h2>
        <small>Generated: <?= date('d M Y, g:ia') ?> &bull; Sold: <?= $soldCount ?> / <?= $totalCells ?> squares</small>
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
                    $displayRef = formatCellRef($cellRef, $gridCols, $cellNaming);
                    $numericRef = formatCellRef($cellRef, $gridCols, 'numeric');
                    $isTaken = isset($bookings[$cellRef]);
                    $isWinner = $isTaken && !empty($bookings[$cellRef]['prize']);
                    $dataAttrs = '';
                    $classes = 'cell';
                    if ($isTaken) {
                        $b = $bookings[$cellRef];
                        $classes .= ' taken';
                        if ($isWinner) $classes .= ' winner';
                        if ($isAdmin && empty($b['is_paid'])) $classes .= ' admin-unpaid';

                        $dataAttrs = sprintf(
                            'data-cell="%s" data-display="%s" data-numeric="%s" data-name="%s" data-ticket="%s" data-prize="%s" data-paid="%s"',
                            htmlspecialchars($cellRef),
                            htmlspecialchars($displayRef),
                            htmlspecialchars($numericRef),
                            htmlspecialchars($b['buyer_name']),
                            htmlspecialchars($b['ticket_id']),
                            htmlspecialchars($b['prize'] ?? ''),
                            !empty($b['is_paid']) ? '1' : '0'
                        );
                    } else {
                        $dataAttrs = sprintf('data-cell="%s" data-display="%s" data-numeric="%s"', htmlspecialchars($cellRef), htmlspecialchars($displayRef), htmlspecialchars($numericRef));
                    }
                ?>
                    <div class="<?= $classes ?>" <?= $dataAttrs ?>>
                        <?php if ($isWinner): ?>
                            <span class="winner-ref"><?= $displayRef ?></span>
                            <img src="cow_poo_2.svg" class="poo-icon" alt="Winner <?= $displayRef ?>">
                        <?php else: ?>
                            <?= $displayRef ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
        <div class="legend no-print">
            <div class="legend-item"><div class="legend-swatch available"></div><span>Available</span></div>
            <div class="legend-item"><div class="legend-swatch sold"></div><span>Sold</span></div>
            <div class="legend-item"><div class="legend-swatch winner"></div><span>Winner <img src="cow_poo_2.svg" style="width:36px;height:36px;vertical-align:middle;"></span></div>
            <div class="legend-item"><div class="legend-swatch highlight"></div><span>Your Cells</span></div>
            <?php if ($isAdmin): ?>
                <div class="legend-item"><div class="legend-swatch admin-unpaid"></div><span style="color:#dc3545;font-weight:bold;">Unpaid (Admin Only)</span></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="print-only print-judges-box">
        <h3 style="margin-top:0; border-bottom:1px solid #000; padding-bottom:4px;">🐮 Official Paddock Judges Sign-Off</h3>
        <table style="width:100%; border:none; margin-top:10px; font-size:12px;">
            <tr>
                <td style="width:50%; padding:8px 0;"><strong>Lead Judge Signature:</strong> ____________________________________</td>
                <td style="width:50%; padding:8px 0;"><strong>Time of Drop:</strong> ___________________</td>
            </tr>
            <tr>
                <td style="padding:8px 0;"><strong>Scrutineer Signature:</strong> ___________________________________</td>
                <td style="padding:8px 0;"><strong>Official Winning Cell:</strong> [ &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ]</td>
            </tr>
            <tr>
                <td colspan="2" style="padding:8px 0;"><strong>Notes / Prize Awarded:</strong> __________________________________________________________________</td>
            </tr>
        </table>
    </div>

    <?php if (!empty($winners)): ?>
    <div class="winners-summary no-print">
        <h2><img src="cow_poo_2.svg" style="width:56px;height:56px;vertical-align:middle;"> Winners</h2>
        <div class="winners-list">
            <?php foreach ($winners as $ref => $w): 
                $winDisplay = formatCellRef($ref, $gridCols, $cellNaming);
            ?>
            <div class="winner-card">
                <div class="winner-cell"><?= htmlspecialchars($winDisplay) ?></div>
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

<div class="tooltip no-print" id="tooltip"></div>
<script>
    const tooltip = document.getElementById('tooltip');
    const allCells = document.querySelectorAll('.cell');
    const takenCells = document.querySelectorAll('.cell.taken');
    const cellNaming = '<?= $cellNaming ?>';

    takenCells.forEach(cell => {
        cell.addEventListener('mouseenter', (e) => {
            const disp = cell.dataset.display || cell.dataset.cell;
            let html = `<strong>Square: ${disp}${cellNaming === 'numeric' ? ' (' + cell.dataset.cell + ')' : ''}</strong><br>🎟️ Ticket: ${cell.dataset.ticket}<br>👤 ${cell.dataset.name}`;
            <?php if ($isAdmin): ?>
            if (cell.dataset.paid === "0") {
                html += `<br><span style="color:#ffc107;font-weight:bold;">⚠️ Awaiting Payment (Unpaid)</span>`;
            }
            <?php endif; ?>
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

    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('keyup', (e) => { if (e.key === 'Enter') findCells(); });

    function findCells() {
        const query = searchInput.value.trim().toLowerCase();
        if (!query) return;
        let matchCount = 0;
        allCells.forEach(cell => {
            cell.classList.remove('highlight', 'dimmed');
            const cellDisplay = (cell.dataset.display || '').toLowerCase();
            const cellAlpha = (cell.dataset.cell || '').toLowerCase();
            const cellNumeric = (cell.dataset.numeric || '').toLowerCase();
            const name = (cell.dataset.name || '').toLowerCase();
            const ticket = (cell.dataset.ticket || '').toLowerCase();

            const isMatch = (cellDisplay === query || cellAlpha === query || cellNumeric === query || name.includes(query) || ticket.includes(query));
            if (cell.classList.contains('taken')) {
                if (isMatch) {
                    cell.classList.add('highlight');
                    matchCount++;
                } else {
                    cell.classList.add('dimmed');
                }
            } else {
                if (cellDisplay === query || cellAlpha === query || cellNumeric === query) {
                    cell.classList.add('highlight');
                    matchCount++;
                } else {
                    cell.classList.add('dimmed');
                }
            }
        });
        const resultEl = document.getElementById('searchResult');
        resultEl.innerHTML = matchCount > 0 ? `✅ Found <strong>${matchCount}</strong> cell${matchCount > 1 ? 's' : ''}` : `❌ No cells found for "${searchInput.value.trim()}"`;
        document.getElementById('clearBtn').style.display = 'inline-block';
    }

    function clearSearch() {
        searchInput.value = '';
        allCells.forEach(cell => { cell.classList.remove('highlight', 'dimmed'); });
        document.getElementById('searchResult').innerHTML = '';
        document.getElementById('clearBtn').style.display = 'none';
    }
</script>

<?php if (!empty($_GET['print'])): ?>
<script>
    window.addEventListener('load', () => {
        setTimeout(() => window.print(), 250);
    });
</script>
<?php endif; ?>

</body>
</html>
