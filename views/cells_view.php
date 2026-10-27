<?php
// views/cells_view.php

if (!$viewEvent) {
    echo "<p style='padding:20px; background:#fff; border-radius:8px;'>No event selected. Please create or select an event first.</p>";
    return;
}

$evId = (int)$viewEvent['id'];
$canManage = $isSuperAdmin || canManageEvent($db, $currentAdminId, $evId);
$gridCols = max(2, (int)($viewEvent['grid_cols'] ?? 16));
$gridRows = max(2, (int)($viewEvent['grid_rows'] ?? 16));
$totalSquares = $gridCols * $gridRows;
$cellNaming = $viewEvent['cell_naming'] ?? 'alpha';
$pricingTiers = !empty($viewEvent['pricing_tiers']) ? (json_decode($viewEvent['pricing_tiers'], true) ?: []) : [];

$showGrid = !isset($renderSection) || $renderSection === 'grid_and_prizes';
$showTable = !isset($renderSection) || $renderSection === 'table_only';

// Index sold cells by uppercase cell reference
$soldMap = [];
$soldCount = 0;
$paidCount = 0;
$unpaidCount = 0;
$totalCollected = 0.0;
$unpaidExpected = 0.0;

foreach ($cells as $c) {
    $ref = strtoupper($c['cell_reference']);
    $soldMap[$ref] = $c;
    $soldCount++;
    if (!empty($c['is_paid'])) {
        $paidCount++;
        $totalCollected += (float)($c['price_paid'] ?? 0);
    } else {
        $unpaidCount++;
        $unpaidExpected += (float)($c['order_amount'] ?? 0);
    }
}

$availableSquares = max(0, $totalSquares - $soldCount);

// Generate Column Headers (A, B, C...)
$colLabels = [];
for ($c = 1; $c <= $gridCols; $c++) {
    $letters = '';
    $tempC = $c;
    while ($tempC > 0) {
        $rem = ($tempC - 1) % 26;
        $letters = chr(65 + $rem) . $letters;
        $tempC = (int)(($tempC - $rem - 1) / 26);
    }
    $colLabels[] = $letters;
}

// Status filter for Table Section (All / Paid / Unpaid)
$filterStatus = $_GET['filter_paid'] ?? 'all';
$displayCells = array_filter($cells, function($c) use ($filterStatus) {
    if ($filterStatus === 'paid') return !empty($c['is_paid']);
    if ($filterStatus === 'unpaid') return empty($c['is_paid']);
    return true;
});
?>

<?php if ($showGrid): ?>
<!-- ===================================================================== -->
<!-- SECTION 1: INTERACTIVE ASSIGNMENT GRID & PRIZES                      -->
<!-- ===================================================================== -->
<div class="cells-grid-section" style="margin-bottom:30px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0; font-size:1.25em; color:#2d5016;">🎲 Interactive Paddock Grid &bull; <?= htmlspecialchars($viewEvent['event_name']) ?></h3>
            <p style="margin:2px 0 0; font-size:0.85em; color:#64748b;">
                <?= $soldCount ?> sold &bull; <?= $availableSquares ?> available of <?= $totalSquares ?> squares
            </p>
        </div>

        <!-- Quick Award Prize by Ref Form -->
        <?php if ($canManage): ?>
        <form method="POST" action="admin.php?tab=cells&view=<?= $evId ?>" style="display:inline-flex; align-items:center; gap:6px; margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="assign_prize_by_ref">
            <input type="hidden" name="event_db_id" value="<?= $evId ?>">
            <input type="text" name="cell_reference" placeholder="Square (e.g. <?= $cellNaming === 'numeric' ? '16' : 'D4' ?>)" required style="width:115px; padding:6px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.85em;">
            <input type="text" name="prize" placeholder="Prize (e.g. 1st Place)" required style="width:150px; padding:6px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.85em;">
            <button type="submit" class="primary" style="padding:6px 12px; font-size:0.85em;">🏆 Award Prize</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Manual Assignment Control Box -->
    <?php if ($canManage): ?>
    <div style="background:#fff; border-radius:10px; padding:18px 22px; box-shadow:0 1px 4px rgba(0,0,0,0.06); margin-bottom:18px; border:1.5px solid #e2e8f0;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
            <strong style="color:#2d5016; font-size:0.95em;">✍️ Manual Square Assignment &bull; Click available squares below or use Quick Random:</strong>
            <div style="display:inline-flex; gap:6px; align-items:center;">
                <button type="button" onclick="adminAddRandom(1)" class="btn btn-sm" style="font-size:0.8em; padding:4px 8px;">+1 Random</button>
                <button type="button" onclick="adminAddRandom(3)" class="btn btn-sm" style="font-size:0.8em; padding:4px 8px;">+3 Random</button>
                <button type="button" onclick="adminAddRandom(5)" class="btn btn-sm" style="font-size:0.8em; padding:4px 8px;">+5 Random</button>
                <button type="button" onclick="adminClearSelection()" class="btn btn-sm danger" style="font-size:0.8em; padding:4px 8px;">✕ Clear</button>
            </div>
        </div>

        <form method="POST" action="admin.php?tab=cells&view=<?= $evId ?>" id="manualAssignForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="event_db_id" value="<?= $evId ?>">
            <input type="hidden" name="selected_cells" id="admin_selected_cells_input" value="">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:flex-end;">
                <div>
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#475569; margin-bottom:4px;">Buyer Name *</label>
                    <input type="text" name="buyer_name" id="assign_buyer_name" required placeholder="Full Name" style="width:100%; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.9em;">
                </div>
                <div>
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#475569; margin-bottom:4px;">Buyer Email / Phone</label>
                    <input type="text" name="buyer_email" placeholder="Optional contact" style="width:100%; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.9em;">
                </div>
                <div>
                    <label style="display:block; font-size:0.82em; font-weight:bold; color:#475569; margin-bottom:4px;">Ticket / Receipt Ref</label>
                    <input type="text" name="ticket_id" placeholder="Auto-generated if blank" style="width:100%; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.9em;">
                </div>
                <div style="display:flex; align-items:center; padding-bottom:8px;">
                    <label style="font-size:0.85em; font-weight:bold; color:#334155; cursor:pointer;">
                        <input type="checkbox" name="is_paid" value="1" checked style="margin-right:6px;">
                        Mark as Paid (Cash)
                    </label>
                </div>
                <div>
                    <button type="submit" class="primary" id="assignSubmitBtn" disabled style="width:100%; padding:9px; font-size:0.9em; opacity:0.6;">
                        Assign Squares (<span id="adminSelectedCount">0</span>)
                    </button>
                </div>
            </div>
            <div id="selectedSquaresPreview" style="margin-top:10px; font-size:0.85em; color:#2d5016; font-weight:bold; display:none;">
                Selected: <span id="selectedSquaresList" style="color:#0f172a;"></span>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Interactive Admin Grid (With Column & Row Headers) -->
    <div style="background:#fff; border-radius:10px; padding:18px; box-shadow:0 1px 4px rgba(0,0,0,0.06); overflow-x:auto;">
        <div style="display:grid; grid-template-columns:30px repeat(<?= $gridCols ?>, minmax(36px, 1fr)); gap:3px;">
            <!-- Top Header Corner -->
            <div style="height:24px;"></div>
            <!-- Column Letters A-Z -->
            <?php foreach ($colLabels as $cl): ?>
                <div style="height:24px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; color:#64748b;">
                    <?= htmlspecialchars($cl) ?>
                </div>
            <?php endforeach; ?>

            <!-- Rows with Row Numbers -->
            <?php for ($r = 1; $r <= $gridRows; $r++): ?>
                <div style="display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:bold; color:#64748b;">
                    <?= $r ?>
                </div>
                <?php for ($c = 1; $c <= $gridCols; $c++): 
                    $canonical = $colLabels[$c - 1] . $r;
                    $isTaken = isset($soldMap[$canonical]);
                    $cellData = $isTaken ? $soldMap[$canonical] : null;
                    $hasPrize = $isTaken && !empty($cellData['prize']);
                    $isPaidSquare = $isTaken && !empty($cellData['is_paid']);
                    $displayRef = function_exists('formatCellRef') ? formatCellRef($canonical, $gridCols, $cellNaming) : $canonical;
                    
                    $cellClasses = 'admin-grid-cell';
                    $bgColor = '#7bc67b';
                    $textColor = '#2c5e2c';
                    $border = '1px solid #5aa35a';
                    $cursor = 'pointer';

                    if ($hasPrize) {
                        $bgColor = '#fef08a';
                        $textColor = '#854d0e';
                        $border = '2px solid #eab308';
                        $cursor = 'default';
                    } elseif ($isTaken) {
                        $bgColor = '#e8a87c';
                        $textColor = '#6b3a1a';
                        $border = '1px solid #d4845a';
                        $cursor = 'default';
                        if (!$isPaidSquare) {
                            $border = '2px dashed #dc3545';
                        }
                    }
                ?>
                    <div 
                        id="cell_node_<?= htmlspecialchars($canonical) ?>"
                        class="<?= $cellClasses ?>"
                        data-ref="<?= htmlspecialchars($canonical) ?>"
                        data-display="<?= htmlspecialchars($displayRef) ?>"
                        data-taken="<?= $isTaken ? '1' : '0' ?>"
                        onclick="adminToggleCell('<?= htmlspecialchars($canonical) ?>')"
                        title="<?= $isTaken ? htmlspecialchars($cellData['buyer_name'] . ($hasPrize ? ' - Prize: ' . $cellData['prize'] : '')) : 'Available: Click to select' ?>"
                        style="aspect-ratio:1; min-width:36px; background:<?= $bgColor ?>; color:<?= $textColor ?>; border:<?= $border ?>; border-radius:4px; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:11px; cursor:<?= $cursor ?>; user-select:none; transition:transform 0.1s;">
                        <?= htmlspecialchars($displayRef) ?><?= $hasPrize ? ' 🏆' : '' ?>
                    </div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
    </div>
</div>

<script>
let adminSelectedCells = new Set();
const allAvailableRefs = <?= json_encode(array_values(array_filter(array_map(function($r) use ($gridCols, $colLabels) {
    $cellsInRow = [];
    for ($c = 1; $c <= $gridCols; $c++) {
        $cellsInRow[] = $colLabels[$c - 1] . $r;
    }
    return $cellsInRow;
}, range(1, $gridRows)), function($rowCells) use ($soldMap) {
    return true;
}))) ?>.flat().filter(ref => !<?= json_encode(array_fill_keys(array_keys($soldMap), true)) ?>[ref]);

function adminFormatDisplay(ref) {
    if ('<?= $cellNaming ?>' !== 'numeric') return ref;
    const m = ref.match(/^([A-Z]+)(\d+)$/i);
    if (!m) return ref;
    let col = 0;
    for (let i = 0; i < m[1].length; i++) {
        col = col * 26 + (m[1].charCodeAt(i) - 64);
    }
    return ((parseInt(m[2], 10) - 1) * <?= $gridCols ?>) + col;
}

function adminToggleCell(ref) {
    const node = document.getElementById('cell_node_' + ref);
    if (!node || node.dataset.taken === '1') return;

    if (adminSelectedCells.has(ref)) {
        adminSelectedCells.delete(ref);
        node.style.background = '#7bc67b';
        node.style.color = '#2c5e2c';
        node.style.border = '1px solid #5aa35a';
        node.style.transform = 'scale(1)';
    } else {
        adminSelectedCells.add(ref);
        node.style.background = '#fde047';
        node.style.color = '#713f12';
        node.style.border = '2px solid #ca8a04';
        node.style.transform = 'scale(1.12)';
    }
    adminUpdateSelectionUI();
}

function adminAddRandom(count) {
    const unselected = allAvailableRefs.filter(ref => !adminSelectedCells.has(ref));
    for (let i = 0; i < count && unselected.length > 0; i++) {
        const randIdx = Math.floor(Math.random() * unselected.length);
        const chosen = unselected.splice(randIdx, 1)[0];
        adminToggleCell(chosen);
    }
}

function adminClearSelection() {
    adminSelectedCells.forEach(ref => {
        const node = document.getElementById('cell_node_' + ref);
        if (node) {
            node.style.background = '#7bc67b';
            node.style.color = '#2c5e2c';
            node.style.border = '1px solid #5aa35a';
            node.style.transform = 'scale(1)';
        }
    });
    adminSelectedCells.clear();
    adminUpdateSelectionUI();
}

function adminUpdateSelectionUI() {
    const count = adminSelectedCells.size;
    const submitBtn = document.getElementById('assignSubmitBtn');
    const countSpan = document.getElementById('adminSelectedCount');
    const input = document.getElementById('admin_selected_cells_input');
    const previewBox = document.getElementById('selectedSquaresPreview');
    const previewList = document.getElementById('selectedSquaresList');

    if (countSpan) countSpan.textContent = count;
    if (input) input.value = Array.from(adminSelectedCells).join(',');

    if (submitBtn) {
        if (count > 0) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        } else {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.6';
            submitBtn.style.cursor = 'not-allowed';
        }
    }

    if (previewBox && previewList) {
        if (count > 0) {
            previewBox.style.display = 'block';
            previewList.textContent = Array.from(adminSelectedCells).map(adminFormatDisplay).join(', ');
        } else {
            previewBox.style.display = 'none';
            previewList.textContent = '';
        }
    }
}
</script>
<?php endif; ?>

<?php if ($showTable): ?>
<!-- ===================================================================== -->
<!-- SECTION 2: BOOKINGS, CASH RECONCILIATION & UNPAID MANAGEMENT         -->
<!-- ===================================================================== -->
<div class="bookings-table-section">
    <!-- KPI Summary Row -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
        <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 4px rgba(0,0,0,0.06); border-left:4px solid #16a34a;">
            <span style="font-size:0.8em; color:#64748b; font-weight:bold; text-transform:uppercase;">Total Paid Revenue</span>
            <h3 style="margin:4px 0 0; font-size:1.6em; color:#16a34a;">$<?= number_format($totalCollected, 2) ?></h3>
            <small style="color:#64748b;"><?= $paidCount ?> squares collected</small>
        </div>
        <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 4px rgba(0,0,0,0.06); border-left:4px solid #dc2626;">
            <span style="font-size:0.8em; color:#64748b; font-weight:bold; text-transform:uppercase;">Awaiting Payment</span>
            <h3 style="margin:4px 0 0; font-size:1.6em; color:#dc2626;">$<?= number_format($unpaidExpected, 2) ?></h3>
            <small style="color:#64748b;"><?= $unpaidCount ?> squares unpaid</small>
        </div>
        <div style="background:#fff; border-radius:8px; padding:16px 20px; box-shadow:0 1px 4px rgba(0,0,0,0.06); border-left:4px solid #2d5016;">
            <span style="font-size:0.8em; color:#64748b; font-weight:bold; text-transform:uppercase;">Squares Sold</span>
            <h3 style="margin:4px 0 0; font-size:1.6em; color:#2d5016;"><?= $soldCount ?> <span style="font-size:0.6em; color:#64748b;">/ <?= $totalSquares ?></span></h3>
            <small style="color:#64748b;"><?= $availableSquares ?> available in paddock</small>
        </div>
    </div>

    <!-- Controls Bar: Filter Pills, Sort By, and Bulk Actions -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px; background:#fff; padding:12px 18px; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
        <!-- Filter Pills: All / Paid / Unpaid -->
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span style="font-weight:bold; font-size:0.85em; color:#475569;">Filter:</span>
            <a href="admin.php?tab=bookings&view=<?= $evId ?>&filter_paid=all&sort=<?= urlencode($sortBy) ?>" style="padding:4px 12px; border-radius:14px; font-size:0.8em; font-weight:bold; text-decoration:none; <?= $filterStatus === 'all' ? 'background:#2d5016; color:#fff;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;' ?>">
                All (<?= $soldCount ?>)
            </a>
            <a href="admin.php?tab=bookings&view=<?= $evId ?>&filter_paid=paid&sort=<?= urlencode($sortBy) ?>" style="padding:4px 12px; border-radius:14px; font-size:0.8em; font-weight:bold; text-decoration:none; <?= $filterStatus === 'paid' ? 'background:#16a34a; color:#fff;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;' ?>">
                💵 Paid (<?= $paidCount ?>)
            </a>
            <a href="admin.php?tab=bookings&view=<?= $evId ?>&filter_paid=unpaid&sort=<?= urlencode($sortBy) ?>" style="padding:4px 12px; border-radius:14px; font-size:0.8em; font-weight:bold; text-decoration:none; <?= $filterStatus === 'unpaid' ? 'background:#dc2626; color:#fff;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;' ?>">
                ⚠️ Unpaid (<?= $unpaidCount ?>)
            </a>
        </div>

        <!-- Sort Options & Bulk Actions -->
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span style="font-weight:bold; font-size:0.85em; color:#475569;">Sort:</span>
            <a href="admin.php?tab=bookings&view=<?= $evId ?>&filter_paid=<?= urlencode($filterStatus) ?>&sort=days_unpaid" style="padding:4px 10px; border-radius:6px; font-size:0.8em; font-weight:bold; text-decoration:none; <?= $sortBy === 'days_unpaid' ? 'background:#fef3c7; color:#92400e; border:1px solid #fde047;' : 'background:#f8fafc; color:#475569; border:1px solid #cbd5e1;' ?>">
                ⏳ Days Unpaid
            </a>
            <a href="admin.php?tab=bookings&view=<?= $evId ?>&filter_paid=<?= urlencode($filterStatus) ?>&sort=cell" style="padding:4px 10px; border-radius:6px; font-size:0.8em; font-weight:bold; text-decoration:none; <?= $sortBy === 'cell' ? 'background:#f1f5f9; color:#1e293b; border:1px solid #94a3b8;' : 'background:#f8fafc; color:#475569; border:1px solid #cbd5e1;' ?>">
                🔢 Square
            </a>
            <a href="admin.php?tab=bookings&view=<?= $evId ?>&filter_paid=<?= urlencode($filterStatus) ?>&sort=buyer" style="padding:4px 10px; border-radius:6px; font-size:0.8em; font-weight:bold; text-decoration:none; <?= $sortBy === 'buyer' ? 'background:#f1f5f9; color:#1e293b; border:1px solid #94a3b8;' : 'background:#f8fafc; color:#475569; border:1px solid #cbd5e1;' ?>">
                👤 Buyer
            </a>

            <!-- Download CSV -->
            <a href="admin.php?action=export_csv&event_db_id=<?= $evId ?>" class="btn btn-sm" style="background:#f0fdf4; border-color:#bbf7d0; color:#166534; font-size:0.8em; padding:5px 10px;">
                📥 Export CSV
            </a>

            <!-- Remove All Unpaid Squares Button -->
            <?php if ($canManage && $unpaidCount > 0): ?>
            <form method="POST" action="admin.php?tab=bookings&view=<?= $evId ?>" style="margin:0;" onsubmit="return confirm('⚠️ REMOVE ALL UNPAID SQUARES ⚠️\n\nAre you sure you want to release all <?= $unpaidCount ?> unpaid squares for this event?\n\nAll unpaid reservations will be removed and returned to the available paddock pool.\n\nThis action cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="delete_all_unpaid">
                <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                <button type="submit" class="danger btn-sm" style="font-size:0.8em; padding:5px 10px;">
                    🗑️ Remove All Unpaid (<?= $unpaidCount ?>)
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bookings Ledger Table -->
    <?php if (empty($displayCells)): ?>
        <div style="background:#fff; border-radius:8px; padding:36px; text-align:center; color:#64748b;">
            <p style="font-size:1.05em; margin:0;">No square reservations match the selected filter (<strong><?= htmlspecialchars(ucfirst($filterStatus)) ?></strong>).</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto; background:#fff; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
            <table>
                <thead>
                    <tr>
                        <th>Square</th>
                        <th>Buyer Name</th>
                        <th>Contact</th>
                        <th>Ticket / Order Ref</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Prize</th>
                        <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayCells as $cell): 
                        $isPaid = !empty($cell['is_paid']);
                        $canonical = $cell['cell_reference'];
                        $dispSquare = function_exists('formatCellRef') ? formatCellRef($canonical, $gridCols, $cellNaming) : $canonical;
                        $orderId = $cell['order_id'] ?? '';
                        $isExternal = !empty($orderId) && strpos($orderId, 'MANUAL-') !== 0 && strpos($orderId, 'CASH-') !== 0;
                        
                        $createdTime = !empty($cell['created_at']) ? strtotime($cell['created_at']) : 0;
                        $daysOld = $createdTime > 0 ? (int)floor((time() - $createdTime) / 86400) : 0;
                    ?>
                    <tr style="<?= !$isPaid ? 'background:#fffbf7;' : '' ?>">
                        <td style="font-weight:bold; font-size:1.05em; color:#0f172a;">
                            <?= htmlspecialchars($dispSquare) ?>
                        </td>
                        <td style="font-weight:600; color:#1e293b;">
                            <?= htmlspecialchars($cell['buyer_name']) ?>
                        </td>
                        <td style="color:#64748b; font-size:0.88em;">
                            <?= htmlspecialchars($cell['buyer_email'] ?? '-') ?>
                        </td>
                        <td style="font-family:monospace; font-size:0.85em; color:#475569;">
                            <?= htmlspecialchars($cell['ticket_id']) ?><br>
                            <small style="color:#94a3b8;"><?= htmlspecialchars($orderId) ?></small>
                        </td>
                        <td>
                            <?php if ($isPaid): ?>
                                <span style="display:inline-block; font-size:0.8em; font-weight:bold; padding:2px 8px; border-radius:10px; background:#dcfce7; color:#15803d;">
                                    ✓ Paid
                                </span>
                            <?php else: ?>
                                <span style="display:inline-block; font-size:0.8em; font-weight:bold; padding:2px 8px; border-radius:10px; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;">
                                    ⚠️ Unpaid <?= $daysOld > 0 ? '(' . $daysOld . 'd old)' : '(today)' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:bold; color:<?= $isPaid ? '#059669' : '#d97706' ?>;">
                            $<?= number_format((float)($isPaid ? ($cell['price_paid'] ?? 0) : ($cell['order_amount'] ?? 0)), 2) ?>
                        </td>
                        <td>
                            <?php if (!empty($cell['prize'])): ?>
                                <div style="display:inline-flex; align-items:center; gap:4px;">
                                    <span style="font-weight:bold; color:#854d0e; background:#fef9c3; padding:2px 6px; border-radius:4px; font-size:0.85em;">
                                        🏆 <?= htmlspecialchars($cell['prize']) ?>
                                    </span>
                                    <?php if ($canManage): ?>
                                    <form method="POST" action="admin.php?tab=bookings&view=<?= $evId ?>" style="margin:0;" onsubmit="return confirm('Remove prize from square <?= htmlspecialchars(addslashes($dispSquare)) ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="assign_prize">
                                        <input type="hidden" name="cell_id" value="<?= (int)$cell['id'] ?>">
                                        <input type="hidden" name="prize" value="">
                                        <button type="submit" title="Remove Prize" style="background:none; border:none; color:#dc2626; font-size:0.85em; padding:0 2px; cursor:pointer;">🏆✖</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                        <td style="white-space:nowrap;">
                            <div style="display:inline-flex; gap:4px; align-items:center;">
                                <?php if (!$isPaid): ?>
                                <form method="POST" action="admin.php?tab=bookings&view=<?= $evId ?>" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="cell_id" value="<?= (int)$cell['id'] ?>">
                                    <button type="submit" class="primary btn-sm" style="font-size:0.75em; padding:3px 8px;">💵 Mark Paid</button>
                                </form>

                                <?php if ($isExternal): ?>
                                <form method="POST" action="admin.php?tab=bookings&view=<?= $evId ?>" style="margin:0;" onsubmit="return confirm('Cancel reservation for <?= htmlspecialchars(addslashes($cell['buyer_name'])) ?> (Order #<?= htmlspecialchars(addslashes($orderId)) ?>)?\n\nThis will notify the buyer via Hi.Events message and release the square.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="cancel_and_notify">
                                    <input type="hidden" name="cell_id" value="<?= (int)$cell['id'] ?>">
                                    <button type="submit" class="btn-sm warning" style="font-size:0.75em; padding:3px 8px;">🚫 Cancel &amp; Notify</button>
                                </form>
                                <?php endif; ?>
                                <?php endif; ?>
                                
                                <form method="POST" action="admin.php?tab=bookings&view=<?= $evId ?>" style="margin:0;" onsubmit="return confirm('Remove square <?= htmlspecialchars(addslashes($dispSquare)) ?> assigned to <?= htmlspecialchars(addslashes($cell['buyer_name'])) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_cell">
                                    <input type="hidden" name="cell_id" value="<?= (int)$cell['id'] ?>">
                                    <button type="submit" class="btn-sm" style="font-size:0.75em; padding:3px 8px; color:#dc2626;">✕ Remove</button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- Post-Event Privacy Cleanup Panel (NZ Privacy Act) -->
    <?php 
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_cells WHERE event_id = ? AND buyer_email IS NOT NULL AND buyer_email != ''");
    $cntStmt->execute([$evId]);
    $contactsCount = (int)$cntStmt->fetchColumn();
    ?>
    <?php if ($canManage && $contactsCount > 0): ?>
    <div style="margin-top:20px; padding:15px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <strong style="color:#9a3412;">🔒 Post-Event Privacy Cleanup (NZ Privacy Act)</strong>
            <p style="margin:4px 0 0; color:#666; font-size:0.85em;">
                Wipe customer emails &amp; phone numbers while retaining buyer names, square coordinates, and audit totals.
            </p>
        </div>
        <form method="POST" action="admin.php?tab=bookings&view=<?= $evId ?>" onsubmit="return confirm('Permanently erase contact details for this event?\n\nExport the CSV first if you need the data!');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="purge_contacts">
            <input type="hidden" name="event_db_id" value="<?= $evId ?>">
            <button type="submit" class="danger" style="padding:8px 14px; font-size:0.88em;">
                🧹 Purge Contacts (<?= $contactsCount ?> on file)
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
