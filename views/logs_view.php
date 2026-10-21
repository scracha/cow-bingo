<?php
// views/logs_view.php
if (!$isSuperAdmin) return;
?>
<h2>Recent Webhook Logs</h2>
<table>
    <thead><tr><th>ID</th><th>Event ID</th><th>Type</th><th>Processed</th><th>Error</th><th>Time</th><th>Payload</th></tr></thead>
    <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td><?= $log['id'] ?></td>
            <td><?= htmlspecialchars($log['hievents_event_id'] ?? '-') ?></td>
            <td><?= htmlspecialchars($log['event_type']) ?></td>
            <td><?= $log['processed'] ? '✅' : '⏳' ?></td>
            <td><?= htmlspecialchars($log['error_message'] ?? '') ?></td>
            <td><?= $log['created_at'] ?></td>
            <td><pre><?= htmlspecialchars(substr($log['payload'], 0, 200)) ?></pre></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?><tr><td colspan="7" style="text-align:center;color:#999;">No webhooks received yet</td></tr><?php endif; ?>
    </tbody>
</table>