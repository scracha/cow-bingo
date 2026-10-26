<?php
// views/admins_view.php
$csrfToken = getCsrfToken();

if ($isSuperAdmin) {
    $admins = $db->query("SELECT a.*, GROUP_CONCAT(e.event_name SEPARATOR ', ') as event_names
        FROM cow_bingo_admins a
        LEFT JOIN cow_bingo_admin_events ae ON ae.admin_id = a.id
        LEFT JOIN cow_bingo_events e ON e.id = ae.event_id
        GROUP BY a.id ORDER BY a.created_at")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $admStmt = $db->prepare("
        SELECT a.*, GROUP_CONCAT(e.event_name SEPARATOR ', ') as event_names
        FROM cow_bingo_admins a
        INNER JOIN cow_bingo_admin_events ae ON ae.admin_id = a.id
        INNER JOIN cow_bingo_events e ON e.id = ae.event_id
        INNER JOIN cow_bingo_admin_events my_ae ON my_ae.event_id = e.id AND my_ae.admin_id = ?
        WHERE a.role = 'seller' AND a.is_super_admin = 0
        GROUP BY a.id ORDER BY a.created_at
    ");
    $admStmt->execute([$currentAdminId]);
    $admins = $admStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<h2>
    <?= $isSuperAdmin ? 'Manage Admins &amp; iPad Sellers' : 'Manage iPad Sellers' ?>
    <button type="button" class="btn-help-inline" onclick="openIpadModal()">📱 How to set up iPads</button>
</h2>
<table>
    <thead>
        <tr>
            <th>Username</th>
            <th>Name</th>
            <th>Role</th>
            <th>Assigned Event(s)</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($admins as $adm): 
        $roleLabel = ($adm['role'] ?? '') === 'seller' ? '📱 iPad / Field Seller' : ((!empty($adm['is_super_admin']) || ($adm['role'] ?? '') === 'super_admin') ? '★ Super Admin' : 'Event Admin');
    ?>
        <tr>
            <td><?= htmlspecialchars($adm['username']) ?></td>
            <td><?= htmlspecialchars($adm['display_name']) ?></td>
            <td><strong><?= $roleLabel ?></strong></td>
            <td><?= (!empty($adm['is_super_admin']) || ($adm['role'] ?? '') === 'super_admin') ? '<em>All events</em>' : htmlspecialchars($adm['event_names'] ?? 'None') ?></td>
            <td>
                <?php if ((int)$adm['id'] !== $currentAdminId): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Remove this seller?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_admin">
                    <input type="hidden" name="tab" value="admin">
                    <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                    <button type="submit" class="danger">Remove</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($admins)): ?>
        <tr><td colspan="5" style="text-align:center;color:#999;">No <?= $isSuperAdmin ? 'users' : 'iPad sellers' ?> created yet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h3 style="margin-top:25px;"><?= $isSuperAdmin ? 'Add New Admin or iPad Seller' : 'Add New iPad Seller' ?></h3>
<form method="POST" style="padding:15px; background:white; border-radius:6px; margin-bottom:15px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="create_admin">
    <input type="hidden" name="tab" value="admin">
    
    <div class="form-group">
        <label>Username:</label>
        <input type="text" name="new_username" placeholder="e.g. ipad1" required>
    </div>
    <div class="form-group">
        <label>Password:</label>
        <input type="password" name="new_password" required>
    </div>
    <div class="form-group">
        <label>Display Name:</label>
        <input type="text" name="new_display_name" placeholder="e.g. iPad 1 - Green" required>
    </div>

    <?php if ($isSuperAdmin): ?>
    <div class="form-group">
        <label>Role:</label>
        <select name="new_role" id="newRoleSelect" onchange="handleRoleChange(this)">
            <option value="seller" selected>📱 iPad / Cash Seller (pos.php only)</option>
            <option value="event_admin">Event Admin</option>
            <option value="super_admin">★ Super Admin (all access)</option>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" name="new_role" value="seller">
    <div class="form-group">
        <label>Role:</label>
        <span style="font-size:0.9em; font-weight:bold; color:#2d5016;">📱 iPad / Cash Seller (pos.php only)</span>
    </div>
    <?php endif; ?>

    <div class="form-group" id="eventAccessGroup">
        <label>Events Access:</label>
        <select name="new_event_ids[]" id="newEventIdsSelect" multiple style="height:80px;width:250px;" <?= !$isSuperAdmin ? 'required' : '' ?>>
            <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>" <?= count($events) === 1 ? 'selected' : '' ?>>
                <?= htmlspecialchars($ev['event_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <br><small style="color:#666;">(Hold Ctrl/Cmd to select multiple. Required for iPad Sellers and Event Admins.)</small>
    </div>

    <div class="form-group">
        <label>&nbsp;</label>
        <button type="submit" class="primary"><?= $isSuperAdmin ? 'Create User' : 'Create iPad Seller' ?></button>
    </div>
</form>

<script>
function handleRoleChange(select) {
    const eventSelect = document.getElementById('newEventIdsSelect');
    const eventGroup = document.getElementById('eventAccessGroup');
    if (!eventSelect) return;
    if (select.value === 'super_admin') {
        eventSelect.removeAttribute('required');
        if (eventGroup) eventGroup.style.opacity = '0.5';
    } else {
        eventSelect.setAttribute('required', 'required');
        if (eventGroup) eventGroup.style.opacity = '1';
    }
}
const roleEl = document.getElementById('newRoleSelect');
if (roleEl) handleRoleChange(roleEl);
</script>
