<?php
// views/admins_view.php
if (!$isSuperAdmin) return;

$admins = $db->query("SELECT a.*, GROUP_CONCAT(e.event_name SEPARATOR ', ') as event_names
    FROM cow_bingo_admins a
    LEFT JOIN cow_bingo_admin_events ae ON ae.admin_id = a.id
    LEFT JOIN cow_bingo_events e ON e.id = ae.event_id
    GROUP BY a.id ORDER BY a.created_at")->fetchAll(PDO::FETCH_ASSOC);
?>
<h2>
    Manage Admins &amp; iPad Sellers
    <button type="button" class="btn-help-inline" onclick="openIpadModal()">📱 How to set up iPads</button>
</h2>
<table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Assigned Event(s)</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($admins as $adm): 
        $roleLabel = ($adm['role'] ?? '') === 'seller' ? '📱 iPad / Field Seller' : ((!empty($adm['is_super_admin']) || ($adm['role'] ?? '') === 'super_admin') ? '⭐ Super Admin' : 'Event Admin');
    ?>
        <tr>
            <td><?= htmlspecialchars($adm['username']) ?></td>
            <td><?= htmlspecialchars($adm['display_name']) ?></td>
            <td><strong><?= $roleLabel ?></strong></td>
            <td><?= (!empty($adm['is_super_admin']) || ($adm['role'] ?? '') === 'super_admin') ? '<em>All events</em>' : htmlspecialchars($adm['event_names'] ?? 'None') ?></td>
            <td>
                <?php if ((int)$adm['id'] !== $currentAdminId): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Delete this user?')">
                    <input type="hidden" name="action" value="delete_admin">
                    <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                    <button type="submit" class="danger">Remove</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3 style="margin-top:20px;">Add New Admin or iPad Seller</h3>
<form method="POST" style="padding:15px; background:white; border-radius:6px; margin-bottom:15px;">
    <input type="hidden" name="action" value="create_admin">
    <div class="form-group"><label>Username:</label><input type="text" name="new_username" placeholder="e.g. ipad1" required></div>
    <div class="form-group"><label>Password:</label><input type="password" name="new_password" required></div>
    <div class="form-group"><label>Display Name:</label><input type="text" name="new_display_name" placeholder="e.g. iPad 1 - Green" required></div>
    <div class="form-group">
        <label>Role:</label>
        <select name="new_role">
            <option value="seller">📱 iPad / Cash Seller (pos.php only)</option>
            <option value="event_admin">Event Admin</option>
            <option value="super_admin">⭐ Super Admin (all access)</option>
        </select>
    </div>
    <div class="form-group">
        <label>Events Access:</label>
        <select name="new_event_ids[]" multiple style="height:80px;width:250px;">
            <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['event_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <br><small style="color:#666;">(Hold Ctrl/Cmd to select multiple. Required for iPad Sellers and Event Admins.)</small>
    </div>
    <div class="form-group"><label>&nbsp;</label><button type="submit" class="primary">Create User</button></div>
</form>