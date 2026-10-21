<?php
// views/cells_view.php
if (!$viewEvent) return;

$gridCols = (int) $viewEvent['grid_cols'];
$gridRows = (int) $viewEvent['grid_rows'];
$colLetters = array_slice(range('A', 'Z'), 0, $gridCols);

// Index sold cells for quick lookup
$soldCells = array_column($cells, 'buyer_name', 'cell_reference');
?>
<h2>Cells for: <?= htmlspecialchars($viewEvent['event_name']) ?> (<?= count($cells) ?> sold)</h2>

<!-- Assign Prize Form -->
<form method="POST" action="?view=<?= $viewEventId ?>" style="margin-bottom:15px; padding:15px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px;">
    <input type="hidden" name="action" value="assign_prize_by_ref">
    <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
    <strong>🏆 Assign Prize:</strong>
    <input type="text" name="cell_reference" required placeholder="Cell (e.g. C13)" style="padding:6px;width:100px;text-transform:uppercase;">
    <input type="text" name="prize" required placeholder="Prize (e.g. 1st Prize, Cake)" style="padding:6px;width:250px;">
    <button type="submit" class="primary">Assign Prize</button>
</form>

<!-- Multi-Cell Visual Manual Assignment -->
<details open style="margin-bottom:20px; background:white; border-radius:8px; border:1px solid #ddd; padding:15px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
    <summary style="cursor:pointer; font-weight:bold; font-size:1.05em; color:#2d5016; margin-bottom:12px;">
        🎲 Manual Cell Assignment (Interactive Grid &amp; Multi-Select)
    </summary>

    <form method="POST" action="?view=<?= $viewEventId ?>" id="adminManualAssignForm" autocomplete="off">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
        <input type="hidden" name="selected_cells" id="adminSelectedCellsInput" value="">

        <!-- Top Inputs & Quick Random Bar -->
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px;">
            <input type="text" name="buyer_name" placeholder="Buyer Name *" required style="padding:8px 12px; border:1px solid #ccc; border-radius:4px; width:180px;">
            <input type="email" name="buyer_email" placeholder="Email (optional)" style="padding:8px 12px; border:1px solid #ccc; border-radius:4px; width:180px;">
            <input type="text" name="ticket_id" placeholder="Ticket / Ref # (optional)" style="padding:8px 12px; border:1px solid #ccc; border-radius:4px; width:170px;">
            
            <label style="display:inline-flex; align-items:center; gap:5px; font-size:0.9em; margin-left:5px; width:auto; cursor:pointer;">
                <input type="checkbox" name="is_paid" value="1" checked style="width:auto;"> Marked as Paid
            </label>

            <!-- Quick Random Buttons -->
            <div style="margin-left:auto; display:flex; gap:6px; align-items:center;">
                <span style="font-size:0.85em; color:#666; font-weight:bold;">Quick:</span>
                <button type="button" class="btn" style="background:#4a7c23; padding:5px 10px; font-size:0.8em;" onclick="adminAddRandom(1)">+1 Random</button>
                <button type="button" class="btn" style="background:#4a7c23; padding:5px 10px; font-size:0.8em;" onclick="adminAddRandom(3)">+3 Random</button>
                <button type="button" class="btn" style="background:#4a7c23; padding:5px 10px; font-size:0.8em;" onclick="adminAddRandom(5)">+5 Random</button>
                <button type="button" class="btn" style="background:#6c757d; padding:5px 10px; font-size:0.8em;" onclick="adminClearSelection()">Clear</button>
            </div>
        </div>

        <!-- Interactive Visual Paddock Grid -->
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
                        $isSold = isset($soldCells[$ref]);
                    ?>
                        <div class="admin-grid-cell <?= $isSold ? 'sold' : 'available' ?>" 
                             data-cell="<?= $ref ?>" 
                             title="<?= $isSold ? ('Sold to ' . htmlspecialchars($soldCells[$ref])) : ('Available: ' . $ref) ?>"
                             onclick="adminToggleCell(this)">
                            <?= $ref ?>
                        </div>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Action / Selection Summary Bar -->
        <div style="display:flex; justify-content:space-between; align-items:center; background:#edf7ed; border:1px solid #c8e6c9; padding:10px 14px; border-radius:6px;">
            <span id="adminSelectionSummary" style="font-size:0.95em; color:#1b5e20; font-weight:bold;">
                No cells selected (click squares above or tap Quick Random)
            </span>
            <button type="submit" class="primary" id="adminSubmitBtn" disabled style="padding:8px 20px;">
                Assign Selected Cells
            </button>
        </div>
    </form>
</details>

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

function adminUpdateSelectionUI() {
    const count = adminSelected.size;
    const input = document.getElementById('adminSelectedCellsInput');
    const summary = document.getElementById('adminSelectionSummary');
    const btn = document.getElementById('adminSubmitBtn');

    input.value = Array.from(adminSelected).join(',');

    if (count > 0) {
        summary.innerHTML = `Selected <strong>${count}</strong> square${count > 1 ? 's' : ''}: <span style="color:#2d5016;">${Array.from(adminSelected).join(', ')}</span>`;
        btn.textContent = `Assign ${count} Square${count > 1 ? 's' : ''}`;
        btn.disabled = false;
    } else {
        summary.textContent = 'No cells selected (click squares above or tap Quick Random)';
        btn.textContent = 'Assign Selected Cells';
        btn.disabled = true;
    }
}
</script>

<!-- Cells Table -->
<table>
    <thead>
        <tr>
            <th>Cell</th><th>Ticket ID</th><th>Buyer</th><th>Email</th><th>Status</th><th>Assigned By</th><th>Prize</th><th>Assigned At</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cells as $cell): ?>
        <tr<?= $cell['prize'] ? ' style="background:#fff8e1;"' : '' ?>>
            <td><strong><?= htmlspecialchars($cell['cell_reference']) ?></strong></td>
            <td><?= htmlspecialchars($cell['ticket_id']) ?></td>
            <td><?= htmlspecialchars($cell['buyer_name']) ?></td>
            <td><?= htmlspecialchars($cell['buyer_email'] ?? '') ?></td>
            <td>
                <?= !empty($cell['is_paid']) 
                    ? '<span style="background:#d4edda;color:#155724;padding:3px 8px;border-radius:4px;font-weight:bold;font-size:0.85em;">🟢 Paid</span>' 
                    : '<span style="background:#fff3cd;color:#856404;padding:3px 8px;border-radius:4px;font-weight:bold;font-size:0.85em;">🟠 Unpaid</span>' ?>
            </td>
            <td><span style="background:#e9ecef;padding:2px 7px;border-radius:4px;font-size:0.85em;font-weight:bold;"><?= htmlspecialchars($cell['assigned_by'] ?? 'hi.events') ?></span></td>
            <td><?= $cell['prize'] ? '🏆 ' . htmlspecialchars($cell['prize']) : '' ?></td>
            <td><?= $cell['created_at'] ?></td>
            <td>
                <form method="POST" class="inline" onsubmit="return confirm('Remove assignment?')">
                    <input type="hidden" name="action" value="delete_cell">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <button type="submit" class="danger">Remove</button>
                </form>

                <?php if (empty($cell['is_paid'])): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Cancel in hi.events and message buyer?')">
                    <input type="hidden" name="action" value="cancel_and_notify">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <button type="submit" style="background:#dc3545;color:white;font-size:0.8em;padding:5px 10px;" title="Cancel on hi.events">Cancel &amp; Notify</button>
                </form>
                <?php endif; ?>

                <?php if ($cell['prize']): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="assign_prize">
                    <input type="hidden" name="cell_id" value="<?= $cell['id'] ?>">
                    <input type="hidden" name="prize" value="">
                    <button type="submit" class="warning" title="Remove prize">🏆✖</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($cells)): ?><tr><td colspan="9" style="text-align:center;color:#999;">No cells assigned yet</td></tr><?php endif; ?>
    </tbody>
</table>