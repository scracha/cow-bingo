<?php
// views/events_view.php
if ($isSuperAdmin): 
    $hiEvents = function_exists('fetchHiEventsEvents') ? fetchHiEventsEvents() : [];
    $existingHiIds = array_column($events, 'hievents_event_id');
?>
<h2>Create New Cow Bingo Event</h2>
<form method="POST">
    <input type="hidden" name="action" value="create_event">
    <div class="form-group">
        <label>Hi.Events Event:</label>
        <?php if (!empty($hiEvents)): ?>
            <select name="hievents_event_id" id="event_select" onchange="updateEventName()">
                <option value="">-- Select an event --</option>
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
            <input type="text" name="hievents_event_id_manual" placeholder="e.g. 42">
        <?php else: ?>
            <input type="text" name="hievents_event_id" required placeholder="e.g. 42 (from your hi.events URL)">
        <?php endif; ?>
    </div>
    <div class="form-group">
        <label>Event Name:</label>
        <input type="text" name="event_name" id="event_name_input" required placeholder="e.g. Summer Cow Bingo 2026">
    </div>
    <div class="form-group"><label>Grid Columns:</label><input type="number" name="grid_cols" value="16" min="1" max="26"></div>
    <div class="form-group"><label>Grid Rows:</label><input type="number" name="grid_rows" value="16" min="1" max="50"></div>
    <div class="form-group"><label>&nbsp;</label><button type="submit" class="primary">Create Event</button></div>
</form>
<script>
function updateEventName() {
    const select = document.getElementById('event_select');
    const selected = select.options[select.selectedIndex];
    if (selected && selected.dataset.name) document.getElementById('event_name_input').value = selected.dataset.name;
}
</script>
<?php endif; ?>

<h2>Events</h2>
<table>
    <thead><tr><th>ID</th><th>Hi.Events ID</th><th>Name</th><th>Grid</th><th>Sold</th><th>Active</th><th>Actions</th></tr></thead>
    <tbody>
        <?php foreach ($events as $ev): $total = $ev['grid_cols'] * $ev['grid_rows']; ?>
        <tr>
            <td><?= $ev['id'] ?></td>
            <td><?= htmlspecialchars($ev['hievents_event_id']) ?></td>
            <td><?= htmlspecialchars($ev['event_name']) ?></td>
            <td><?= $ev['grid_cols'] ?>x<?= $ev['grid_rows'] ?></td>
            <td><?= $ev['sold_count'] ?>/<?= $total ?></td>
            <td><?= $ev['is_active'] ? '✅' : '❌' ?></td>
            <td>
                <a class="btn" href="?view=<?= $ev['id'] ?>">View Cells</a>
                <a class="btn" href="grid.php?event=<?= htmlspecialchars($ev['hievents_event_id']) ?>" target="_blank">Grid</a>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="sync_attendees">
                    <input type="hidden" name="event_db_id" value="<?= $ev['id'] ?>">
                    <button type="submit" class="primary" style="font-size:0.8em;padding:5px 10px;" onclick="return confirm('Sync attendees from hi.events?')">Sync</button>
                </form>
                <form method="POST" class="inline">
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