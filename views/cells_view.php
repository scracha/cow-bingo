<?php
// views/cells_view.php
if (!$viewEvent) return;
?>
<h2>Cells for: <?= htmlspecialchars($viewEvent['event_name']) ?> (<?= count($cells) ?> sold)</h2>

<!-- Assign Prize -->
<form method="POST" action="?view=<?= $viewEventId ?>" style="margin-bottom:15px; padding:15px; background:#fff8e1; border:1px solid #ffe082; border-radius:6px;">
    <input type="hidden" name="action" value="assign_prize_by_ref">
    <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
    <strong>🏆 Assign Prize:</strong>
    <input type="text" name="cell_reference" required placeholder="Cell (e.g. C13)" style="padding:6px;width:100px;text-transform:uppercase;">
    <input type="text" name="prize" required placeholder="Prize (e.g. 1st Prize)" style="padding:6px;width:250px;">
    <button type="submit" class="primary">Assign Prize</button>
</form>

<!-- Manual Assign -->
<form method="POST" action="?view=<?= $viewEventId ?>" style="margin-bottom:15px; padding:15px; background:white; border-radius:6px;">
    <input type="hidden" name="action" value="assign">
    <input type="hidden" name="event_db_id" value="<?= $viewEventId ?>">
    <strong>Manual assign:</strong>
    <input type="text" name="ticket_id" required placeholder="Ticket ID" style="padding:6px;width:120px;">
    <input type="text" name="buyer_name" required placeholder="Buyer Name" style="padding:6px;width:150px;">
    <input type="email" name="buyer_email" placeholder="Email (optional)" style="padding:6px;width:170px;">
    <button type="submit" class="primary">Assign Random Cell</button>
</form>

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