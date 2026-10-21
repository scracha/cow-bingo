<?php
/**
 * Cow Bingo - Field POS / iPad Seller Interface with Interactive Grid & Discounts
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php'; // <-- ADD THIS
session_start();

$db = getDbConnection();

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

$adminId = (int)$_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'iPad';
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

// Determine event
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
    die("<h3>No active event assigned to this iPad.</h3><p><a href='admin.php?logout'>Log Out</a></p>");
}

$eventId = (int)$event['id'];
$hieventsId = $event['hievents_event_id'];
$lastAssigned = null;
$errorMessage = '';

// 1. Fetch products & bulk tiers from hi.events (fallback to standard if none configured)
$rawProducts = function_exists('fetchHiEventsProducts') ? fetchHiEventsProducts($hieventsId) : [];
$pricingTiers = [];

foreach ($rawProducts as $p) {
    $title = strtolower($p['title'] ?? $p['name'] ?? '');
    $price = (float)($p['price'] ?? 0);
    // Detect bundle sizes from title (e.g., "5 Squares", "3 Pack", "Single")
    if (preg_match('/(\d+)\s*(square|ticket|pack)/i', $title, $matches)) {
        $qty = (int)$matches[1];
    } elseif (strpos($title, 'single') !== false || strpos($title, '1') !== false) {
        $qty = 1;
    } else {
        $qty = 1;
    }
    $pricingTiers[$qty] = $price;
}

// Fallback pricing if hi.events products aren't configured with bundles
if (empty($pricingTiers)) {
    $pricingTiers = [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
}
krsort($pricingTiers); // Sort highest quantity first for greedy discount calculation

// 2. Handle Multi-Cell Cash Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'field_assign') {
    $buyerName = trim($_POST['buyer_name'] ?? '');
    $buyerContact = trim($_POST['buyer_contact'] ?? '');
    $selectedCells = array_filter(array_map('trim', explode(',', $_POST['selected_cells'] ?? '')));

    if (empty($buyerName)) {
        $errorMessage = 'Please enter the buyer\'s name.';
    } elseif (empty($selectedCells)) {
        $errorMessage = 'Please tap at least one square on the grid.';
    } else {
        $cleanDevice = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($adminName));
        $batchId = 'CASH-' . ($cleanDevice ?: 'IPAD') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        
        $db->beginTransaction();
        $assignedList = [];
        $hasCollision = false;

        $insertStmt = $db->prepare("
            INSERT INTO cow_bingo_cells 
            (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, assigned_by) 
            VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ");

        $i = 1;
        foreach ($selectedCells as $cell) {
            $ticketId = $batchId . '-' . $i++;
            try {
                $insertStmt->execute([$eventId, $ticketId, $batchId, $buyerName, $buyerContact, $cell, $adminName]);
                $assignedList[] = $cell;
            } catch (PDOException $e) {
                $hasCollision = true;
                break;
            }
        }

        if ($hasCollision) {
            $db->rollBack();
            $errorMessage = "⚠️ Square $cell was just taken by another seller! Please pick a different square.";
        } else {
            $db->commit();
            $lastAssigned = [
                'cells' => $assignedList,
                'name' => $buyerName,
                'batch' => $batchId,
                'total' => $_POST['calculated_total'] ?? '0.00'
            ];
        }
    }
}

// 3. Load Current Grid State
$gridCols = (int)$event['grid_cols'];
$gridRows = (int)$event['grid_rows'];
$colLetters = array_slice(range('A', 'Z'), 0, $gridCols);

$allBookings = getEventBookings($db, $eventId);
$soldCells = array_column($allBookings, 'buyer_name', 'cell_reference');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($event['event_name']) ?> - iPad POS</title>
    <style>
        * { box-sizing: border-box; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1f3a0f; color: #fff; margin: 0; padding: 10px 10px 120px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .badge { background: #4a7c23; padding: 6px 14px; border-radius: 20px; font-weight: bold; font-size: 0.9em; }
        
        /* Grid */
        .grid-container { background: white; padding: 10px; border-radius: 12px; overflow-x: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .grid { display: grid; grid-template-columns: 24px repeat(<?= $gridCols ?>, minmax(36px, 1fr)); gap: 3px; }
        .header-cell { color: #666; font-size: 11px; font-weight: bold; display: flex; align-items: center; justify-content: center; }
        .cell {
            aspect-ratio: 1; border-radius: 4px; font-size: 11px; font-weight: bold;
            display: flex; align-items: center; justify-content: center; user-select: none;
            background: #7bc67b; border: 1px solid #5aa35a; color: #1e461e;
        }
        .cell.sold { background: #e0a37e; border-color: #ba7c57; color: #5a2e12; opacity: 0.6; pointer-events: none; }
        .cell.selected {
            background: #ffc107 !important; border: 2px solid #d39e00 !important; color: #000 !important;
            transform: scale(1.08); z-index: 2; box-shadow: 0 0 8px rgba(255, 193, 7, 0.8);
        }
        
        /* Quick pick bar */
        .quick-pick { display: flex; gap: 8px; margin: 12px 0; overflow-x: auto; align-items: center; }
        .quick-btn { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 8px 14px; border-radius: 20px; font-weight: bold; font-size: 0.85em; cursor: pointer; white-space: nowrap; }
        .quick-btn:active { background: #ffc107; color: #000; }

        /* Sticky Bottom Checkout Tray */
        .checkout-tray {
            position: fixed; bottom: 0; left: 0; right: 0; background: #ffffff; color: #222;
            padding: 12px 16px; box-shadow: 0 -4px 20px rgba(0,0,0,0.4); border-top-left-radius: 16px; border-top-right-radius: 16px; z-index: 100;
        }
        .tray-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .tray-total { font-size: 1.3em; font-weight: 900; color: #2d5016; }
        .inputs-row { display: flex; gap: 8px; }
        .inputs-row input { flex: 1; padding: 12px; border: 2px solid #ccc; border-radius: 8px; font-size: 1em; }
        .btn-confirm { background: #2d5016; color: white; border: none; padding: 12px 20px; border-radius: 8px; font-size: 1em; font-weight: bold; cursor: pointer; }
        .btn-confirm:disabled { background: #aaa; }

        .banner-success { background: #d4edda; color: #155724; border: 2px solid #28a745; padding: 12px; border-radius: 8px; margin-bottom: 10px; text-align: center; }
        .banner-error { background: #f8d7da; color: #721c24; border: 2px solid #dc3545; padding: 12px; border-radius: 8px; margin-bottom: 10px; text-align: center; }
    </style>
</head>
<body>

<div class="top-bar">
    <span class="badge">📱 <?= htmlspecialchars($adminName) ?></span>
    <span style="font-size:0.9em;">Tap squares below or use quick random:</span>
    <a href="admin.php?logout" style="color:#ffc107;text-decoration:none;font-size:0.85em;">Logout</a>
</div>

<?php if ($lastAssigned): ?>
    <div class="banner-success">
        🎉 <strong>SOLD <?= count($lastAssigned['cells']) ?> SQUARES ($<?= htmlspecialchars($lastAssigned['total']) ?>)!</strong><br>
        Cells: <strong><?= implode(', ', $lastAssigned['cells']) ?></strong> assigned to <strong><?= htmlspecialchars($lastAssigned['name']) ?></strong>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="banner-error"><?= htmlspecialchars($errorMessage) ?></div>
<?php endif; ?>

<!-- Quick Random Selectors -->
<div class="quick-pick">
    <span style="font-size:0.8em;opacity:0.8;">🎲 Quick Random:</span>
    <button type="button" class="quick-btn" onclick="addRandomSquares(1)">+1 Random</button>
    <button type="button" class="quick-btn" onclick="addRandomSquares(3)">+3 Random</button>
    <button type="button" class="quick-btn" onclick="addRandomSquares(5)">+5 Random</button>
    <button type="button" class="quick-btn" style="background:#dc3545;border:none;" onclick="clearSelected()">Clear</button>
</div>

<!-- Visual Paddock Grid -->
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
                $isSold = isset($soldCells[$ref]);
            ?>
                <div class="cell <?= $isSold ? 'sold' : 'available' ?>" 
                     data-cell="<?= $ref ?>" 
                     onclick="toggleCell(this)">
                    <?= $ref ?>
                </div>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>

<!-- Sticky Checkout Bar -->
<div class="checkout-tray">
    <form method="POST" id="posForm" autocomplete="off">
        <input type="hidden" name="action" value="field_assign">
        <input type="hidden" name="selected_cells" id="selectedCellsInput" value="">
        <input type="hidden" name="calculated_total" id="calculatedTotalInput" value="0.00">

        <div class="tray-top">
            <span id="selectedSummary" style="font-weight:bold;font-size:0.95em;">0 squares selected</span>
            <span class="tray-total" id="totalDisplay">$0.00</span>
        </div>
        <div class="inputs-row">
            <input type="text" name="buyer_name" id="buyerName" placeholder="Buyer Name *" required>
            <input type="text" name="buyer_contact" placeholder="Phone/Email (Optional)">
            <button type="submit" class="btn-confirm" id="submitBtn" disabled>Collect Cash</button>
        </div>
    </form>
</div>

<script>
    const pricingTiers = <?= json_encode($pricingTiers) ?>; // e.g. {10: 70, 5: 40, 3: 25, 1: 10}
    let selected = new Set();

    function toggleCell(el) {
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

    // Bulk discount calculator: greedily applies largest package first
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
        document.getElementById('selectedSummary').textContent = `${qty} square${qty === 1 ? '' : 's'} (${Array.from(selected).join(', ') || 'None'})`;
        document.getElementById('submitBtn').disabled = (qty === 0);
    }
</script>
</body>
</html>