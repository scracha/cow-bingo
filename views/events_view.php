<?php
// views/events_view.php
$csrfToken = getCsrfToken();

if ($isSuperAdmin): 
    $hiEventsEnabled = !defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED;
    $hiEvents = ($hiEventsEnabled && function_exists('fetchHiEventsEvents')) ? fetchHiEventsEvents() : [];
    $existingHiIds = array_filter(array_column($events, 'hievents_event_id'));
?>
<h2>📖 Setup Guide</h2>
<div style="background:white; padding:20px; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.1); margin-bottom:20px; line-height:1.7;">
    <details>
        <summary style="cursor:pointer; font-weight:bold; font-size:1.1em; color:#2d5016;">How to set up a Cow Bingo event (click to expand)</summary>
        <ol style="margin-top:15px; padding-left:20px;">
            <li><strong>Option A: Linked with Hi.Events (Online Sales &amp; Webhooks)</strong><br>
                Create the event on hi.events, select it in the dropdown below, and configure your webhook.<br>
                Webhook URL: <code>https://your-domain.com/cow-bingo/webhook.php</code></li>
            <li><strong>Option B: Standalone / Offline (Cash &amp; iPads Only)</strong><br>
                Leave the Hi.Events field blank. Enter a name, grid size, and cash pricing tiers below. You can link a hi.events ID later if desired.</li>
            <li><strong>Field Sellers (iPads)</strong><br>
                Under <em>Manage Admins &amp; iPad Sellers</em>, create accounts with the role <strong>iPad / Cash Seller</strong>. <button type="button" class="btn-help-inline" onclick="openIpadModal()">View iPad setup walkthrough</button>.</li>
        </ol>
    </details>
</div>

<h2>Create New Cow Bingo Event</h2>
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="create_event">
    <div class="form-group">
        <label>Hi.Events Event:</label>
        <?php if (!empty($hiEvents)): ?>
            <select name="hievents_event_id" id="event_select" onchange="updateEventName()">
                <option value="">-- Standalone / Offline (Link later) --</option>
                <?php foreach ($hiEvents as $he): 
                    $alreadyConfigured = in_array((string)$he['id'], $existingHiIds, true);
                ?>
                    <option value="<?= htmlspecialchars($he['id']) ?>" 
                            data-name="<?= htmlspecialchars($he['title'] ?? $he['name'] ?? '') ?>"
                            <?= $alreadyConfigured ? 'disabled' : '' ?>>
                        <?= htmlspecialchars(($he['title'] ?? $he['name'] ?? 'Untitled') . ' (ID: ' . $he['id'] . ')') ?>
                        <?= $alreadyConfigured ? ' — (Already Created)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>
            <label>Or enter ID manually:</label>
            <input type="text" name="hievents_event_id_manual" placeholder="Optional (e.g. 42)">
        <?php else: ?>
            <input type="text" name="hievents_event_id" placeholder="Optional (leave blank for Standalone / Offline)">
        <?php endif; ?>
        <br><small style="color:#666;margin-left:154px;">Leave blank if hi.events is offline or not used.</small>
    </div>

    <div class="form-group">
        <label>Event Name: *</label>
        <input type="text" name="event_name" id="event_name_input" required placeholder="e.g. Summer Cow Bingo 2026">
    </div>

    <div class="form-group">
        <label>Cash Pricing Tiers:</label>
        <input type="text" name="pricing_tiers" value="1:10, 3:25, 5:40, 10:70" placeholder="e.g. 1:10, 3:25, 5:40" style="width:300px;">
        <br><small style="color:#666;margin-left:154px;">Format: <code>qty:price, qty:price</code> (used for cash discount calculation on iPads)</small>
    </div>

    <div class="form-group"><label>Grid Columns:</label><input type="number" name="grid_cols" value="16" min="1" max="26"></div>
    <div class="form-group"><label>Grid Rows:</label><input type="number" name="grid_rows" value="16" min="1" max="50"></div>
    <div class="form-group"><label>&nbsp;</label><button type="submit" class="primary">Create Event</button></div>
</form>
<script>
function updateEventName() {
    const select = document.getElementById('event_select');
    if (!select) return;
    const selected = select.options[select.selectedIndex];
    if (selected && selected.dataset.name) {
        document.getElementById('event_name_input').value = selected.dataset.name;
    }
}
</script>
<?php endif; ?>

<h2>Events</h2>
<table>
    <thead><tr><th>ID</th><th>Hi.Events ID</th><th>Name</th><th>Grid</th><th>Sold</th><th>Active</th><th>Actions</th></tr></thead>
    <tbody>
        <?php foreach ($events as $ev): 
            $total = $ev['grid_cols'] * $ev['grid_rows'];
            $hasHiEvents = !empty($ev['hievents_event_id']);
        ?>
        <tr>
            <td><?= $ev['id'] ?></td>
            <td>
                <?php if ($hasHiEvents): ?>
                    <strong><?= htmlspecialchars($ev['hievents_event_id']) ?></strong>
                <?php else: ?>
                    <span style="background:#e2e3e5;color:#383d41;padding:2px 6px;border-radius:4px;font-size:0.8em;font-weight:bold;">Offline</span>
                    <form method="POST" class="inline" style="margin-left:4px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="link_hievents">
                        <input type="hidden" name="event_db_id" value="<?= $ev['id'] ?>">
                        <input type="text" name="hievents_event_id" placeholder="ID #" style="padding:2px 5px;font-size:0.75em;width:45px;" required>
                        <button type="submit" class="primary" style="padding:2px 6px;font-size:0.75em;" title="Link to Hi.Events ID">🔗 Link</button>
                    </form>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($ev['event_name']) ?></td>
            <td><?= $ev['grid_cols'] ?>x<?= $ev['grid_rows'] ?></td>
            <td><?= $ev['sold_count'] ?>/<?= $total ?></td>
            <td><?= $ev['is_active'] ? '✅' : '❌' ?></td>
            <td>
                <a class="btn" href="?tab=cells&view=<?= $ev['id'] ?>">View Cells</a>
                <a class="btn" href="grid.php?id=<?= $ev['id'] ?>" target="_blank">Grid</a>
                
                <?php if ($hasHiEvents): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="sync_attendees">
                        <input type="hidden" name="event_db_id" value="<?= $ev['id'] ?>">
                        <button type="submit" class="primary" style="font-size:0.8em;padding:5px 10px;" onclick="return confirm('Sync attendees from hi.events?')">Sync</button>
                    </form>
                <?php endif; ?>

                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="toggle_event">
                    <input type="hidden" name="event_db_id" value="<?= $ev['id'] ?>">
                    <button type="submit" class="warning"><?= $ev['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($events)): ?><tr><td colspan="7" style="text-align:center;color:#999;">No events created yet</td></tr><?php endif; ?>
    </tbody>
</table>
