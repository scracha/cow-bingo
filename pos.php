<?php
/**
 * Cow Pat Bingo - Field POS / Mobile & iPad Seller Interface with Mobile-Optimized Layout
 */
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

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php';

if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'time' => time()]);
    exit;
}

$db = getDbConnection();

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

$adminId = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Device';
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if ($isSuperAdmin) {
    $evStmt = $db->query("SELECT * FROM cow_bingo_events WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
} else {
    $evStmt = $db->prepare("
        SELECT e.* FROM cow_bingo_events e
        INNER JOIN cow_bingo_admin_events ae ON ae.event_id = e.id
        WHERE ae.admin_id = ? AND e.is_active = 1
        ORDER BY e.id DESC LIMIT 1
    ");
    $evStmt->execute([$adminId]);
    $event = $evStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$event) {
    die("<h3>No active event assigned to this device.</h3><p><a href='admin.php?logout'>Log Out</a></p>");
}

$eventId = (int)$event['id'];
$hieventsId = $event['hievents_event_id'] ?? null;
$allowCellSelection = isset($event['allow_cell_selection']) ? (int)$event['allow_cell_selection'] : 1;
$cellNaming = $event['cell_naming'] ?? 'alpha';
$lastAssigned = null;
$errorMessage = '';

// 1. Resolve Pricing Tiers
$pricingTiers = [];

if (!empty($event['pricing_tiers'])) {
    $decoded = json_decode($event['pricing_tiers'], true);
    if (is_array($decoded) && !empty($decoded)) {
        $pricingTiers = $decoded;
    }
}

if (empty($pricingTiers) && !empty($hieventsId) && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED) && function_exists('fetchHiEventsProducts')) {
    $rawProducts = fetchHiEventsProducts($hieventsId);
    foreach ($rawProducts as $p) {
        $title = strtolower($p['title'] ?? $p['name'] ?? '');
        $price = (float)($p['price'] ?? 0);
        if (preg_match('/(\d+)\s*(square|ticket|pack)/i', $title, $matches)) {
            $qty = (int)$matches[1];
        } elseif (strpos($title, 'single') !== false || strpos($title, '1') !== false) {
            $qty = 1;
        } else {
            $qty = 1;
        }
        $pricingTiers[$qty] = $price;
    }
    if (!empty($pricingTiers)) {
        $db->prepare("UPDATE cow_bingo_events SET pricing_tiers = ? WHERE id = ?")
           ->execute([json_encode($pricingTiers), $eventId]);
    }
}

if (empty($pricingTiers)) {
    $pricingTiers = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
}
krsort($pricingTiers);

// 2. Handle Multi-Cell Cash Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'field_assign') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security session expired. Please reload the page.';
    } else {
        $buyerName = trim($_POST['buyer_name'] ?? '');
        $buyerContact = trim($_POST['buyer_contact'] ?? '');
        $selectedCells = array_filter(array_map('trim', explode(',', $_POST['selected_cells'] ?? '')));

        if (empty($buyerName)) {
            $errorMessage = 'Please enter the buyer\'s name.';
        } elseif (empty($selectedCells)) {
            $errorMessage = 'Please select at least one square.';
        } else {
            $cleanDevice = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($adminName));
            $batchId = 'CASH-' . ($cleanDevice ?: 'POS') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $cellCount = count($selectedCells);
            $batchTotal = calculateBatchPrice($cellCount, $pricingTiers);
            $pricePerSquare = round($batchTotal / max(1, $cellCount), 2);
            
            $db->beginTransaction();
            $assignedList = [];
            $hasCollision = false;

            $insertStmt = $db->prepare("
                INSERT INTO cow_bingo_cells 
                (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, order_amount, price_paid, payment_method, assigned_by)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, 'cash', ?)
            ");

            $i = 1;
            $colsForNorm = (int)$event['grid_cols'];
            foreach ($selectedCells as $cell) {
                $ticketId = $batchId . '-' . $i++;
                $canonCell = normalizeCellRef($cell, $colsForNorm);
                try {
                    $insertStmt->execute([
                        $eventId, 
                        $ticketId, 
                        $batchId, 
                        $buyerName, 
                        $buyerContact ?: null, 
                        strtoupper($canonCell), 
                        $batchTotal, 
                        $pricePerSquare, 
                        $adminName
                    ]);
                    $assignedList[] = strtoupper($canonCell);
                } catch (PDOException $e) {
                    $hasCollision = true;
                    break;
                }
            }

            if ($hasCollision) {
                $db->rollBack();
                $errorMessage = "⚠️  Square $cell was just taken by another seller! Please pick a different square.";
            } else {
                $db->commit();
                $gridCols = (int)$event['grid_cols'];
                $cellStr = implode(', ', array_map(fn($c) => formatCellRef($c, $gridCols, $cellNaming), $assignedList));
                logAuditEvent($db, $eventId, 'cell_assigned', $cellStr, $batchId, $batchId, $buyerName, $batchTotal, "POS Cash Sale ({$cellCount} sq): $cellStr", $adminName);
                $lastAssigned = [
                    'cells' => array_map(fn($c) => formatCellRef($c, $gridCols, $cellNaming), $assignedList),
                    'name' => $buyerName,
                    'batch' => $batchId,
                    'total' => number_format($batchTotal, 2)
                ];
            }
        }
    }
}

// 3. Load Current Grid State
$gridCols = (int)$event['grid_cols'];
$gridRows = (int)$event['grid_rows'];
$colLetters = array_slice(range('A', 'Z'), 0, $gridCols);

$allBookings = getEventBookings($db, $eventId);
$soldCells = array_column($allBookings, 'buyer_name', 'cell_reference');

$recentStmt = $db->prepare("
    SELECT cell_reference, buyer_name, ticket_id, created_at 
    FROM cow_bingo_cells 
    WHERE event_id = ? AND assigned_by = ? 
    ORDER BY id DESC LIMIT 5
");
$recentStmt->execute([$eventId, $adminName]);
$recentSales = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?= htmlspecialchars($event['event_name']) ?> - Cow Pat Bingo POS</title>
    <style>
        * { box-sizing: border-box; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1f3a0f; color: #fff; margin: 0; padding: 10px 10px 140px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .badge { background: #4a7c23; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 0.9em; }
        
        .mode-banner {
            background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;
            padding: 8px 12px; border-radius: 8px; font-weight: bold; font-size: 0.88em;
            margin-bottom: 10px; text-align: center;
        }

        .grid-container { background: white; padding: 10px; border-radius: 12px; overflow-x: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.3); -webkit-overflow-scrolling: touch; }
        .grid { display: grid; grid-template-columns: 24px repeat(<?= $gridCols ?>, minmax(32px, 1fr)); gap: 3px; }
        .header-cell { color: #666; font-size: 11px; font-weight: bold; display: flex; align-items: center; justify-content: center; }
        .cell {
            aspect-ratio: 1; border-radius: 4px; font-size: 11px; font-weight: bold;
            display: flex; align-items: center; justify-content: center; user-select: none;
            background: #7bc67b; border: 1px solid #5aa35a; color: #1e461e;
            cursor: <?= $allowCellSelection ? 'pointer' : 'default' ?>;
        }
        .cell.sold { background: #e0a37e; border-color: #ba7c57; color: #5a2e12; opacity: 0.6; pointer-events: none; }
        .cell.selected {
            background: #ffc107 !important; border: 2px solid #d39e00 !important; color: #000 !important;
            transform: scale(1.08); z-index: 2; box-shadow: 0 0 8px rgba(255, 193, 7, 0.8);
        }
        
        .quick-pick {
            display: flex;
            gap: 6px;
            margin: 10px 0;
            align-items: center;
            flex-wrap: wrap;
        }
        .quick-label { font-size: 0.82em; opacity: 0.85; font-weight: bold; margin-right: 2px; }
        .quick-btn {
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.35);
            color: white;
            padding: 7px 11px;
            border-radius: 16px;
            font-weight: bold;
            font-size: 0.82em;
            cursor: pointer;
            white-space: nowrap;
            flex: 1 1 auto;
            text-align: center;
        }
        .quick-btn:active { background: #ffc107; color: #000; }
        .quick-btn.btn-clear { background: #dc3545; border: none; flex: 0 1 auto; }

        .checkout-tray {
            position: fixed; bottom: 0; left: 0; right: 0; background: #ffffff; color: #222;
            padding: 10px 14px 14px; box-shadow: 0 -4px 20px rgba(0,0,0,0.4); border-top-left-radius: 16px; border-top-right-radius: 16px; z-index: 100;
        }
        .tray-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .tray-total { font-size: 1.3em; font-weight: 900; color: #2d5016; }
        
        .inputs-row {
            display: flex;
            gap: 8px;
        }
        .inputs-row input {
            flex: 1;
            min-width: 0;
            padding: 10px 12px;
            border: 2px solid #ccc;
            border-radius: 8px;
            font-size: 0.95em;
        }
        .btn-confirm {
            background: #2d5016; color: white; border: none; padding: 11px 18px;
            border-radius: 8px; font-size: 1em; font-weight: bold; cursor: pointer; white-space: nowrap;
        }
        .btn-confirm:disabled { background: #aaa; cursor: not-allowed; }

        @media (max-width: 600px) {
            body { padding: 8px 8px 170px; }
            .inputs-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }
            .inputs-row input {
                padding: 9px 10px;
                font-size: 0.9em;
            }
            .btn-confirm {
                grid-column: 1 / -1;
                width: 100%;
                padding: 12px;
                font-size: 1.05em;
            }
            .quick-btn {
                padding: 6px 8px;
                font-size: 0.78em;
            }
            .grid { gap: 2px; }
        }

        .banner-success { background: #d4edda; color: #155724; border: 2px solid #28a745; padding: 10px; border-radius: 8px; margin-bottom: 10px; text-align: center; font-size: 0.9em; }
        .banner-error { background: #f8d7da; color: #721c24; border: 2px solid #dc3545; padding: 10px; border-radius: 8px; margin-bottom: 10px; text-align: center; font-size: 0.9em; }
        .recent-sales { margin-top: 15px; font-size: 0.85em; opacity: 0.9; }
    </style>
</head>
<body>

<div class="top-bar">
    <span class="badge">📱 <?= htmlspecialchars($adminName) ?></span>
    <span style="font-size:0.85em;"><?= $allowCellSelection ? 'Tap squares or pick random:' : 'Random Mode active:' ?></span>
    <a href="admin.php?logout" style="color:#ffc107;text-decoration:none;font-size:0.85em;">Logout</a>
</div>

<?php if (!$allowCellSelection): ?>
    <div class="mode-banner">
        🎲 <strong>Random Allocation Only Mode</strong> â€” Square tapping is disabled. Tap +1, +3, +5, or +10 below to assign tickets.
    </div>
<?php endif; ?>

<?php if ($lastAssigned): ?>
    <div class="banner-success">
        🎉 <strong>SOLD <?= count($lastAssigned['cells']) ?> SQUARES ($<?= htmlspecialchars($lastAssigned['total']) ?>)!</strong><br>
        Cells: <strong><?= implode(', ', $lastAssigned['cells']) ?></strong> assigned to <strong><?= htmlspecialchars($lastAssigned['name']) ?></strong>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="banner-error"><?= htmlspecialchars($errorMessage) ?></div>
<?php endif; ?>

<div class="quick-pick">
    <span class="quick-label">🎲 Quick:</span>
    <button type="button" class="quick-btn" onclick="addRandomSquares(1)">+1</button>
    <button type="button" class="quick-btn" onclick="addRandomSquares(3)">+3</button>
    <button type="button" class="quick-btn" onclick="addRandomSquares(5)">+5</button>
    <button type="button" class="quick-btn" onclick="addRandomSquares(10)">+10</button>
    <button type="button" class="quick-btn btn-clear" onclick="clearSelected()">Clear</button>
</div>

<div class="grid-container">
    <div class="grid">
        <div class="header-cell"></div>
        <?php foreach ($colLetters as $col): ?>
            <div class="header-cell"><?= $col ?></div>
        <?php endforeach; ?>

        <?php for ($r = 1; $r <= $gridRows; $r++): ?>
            <div class="header-cell"><?= $r ?></div>
            <?php foreach ($colLetters as $c): 
                $ref = $c . $r;
                $displayRef = formatCellRef($ref, $gridCols, $cellNaming);
                $isSold = isset($soldCells[$ref]);
            ?>
                <div class="cell <?= $isSold ? 'sold' : 'available' ?>" 
                     data-cell="<?= $ref ?>" 
                     data-display="<?= $displayRef ?>" 
                     onclick="<?= $allowCellSelection ? 'toggleCell(this)' : '' ?>">
                    <?= $displayRef ?>
                </div>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>

<?php if (!empty($recentSales)): ?>
<div class="recent-sales">
    <strong>Recent Sales on this device:</strong>
    <?php foreach ($recentSales as $s): 
        $disp = formatCellRef($s['cell_reference'], $gridCols, $cellNaming);
    ?>
        <span style="background:rgba(255,255,255,0.15);padding:2px 8px;border-radius:10px;margin-right:5px;display:inline-block;margin-top:4px;">
            <?= htmlspecialchars($disp) ?> (<?= htmlspecialchars($s['buyer_name']) ?>)
        </span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="checkout-tray">
    <form method="POST" id="posForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="field_assign">
        <input type="hidden" name="selected_cells" id="selectedCellsInput" value="">
        <input type="hidden" name="calculated_total" id="calculatedTotalInput" value="0.00">

        <div class="tray-top">
            <span id="selectedSummary" style="font-weight:bold;font-size:0.9em;">0 squares selected</span>
            <span class="tray-total" id="totalDisplay">$0.00</span>
        </div>
        <div class="inputs-row">
            <input type="text" name="buyer_name" id="buyerName" placeholder="Buyer Name *" required>
            <input type="text" name="buyer_contact" placeholder="Phone / Email">
            <button type="submit" class="btn-confirm" id="submitBtn" disabled>Collect Cash</button>
        </div>
    </form>
</div>

<script>
    const pricingTiers = <?= json_encode($pricingTiers) ?>;
    const allowCellSelection = <?= $allowCellSelection ? 'true' : 'false' ?>;
    const cellNaming = '<?= $cellNaming ?>';
    const gridCols = <?= $gridCols ?>;
    let selected = new Set();

    setInterval(() => {
        fetch('pos.php?ping=1').catch(() => {});
    }, 180000);

    document.getElementById('posForm').addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        if (btn.disabled || btn.dataset.submitting === '1') {
            e.preventDefault();
            return false;
        }
        btn.dataset.submitting = '1';
        btn.disabled = true;
        btn.textContent = 'Recording Cash... ⏳';
        return true;
    });

    function formatRef(ref) {
        if (cellNaming !== 'numeric') return ref;
        const m = ref.match(/^([A-Z]+)([0-9]+)$/);
        if (!m) return ref;
        let col = 0;
        for (let i = 0; i < m[1].length; i++) {
            col = col * 26 + (m[1].charCodeAt(i) - 64);
        }
        const row = parseInt(m[2], 10);
        return ((row - 1) * gridCols) + col;
    }

    function toggleCell(el) {
        if (!allowCellSelection) return;
        if (el.classList.contains('sold')) return;
        const ref = el.dataset.cell;
        if (selected.has(ref)) {
            selected.delete(ref);
            el.classList.remove('selected');
        } else {
            selected.add(ref);
            el.classList.add('selected');
        }
        updateTotals();
    }

    function addRandomSquares(count) {
        const available = Array.from(document.querySelectorAll('.cell.available:not(.selected)'));
        if (available.length === 0) return alert('No more squares available in the paddock!');
        
        for (let i = 0; i < count; i++) {
            if (available.length === 0) break;
            const randIndex = Math.floor(Math.random() * available.length);
            const chosen = available.splice(randIndex, 1)[0];
            selected.add(chosen.dataset.cell);
            chosen.classList.add('selected');
        }
        updateTotals();
    }

    function clearSelected() {
        document.querySelectorAll('.cell.selected').forEach(c => c.classList.remove('selected'));
        selected.clear();
        updateTotals();
    }

    function calculateBulkPrice(qty) {
        if (qty === 0) return 0.00;
        let total = 0;
        let rem = qty;
        
        const sortedTiers = Object.keys(pricingTiers).map(Number).sort((a,b) => b - a);
        for (let tier of sortedTiers) {
            if (rem >= tier) {
                const countOfTier = Math.floor(rem / tier);
                total += countOfTier * pricingTiers[tier];
                rem %= tier;
            }
        }
        return total.toFixed(2);
    }

    function updateTotals() {
        const qty = selected.size;
        const total = calculateBulkPrice(qty);
        
        document.getElementById('selectedCellsInput').value = Array.from(selected).join(',');
        document.getElementById('calculatedTotalInput').value = total;
        document.getElementById('totalDisplay').textContent = '$' + total;
        const displayList = Array.from(selected).map(formatRef);
        document.getElementById('selectedSummary').textContent = `${qty} sq (${displayList.join(', ') || 'None'})`;
        document.getElementById('submitBtn').disabled = (qty === 0);
    }
</script>
</body>
</html>
