<?php
// views/cells_view.php
if (!$viewEvent) {
    echo "<p style='color:#777;padding:20px;text-align:center;'>No event selected. Please create or select an event first.</p>";
    return;
}

$gridCols = (int) $viewEvent['grid_cols'];
$gridRows = (int) $viewEvent['grid_rows'];
$cellNaming = $viewEvent['cell_naming'] ?? 'alpha';
$colLetters = array_slice(range('A', 'Z'), 0, $gridCols);
$soldCells = array_column($cells, 'buyer_name', 'cell_reference');

$showGrid = !isset($renderSection) || $renderSection === 'grid_and_prizes';
$showTable = !isset($renderSection) || $renderSection === 'table_only';
$csrfToken = getCsrfToken();
$sortBy = $_GET['sort'] ?? 'cell';
?>

<?php if ($showGrid): ?>
<!-- Assign Prize Form -->
<form method="POST" action="?tab=cells&view=<?= $viewEventId ?>" style="margin-bottom:15px; padding:15px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="assign_prize_by_ref">
    <input type="hidden" name="tab" value="cells">
    <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
    <strong>🏆 Assign Prize:</strong>
    <input type="text" name="cell_reference" required placeholder="<?= $cellNaming === 'numeric' ? 'Square # (e.g. 16 or D2)' : 'Cell (e.g. D2 or 16)' ?>" style="padding:6px;width:140px;text-transform:uppercase;">
    <input type="text" name="prize" required placeholder="Prize (e.g. 1st Prize, Cake)" style="padding:6px;width:250px;">
    <button type="submit" class="primary">Assign Prize</button>
</form>

<!-- Multi-Cell Visual Manual Assignment -->
<div style="margin-bottom:20px; background:white; border-radius:8px; border:1px solid #ddd; padding:15px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
    <h3 style="margin-top:0; color:#2d5016;">🎲 Manual Cell Assignment (Interactive Grid &amp; Multi-Select)</h3>

    <form method="POST" action="?tab=cells&view=<?= $viewEventId ?>" id="adminManualAssignForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="tab" value="cells">
        <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
        <input type="hidden" name="selected_cells" id="adminSelectedCellsInput" value="">

        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px;">
            <input type="text" name="buyer_name" placeholder="Buyer Name *" required style="padding:8px 12px; border:1px solid #ccc; border-radius:4px; width:180px;">
            <input type="email" name="buyer_email" placeholder="Email (optional)" style="padding:8px 12px; border:1px solid #ccc; border-radius:4px; width:180px;">
            <input type="text" name="ticket_id" placeholder="Ticket / Ref # (optional)" style="padding:8px 12px; border:1px solid #ccc; border-radius:4px; width:170px;">
            
            <label style="display:inline-flex; align-items:center; gap:5px; font-size:0.9em; margin-left:5px; width:auto; cursor:pointer;">
                <input type="checkbox" name="is_paid" value="1" checked style="width:auto;"> Marked as Paid
            </label>

            <div style="margin-left:auto; display:flex; gap:6px; align-items:center;">
                <span style="font-size:0.85em; color:#666; font-weight:bold;">Quick:</span>
                <button type="button" class="btn" style="background:#4a7c23; color:#ffffff; padding:5px 10px; font-size:0.8em; font-weight:bold; cursor:pointer;" onclick="adminAddRandom(1)">+1 Random</button>
                <button type="button" class="btn" style="background:#4a7c23; color:#ffffff; padding:5px 10px; font-size:0.8em; font-weight:bold; cursor:pointer;" onclick="adminAddRandom(3)">+3 Random</button>
                <button type="button" class="btn" style="background:#4a7c23; color:#ffffff; padding:5px 10px; font-size:0.8em; font-weight:bold; cursor:pointer;" onclick="adminAddRandom(5)">+5 Random</button>
                <button type="button" class="btn" style="background:#6c757d; color:#ffffff; padding:5px 10px; font-size:0.8em; font-weight:bold; cursor:pointer;" onclick="adminClearSelection()">Clear</button>
            </div>
        </div>

        <div style="background:#f8f9fa; border:1px solid #e2e8f0; border-radius:6px; padding:10px; max-height:300px; overflow:auto; margin-bottom:12px;">
            <div style="display:grid; grid-template-columns: 24px repeat(<?= $gridCols ?>, minmax(28px, 1fr)); gap:2px; font-size:11px; text-align:center;">
                <div style="color:#888; font-weight:bold;"></div>
                <?php foreach ($colLetters as $col): ?>
                    <div style="color:#666; font-weight:bold; padding:2px 0;"><?= $col ?></div>
                <?php endforeach; ?>

                <?php for ($r = 1; $r <= $gridRows; $r++): ?>
                    <div style="color:#666; font-weight:bold; display:flex; align-items:center; justify-content:center;"><?= $r ?></div>
                    <?php foreach ($colLetters as $c): 
                        $ref = $c . $r;
                        $displayRef = formatCellRef($ref, $gridCols, $cellNaming);
                        $isSold = isset($soldCells[$ref]);
                    ?>
                        <div class="admin-grid-cell <?= $isSold ? 'sold' : 'available' ?>" 
                             data-cell="<?= $ref ?>" 
                             data-display="<?= $displayRef ?>"
                             title="<?= $isSold ? ('Sold to ' . htmlspecialchars($soldCells[$ref]) . ' [' . $displayRef . ']') : ('Available: ' . $displayRef . ($cellNaming === 'numeric' ? ' (' . $ref . ')' : '')) ?>"
                             onclick="adminToggleCell(this)">
                            <?= $displayRef ?>
                        </div>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; background:#edf7ed; border:1px solid #c8e6c9; padding:10px 14px; border-radius:6px;">
            <span id="adminSelectionSummary" style="font-size:0.95em; color:#1b5e20; font-weight:bold;">
                No cells selected (click squares above or tap Quick Random)
            </span>
            <button type="submit" class="primary" id="adminSubmitBtn" disabled style="padding:8px 20px;">
                Assign Selected Cells
            </button>
        </div>
    </form>
</div>

<style>
.admin-grid-cell {
    aspect-ratio: 1; border-radius: 3px; font-weight: bold;
    display: flex; align-items: center; justify-content: center;
    user-select: none; transition: transform 0.1s;
}
.admin-grid-cell.available {
    background: #7bc67b; border: 1px solid #5aa35a; color: #1e461e; cursor: pointer;
}
.admin-grid-cell.available:hover {
    background: #90d690; transform: scale(1.1); z-index: 5;
}
.admin-grid-cell.sold {
    background: #e0a37e; border: 1px solid #ba7c57; color: #5a2e12; opacity: 0.5; cursor: not-allowed;
}
.admin-grid-cell.selected {
    background: #ffc107 !important; border: 2px solid #b8860b !important; color: #000 !important;
    font-weight: 900; transform: scale(1.15); z-index: 6; box-shadow: 0 0 6px rgba(255,193,7,0.9);
}
</style>

<script>
let adminSelected = new Set();
const adminCellNaming = '<?= $cellNaming ?>';
const adminGridCols = <?= $gridCols ?>;

function adminToggleCell(el) {
    if (el.classList.contains('sold')) return;
    const ref = el.dataset.cell;
    if (adminSelected.has(ref)) {
        adminSelected.delete(ref);
        el.classList.remove('selected');
    } else {
        adminSelected.add(ref);
        el.classList.add('selected');
    }
    adminUpdateSelectionUI();
}

function adminAddRandom(count) {
    const available = Array.from(document.querySelectorAll('.admin-grid-cell.available:not(.selected)'));
    if (available.length === 0) return alert('No remaining cells available in this grid!');

    for (let i = 0; i < count; i++) {
        if (available.length === 0) break;
        const randIdx = Math.floor(Math.random() * available.length);
        const chosen = available.splice(randIdx, 1)[0];
        adminSelected.add(chosen.dataset.cell);
        chosen.classList.add('selected');
    }
    adminUpdateSelectionUI();
}

function adminClearSelection() {
    document.querySelectorAll('.admin-grid-cell.selected').forEach(c => c.classList.remove('selected'));
    adminSelected.clear();
    adminUpdateSelectionUI();
}

function adminFormatDisplay(ref) {
    if (adminCellNaming !== 'numeric') return ref;
    const m = ref.match(/^([A-Z])([0-9]+)$/);
    if (!m) return ref;
    const col = m[1].charCodeAt(0) - 64;
    const row = parseInt(m[2], 10);
    return ((row - 1) * adminGridCols) + col;
}

function adminUpdateSelectionUI() {
    const count = adminSelected.size;
    const input = document.getElementById('adminSelectedCellsInput');
    const summary = document.getElementById('adminSelectionSummary');
    const btn = document.getElementById('adminSubmitBtn');

    input.value = Array.from(adminSelected).join(',');

    if (count > 0) {
        const displayList = Array.from(adminSelected).map(adminFormatDisplay);
        summary.innerHTML = `Selected <strong>${count}</strong> square${count > 1 ? 's' : ''}: <span style="color:#2d5016;">${displayList.join(', ')}</span>`;
        btn.textContent = `Assign ${count} Square${count > 1 ? 's' : ''}`;
        btn.disabled = false;
    } else {
        summary.textContent = 'No cells selected (click squares above or tap Quick Random)';
        btn.textContent = 'Assign Selected Cells';
        btn.disabled = true;
    }
}
</script>
<?php endif; ?>

<?php if ($showTable): ?>
<!-- TREASURER CASH & ONLINE SALES RECONCILIATION -->
<?php
$pricingMap = [];
if (!empty($viewEvent['pricing_tiers'])) {
    $decoded = json_decode($viewEvent['pricing_tiers'], true);
    if (is_array($decoded)) $pricingMap = $decoded;
}
if (empty($pricingMap)) {
    $pricingMap = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
}

backfillMissingCellPrices($db, $viewEventId, $pricingMap);

$reconData = calculateEventCashReconciliation($db, $viewEventId, $pricingMap);
$summaryChannels = $reconData['summary'];
$buyerBatches = $reconData['buyer_batches'];
$totals = $reconData['totals'];

$batchCounts = [];
foreach ($cells as $c) {
    $bRef = $c['order_id'] ?: $c['ticket_id'];
    $batchCounts[$bRef] = ($batchCounts[$bRef] ?? 0) + 1;
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_cells WHERE event_id = ? AND buyer_email IS NOT NULL AND buyer_email != ''");
$cntStmt->execute([$viewEventId]);
$contactsCount = (int) $cntStmt->fetchColumn();
?>

<!-- KPI CARDS SUMMARY FOR TREASURER -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:20px;">
    <div style="background:#fff; border-radius:8px; border:1.5px solid #28a745; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8em; font-weight:bold; color:#155724; text-transform:uppercase;">💵 Physical Cash in Envelopes</div>
        <div style="font-size:1.6em; font-weight:900; color:#155724; margin:4px 0;">$<?= number_format($totals['cash_paid'], 2) ?></div>
        <div style="font-size:0.85em; color:#555;">
            Expected: <strong>$<?= number_format($totals['cash_expected'], 2) ?></strong> (<?= $totals['cash_squares'] ?> squares)
        </div>
    </div>

    <div style="background:#fff; border-radius:8px; border:1.5px solid #0284c7; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8em; font-weight:bold; color:#0369a1; text-transform:uppercase;">💳 Online hi.events / Card</div>
        <div style="font-size:1.6em; font-weight:900; color:#0369a1; margin:4px 0;">$<?= number_format($totals['online_paid'], 2) ?></div>
        <div style="font-size:0.85em; color:#555;">
            Expected: <strong>$<?= number_format($totals['online_expected'], 2) ?></strong> (<?= $totals['online_squares'] ?> squares)
        </div>
    </div>

    <div style="background:#fff; border-radius:8px; border:1.5px solid #2d5016; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8em; font-weight:bold; color:#2d5016; text-transform:uppercase;">🏆 Total Revenue Collected</div>
        <div style="font-size:1.6em; font-weight:900; color:#2d5016; margin:4px 0;">$<?= number_format($totals['total_paid'], 2) ?></div>
        <div style="font-size:0.85em; color:#555;">
            Total Expected: <strong>$<?= number_format($totals['total_expected'], 2) ?></strong> (<?= $totals['total_squares'] ?> squares)
        </div>
    </div>

    <div style="background:#fff; border-radius:8px; border:1.5px solid #d97706; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="font-size:0.8em; font-weight:bold; color:#b45309; text-transform:uppercase;">🏷️ Multi-Ticket Discounts</div>
        <div style="font-size:1.6em; font-weight:900; color:#b45309; margin:4px 0;">-$<?= number_format($totals['total_discount'], 2) ?></div>
        <div style="font-size:0.85em; color:#555;">
            Unpaid Pending: <strong>$<?= number_format($totals['unpaid_total'], 2) ?></strong>
        </div>
    </div>
</div>

<!-- CHANNEL RECONCILIATION TABLE -->
<div style="background:white; border-radius:8px; border:1px solid #d2d6dc; padding:18px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
        <div>
            <h3 style="margin:0 0 4px; color:#2d5016;">💰 Channel &amp; Device Cash Breakdown</h3>
            <span style="color:#666; font-size:0.9em;">Reconciliation per field iPad and online sales channel.</span>
        </div>
        <div>
            <a href="admin.php?action=export_csv&event_db_id=<?= $viewEventId ?>" class="btn" style="background:#15803d; font-size:0.9em; padding:8px 14px;">📥 Export Audit CSV for Treasurer</a>
        </div>
    </div>

    <table style="margin-top:5px; box-shadow:none; border:1px solid #eee;">
        <thead>
            <tr>
                <th>Seller / Channel</th>
                <th>Type</th>
                <th>Transactions</th>
                <th>Squares Sold</th>
                <th>Expected Total</th>
                <th>Actual Paid</th>
                <th>Discrepancy / Variance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($summaryChannels as $r): 
                $variance = $r['actual_paid'] - $r['expected_amount'];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['channel_label']) ?></strong></td>
                <td>
                    <?= $r['is_online'] 
                        ? '<span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:4px;font-weight:bold;font-size:0.8em;">Online</span>'
                        : '<span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:4px;font-weight:bold;font-size:0.8em;">Cash</span>' ?>
                </td>
                <td><?= $r['batch_count'] ?></td>
                <td><?= $r['total_squares'] ?></td>
                <td>$<?= number_format($r['expected_amount'], 2) ?></td>
                <td>
                    <strong style="color:<?= $r['is_online'] ? '#0369a1' : '#2d5016' ?>;">
                        $<?= number_format($r['actual_paid'], 2) ?>
                    </strong>
                    <?php if ($r['unpaid_amount'] > 0): ?>
                        <small style="color:#dc3545; display:block;">($<?= number_format($r['unpaid_amount'], 2) ?> unpaid)</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($variance == 0): ?>
                        <span style="color:#15803d; font-weight:bold;">Balanced (+$0.00)</span>
                    <?php elseif ($variance > 0): ?>
                        <span style="color:#2563eb; font-weight:bold;">+$<?= number_format($variance, 2) ?> (Over)</span>
                    <?php else: ?>
                        <span style="color:#dc3545; font-weight:bold;">-$<?= number_format(abs($variance), 2) ?> (Short/Pending)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc; font-weight:bold; border-top:2px solid #ccc;">
                <td>TOTALS</td>
                <td>-</td>
                <td><?= array_sum(array_column($summaryChannels, 'batch_count')) ?></td>
                <td><?= $totals['total_squares'] ?> squares</td>
                <td>$<?= number_format($totals['total_expected'], 2) ?></td>
                <td style="color:#2d5016; font-size:1.1em;">$<?= number_format($totals['total_paid'], 2) ?></td>
                <td>
                    <?php $totVariance = $totals['total_paid'] - $totals['total_expected']; ?>
                    <span style="color:<?= $totVariance >= 0 ? '#15803d' : '#dc3545' ?>; font-weight:bold;">
                        <?= $totVariance >= 0 ? '+' : '-' ?>$<?= number_format(abs($totVariance), 2) ?>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- DETAILED BOOKINGS TABLE -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
    <h3 style="margin:0;">📋 Detailed Square Bookings &amp; Pricing Breakdown</h3>
    <a href="admin.php?action=export_csv&event_db_id=<?= $viewEventId ?>" style="color:#2d5016; font-size:0.85em; font-weight:bold; text-decoration:none;">📥 Download as CSV &darr;</a>
</div>

<table>
    <thead>
        <tr>
            <th><a href="?tab=table&view=<?= $viewEventId ?>&sort=cell" style="color:white;text-decoration:none;"><?= $cellNaming === 'numeric' ? 'Square #' : 'Cell' ?> <?= $sortBy === 'cell' ? '▼' : '' ?></a></th>
            <th>Ticket ID</th>
            <th>Order / Batch</th>
            <th><a href="?tab=table&view=<?= $viewEventId ?>&sort=buyer" style="color:white;text-decoration:underline;">Buyer <?= $sortBy === 'buyer' ? '▼' : '' ?></a></th>
            <th>Contact</th>
            <th>Method</th>
            <th>Expected Total</th>
            <th>Actual Paid</th>
            <th><a href="?tab=table&view=<?= $viewEventId ?>&sort=status" style="color:white;text-decoration:none;">Status <?= $sortBy === 'status' ? '▼' : '' ?></a></th>
            <th>Seller</th>
            <th>Prize</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $seenBatchesInTable = [];
        foreach ($cells as $cell): 
            $bRef = $cell['order_id'] ?: $cell['ticket_id'];
            $batchQty = $batchCounts[$bRef] ?? 1;
            $isPaid = !empty($cell['is_paid']);
            $isFirstInBatch = !isset($seenBatchesInTable[$bRef]);
            $seenBatchesInTable[$bRef] = true;

            $expectedBatch = calculateBatchPrice($batchQty, $pricingMap);
            $actualPaidBatch = $isPaid 
                ? (($cell['order_amount'] !== null && (float)$cell['order_amount'] > 0) ? (float)$cell['order_amount'] : $expectedBatch) 
                : 0.00;
            $unitShare = round(($isPaid ? $actualPaidBatch : $expectedBatch) / max(1, $batchQty), 2);
            $cellDisplay = formatCellRef($cell['cell_reference'], $gridCols, $cellNaming);
        ?>
        <tr<?= $cell['prize'] ? ' style="background:#fff8e1;"' : '' ?>>
            <td>
                <strong><?= htmlspecialchars($cellDisplay) ?></strong>
                <?php if ($cellNaming === 'numeric'): ?>
                    <small style="color:#6b7280; display:block;">(<?= htmlspecialchars($cell['cell_reference']) ?>)</small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($cell['ticket_id']) ?></td>
            <td><code style="font-size:0.85em;"><?= htmlspecialchars($bRef) ?></code></td>
            <td><?= htmlspecialchars($cell['buyer_name']) ?></td>
            <td><?= htmlspecialchars($cell['buyer_email'] ?? '') ?></td>
            <td>
                <span style="font-size:0.85em; font-weight:bold; color:<?= ($cell['payment_method'] ?? '') === 'online' ? '#0284c7' : '#15803d' ?>;">
                    <?= ucfirst(htmlspecialchars($cell['payment_method'] ?? 'cash')) ?>
                </span>
            </td>
            <td>
                <?php if ($isFirstInBatch): ?>
                    <strong>$<?= number_format($expectedBatch, 2) ?></strong>
                    <?php if ($batchQty > 1): ?>
                        <small style="display:block;color:#666;">(Batch of <?= $batchQty ?>)</small>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:#9ca3af; font-size:0.85em;" title="Included in batch total above">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($isFirstInBatch): ?>
                    <strong style="color:<?= $isPaid ? '#15803d' : '#dc3545' ?>;">$<?= number_format($actualPaidBatch, 2) ?></strong>
                    <?php if ($batchQty > 1): ?>
                        <small style="display:block;color:#666;">($<?= number_format($unitShare, 2) ?>/sq)</small>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:#9ca3af; font-size:0.85em;" title="Included in batch total above">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?= $isPaid 
                    ? '<span style="background:#d4edda;color:#155724;padding:3px 8px;border-radius:4px;font-weight:bold;font-size:0.85em;">🟢 Paid</span>' 
                    : '<span style="background:#fff3cd;color:#856404;padding:3px 8px;border-radius:4px;font-weight:bold;font-size:0.85em;">🟡 Unpaid</span>' ?>
            </td>
            <td><span style="background:#e9ecef;padding:2px 7px;border-radius:4px;font-size:0.85em;font-weight:bold;"><?= htmlspecialchars($cell['assigned_by'] ?? 'hi.events') ?></span></td>
            <td><?= $cell['prize'] ? '🏆 ' . htmlspecialchars($cell['prize']) : '' ?></td>
            <td>
                <?php if (empty($cell['is_paid'])): ?>
                <form method="POST" action="?tab=table&view=<?= $viewEventId ?>&sort=<?= urlencode($sortBy) ?>" class="inline" style="margin-right:3px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="tab" value="table">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <button type="submit" style="background:#15803d;color:white;font-size:0.8em;padding:4px 8px;border-radius:4px;border:none;cursor:pointer;" title="Record cash received and mark square as Paid" onclick="return confirm('Mark payment received for <?= htmlspecialchars(addslashes($cell['buyer_name'])) ?>?')">💵 Mark Paid</button>
                </form>

                <form method="POST" action="?tab=table&view=<?= $viewEventId ?>&sort=<?= urlencode($sortBy) ?>" class="inline" style="margin-right:3px;" onsubmit="return confirm('Cancel in hi.events and message buyer?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="cancel_and_notify">
                    <input type="hidden" name="tab" value="table">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <button type="submit" style="background:#dc3545;color:white;font-size:0.8em;padding:4px 8px;border-radius:4px;border:none;cursor:pointer;" title="Cancel on hi.events">Cancel</button>
                </form>
                <?php endif; ?>

                <?php 
                $removeMsg = $isPaid 
                    ? "⚠️ CAUTION: " . htmlspecialchars(addslashes($cell['buyer_name'])) . " has ALREADY PAID for square " . $cellDisplay . " (Ticket: " . htmlspecialchars($cell['ticket_id']) . ")!\n\nAre you sure you want to permanently delete this paid booking and free up the square in the paddock?"
                    : "Remove unpaid assignment for " . htmlspecialchars(addslashes($cell['buyer_name'])) . " (" . $cellDisplay . ")?";
                ?>
                <form method="POST" action="?tab=table&view=<?= $viewEventId ?>&sort=<?= urlencode($sortBy) ?>" class="inline" style="margin-right:3px;" onsubmit="return confirm('<?= $removeMsg ?>')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_cell">
                    <input type="hidden" name="tab" value="table">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <button type="submit" class="danger">Remove</button>
                </form>

                <?php if ($cell['prize']): ?>
                <form method="POST" action="?tab=table&view=<?= $viewEventId ?>&sort=<?= urlencode($sortBy) ?>" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="assign_prize">
                    <input type="hidden" name="tab" value="table">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <input type="hidden" name="prize" value="">
                    <button type="submit" class="warning" title="Remove prize">🏆✖</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cells)): ?><tr><td colspan="12" style="text-align:center;color:#999;">No cells assigned yet for this game</td></tr><?php endif; ?>
    </tbody>
</table>

<!-- Post-Event Data Retention Cleanup (NZ Privacy Act) -->
<div style="margin-top:25px; padding:15px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
    <div>
        <strong style="color:#9a3412;">🔒 Post-Event Privacy Cleanup (NZ Privacy Act)</strong>
        <p style="margin:4px 0 0; color:#666; font-size:0.85em;">
            Once prizes have been paid out and cash is reconciled, wipe customer emails &amp; phone numbers from this event to adhere to data retention rules.<br>
            <em style="color:#888;">(Buyer names, square coordinates, and financial audit totals are retained for historical records).</em>
        </p>
    </div>
    <div>
        <?php if ($contactsCount > 0): ?>
        <form method="POST" action="?tab=table&view=<?= $viewEventId ?>&sort=<?= urlencode($sortBy) ?>" onsubmit="return confirm('Are you sure you want to permanently erase buyer emails and phone numbers for this event?\n\nMake sure your treasurer has exported the CSV first!')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="purge_contacts">
            <input type="hidden" name="tab" value="table">
            <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
            <button type="submit" class="danger" style="padding:8px 14px; font-size:0.88em; cursor:pointer;">🧹 Purge Contacts (<?= $contactsCount ?> on file)</button>
        </form>
        <?php else: ?>
        <span style="display:inline-block; background:#dcfce7; color:#166534; padding:6px 12px; border-radius:6px; font-size:0.85em; font-weight:bold;">
            ✅ Contacts already purged
        </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
