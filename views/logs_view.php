<?php
// views/logs_view.php
?>
<h2>📜 Activity &amp; Audit Trail</h2>
<p style="color:#666; font-size:0.9em; margin-bottom:15px;">
    Permanent record of squares assigned, removed, cancelled, paid, or prizes awarded for this event.
</p>

<!-- Quick Filter Buttons -->
<div style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
    <button type="button" class="btn" style="background:#2d5016; font-size:0.8em; padding:4px 10px;" onclick="filterAudit('all')">All Events</button>
    <button type="button" class="btn" style="background:#dc3545; font-size:0.8em; padding:4px 10px;" onclick="filterAudit('removed')">🗑️ Removals</button>
    <button type="button" class="btn" style="background:#d97706; font-size:0.8em; padding:4px 10px;" onclick="filterAudit('cancelled')">⚠️ Cancellations</button>
    <button type="button" class="btn" style="background:#15803d; font-size:0.8em; padding:4px 10px;" onclick="filterAudit('paid')">💵 Payments</button>
    <button type="button" class="btn" style="background:#b45309; font-size:0.8em; padding:4px 10px;" onclick="filterAudit('prize')">🏆 Prizes</button>
    <button type="button" class="btn" style="background:#4b5563; font-size:0.8em; padding:4px 10px;" onclick="filterAudit('admin')">⚙️ Setup / Admin</button>
</div>

<table id="auditTable">
    <thead>
        <tr>
            <th>Time</th>
            <th>Action</th>
            <th>Cell</th>
            <th>Ticket / Batch Ref</th>
            <th>Buyer Name</th>
            <th>Amount</th>
            <th>Performed By</th>
            <th>Details / Context</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($auditLogs as $log): 
            $action = $log['action'];
            $badgeStyle = 'background:#e5e7eb; color:#374151;';
            $actionLabel = $action;
            $category = 'other';

            if (strpos($action, 'removed') !== false) {
                $badgeStyle = 'background:#fee2e2; color:#991b1b;';
                $actionLabel = '🗑️ Square Removed';
                $category = 'removed';
            } elseif (strpos($action, 'cancelled') !== false) {
                $badgeStyle = 'background:#fef3c7; color:#92400e;';
                $actionLabel = '⚠️ Cancelled';
                $category = 'cancelled';
            } elseif ($action === 'marked_paid') {
                $badgeStyle = 'background:#dcfce7; color:#166534;';
                $actionLabel = '💵 Marked Paid';
                $category = 'paid';
            } elseif (strpos($action, 'prize') !== false) {
                $badgeStyle = 'background:#fef9c3; color:#854d0e;';
                $actionLabel = '🏆 Prize';
                $category = 'prize';
            } elseif ($action === 'contacts_purged') {
                $badgeStyle = 'background:#e0e7ff; color:#3730a3;';
                $actionLabel = '🔒 Contacts Purged';
                $category = 'admin';
            } elseif ($action === 'cell_assigned') {
                $badgeStyle = 'background:#f0fdf4; color:#15803d;';
                $actionLabel = '🎲 Cell Assigned';
                $category = 'other';
            } elseif (in_array($action, ['event_created', 'toggle_square_choice', 'toggle_event_active', 'hievents_linked'], true)) {
                $badgeStyle = 'background:#f3f4f6; color:#1f2937; border: 1px solid #d1d5db;';
                $actionLabel = '⚙️ ' . ucwords(str_replace('_', ' ', $action));
                $category = 'admin';
            }
        ?>
        <tr data-cat="<?= $category ?>">
            <td><?= date('d M Y, g:ia', strtotime($log['created_at'])) ?></td>
            <td><span style="padding:3px 8px; border-radius:4px; font-weight:bold; font-size:0.82em; <?= $badgeStyle ?>"><?= $actionLabel ?></span></td>
            <td><strong><?= htmlspecialchars($log['cell_reference'] ?? '—') ?></strong></td>
            <td><code style="font-size:0.85em;"><?= htmlspecialchars($log['ticket_id'] ?: ($log['order_id'] ?? '—')) ?></code></td>
            <td><?= htmlspecialchars($log['buyer_name'] ?? '—') ?></td>
            <td><?= $log['amount'] !== null ? ('$' . number_format((float)$log['amount'], 2)) : '—' ?></td>
            <td><strong><?= htmlspecialchars($log['performed_by']) ?></strong></td>
            <td style="font-size:0.88em; color:#555;"><?= htmlspecialchars($log['details'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($auditLogs)): ?>
        <tr><td colspan="8" style="text-align:center; color:#999; padding:20px;">No activity logged for this event yet.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<script>
function filterAudit(cat) {
    const rows = document.querySelectorAll('#auditTable tbody tr[data-cat]');
    rows.forEach(r => {
        if (cat === 'all' || r.getAttribute('data-cat') === cat) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
</script>

<?php if ($isSuperAdmin): ?>
<details style="margin-top:35px; background:white; padding:15px; border-radius:8px; border:1px solid #ddd;">
    <summary style="font-weight:bold; cursor:pointer; color:#2d5016;">📜 Raw Webhook Debug Logs (Hi.Events Incoming Payloads)</summary>
    <table style="margin-top:15px;">
        <thead><tr><th>ID</th><th>Event ID</th><th>Type</th><th>Processed</th><th>Error</th><th>Time</th><th>Payload</th></tr></thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td><?= $l['id'] ?></td>
                <td><?= htmlspecialchars($l['hievents_event_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($l['event_type']) ?></td>
                <td><?= $l['processed'] ? '✅' : '⏳' ?></td>
                <td><?= htmlspecialchars($l['error_message'] ?? '') ?></td>
                <td><?= $l['created_at'] ?></td>
                <td><pre><?= htmlspecialchars(substr($l['payload'], 0, 200)) ?></pre></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="7" style="text-align:center;color:#999;">No webhooks received yet</td></tr><?php endif; ?>
        </tbody>
    </table>
</details>
<?php endif; ?>
