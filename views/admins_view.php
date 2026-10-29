<?php
// views/admins_view.php
$csrfToken = getCsrfToken();

if ($isSuperAdmin) {
    $admins = $db->query("SELECT a.*, 
        GROUP_CONCAT(DISTINCT e.event_name SEPARATOR ', ') as event_names,
        GROUP_CONCAT(DISTINCT ae.event_id) as event_ids
        FROM cow_bingo_admins a
        LEFT JOIN cow_bingo_admin_events ae ON ae.admin_id = a.id
        LEFT JOIN cow_bingo_events e ON e.id = ae.event_id
        GROUP BY a.id ORDER BY a.created_at")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $admStmt = $db->prepare("
        SELECT a.*, 
        GROUP_CONCAT(DISTINCT e.event_name SEPARATOR ', ') as event_names,
        GROUP_CONCAT(DISTINCT ae.event_id) as event_ids
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
    <button type="button" class="btn-help-inline" onclick="if(typeof openIpadGuideModal==='function'){openIpadGuideModal();}else if(typeof openIpadModal==='function'){openIpadModal();}">📱 How to set up iPads</button>
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
        $canEdit = $isSuperAdmin || (($adm['role'] ?? '') === 'seller' && empty($adm['is_super_admin']));
        $adminDataJson = htmlspecialchars(json_encode([
            'id' => (int)$adm['id'],
            'username' => $adm['username'] ?? '',
            'display_name' => $adm['display_name'] ?? '',
            'role' => $adm['role'] ?? '',
            'is_super_admin' => (int)($adm['is_super_admin'] ?? 0),
            'event_ids' => $adm['event_ids'] ?? ''
        ]), ENT_QUOTES, 'UTF-8');
    ?>
        <tr>
            <td><?= htmlspecialchars($adm['username']) ?></td>
            <td><?= htmlspecialchars($adm['display_name']) ?></td>
            <td><strong><?= $roleLabel ?></strong></td>
            <td><?= (!empty($adm['is_super_admin']) || ($adm['role'] ?? '') === 'super_admin') ? '<em>All events</em>' : htmlspecialchars($adm['event_names'] ?? 'None') ?></td>
            <td>
                <div style="display:inline-flex; gap:6px; align-items:center;">
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn-sm btn-edit-admin" data-admin="<?= $adminDataJson ?>" style="padding:4px 8px; font-size:0.8em; cursor:pointer;">✏️ Edit</button>
                    <?php endif; ?>
                    <?php if ((int)$adm['id'] !== $currentAdminId): ?>
                    <form method="POST" class="inline" style="margin:0;" onsubmit="return confirm('Remove this user?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_admin">
                        <input type="hidden" name="tab" value="admins">
                        <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                        <button type="submit" class="danger btn-sm" style="padding:4px 8px; font-size:0.8em;">Remove</button>
                    </form>
                    <?php endif; ?>
                </div>
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
    <input type="hidden" name="tab" value="admins">
    
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

<!-- MODAL: EDIT USER & CHANGE PASSWORD / EVENT ACCESS -->
<div id="editAdminModal" class="modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center;">
    <div class="modal-box" style="background:#fff; border-radius:12px; width:92%; max-width:500px; max-height:90vh; overflow-y:auto; padding:24px 28px; box-shadow:0 10px 30px rgba(0,0,0,0.4); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:1.3em; color:#2d5016;">✏️ Edit User / Change Password</h3>
            <button type="button" onclick="closeEditAdminModal()" style="background:none; border:none; font-size:1.5em; cursor:pointer; color:#718096;">&times;</button>
        </div>

        <form method="POST" action="admin.php?tab=admins">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="update_admin">
            <input type="hidden" name="tab" value="admins">
            <input type="hidden" name="admin_id" id="edit_admin_id" value="">

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:600; color:#334155; margin-bottom:5px;">Username:</label>
                <input type="text" id="edit_admin_username" disabled style="background:#f1f5f9; cursor:not-allowed;">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:600; color:#334155; margin-bottom:5px;">Display Name *</label>
                <input type="text" name="display_name" id="edit_admin_display_name" required>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:600; color:#334155; margin-bottom:5px;">Change Password (leave blank to keep current):</label>
                <input type="password" name="new_password" id="edit_admin_password" placeholder="Enter new password (min 8 chars)" autocomplete="new-password">
                <small style="color:#64748b; font-size:0.8em;">Leave blank if you do not wish to change the password.</small>
            </div>

            <?php if ($isSuperAdmin): ?>
            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:600; color:#334155; margin-bottom:5px;">Role:</label>
                <select name="role" id="edit_admin_role" onchange="handleEditRoleChange(this)">
                    <option value="seller">📱 iPad / Cash Seller (pos.php only)</option>
                    <option value="event_admin">Event Admin</option>
                    <option value="super_admin">★ Super Admin (all access)</option>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="role" id="edit_admin_role" value="seller">
            <?php endif; ?>

            <div class="form-group" id="editEventAccessGroup" style="margin-bottom:18px;">
                <label style="display:block; font-size:0.85em; font-weight:600; color:#334155; margin-bottom:5px;">Assigned Event(s):</label>
                <select name="event_ids[]" id="edit_admin_event_ids" multiple style="height:100px; width:100%;">
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>">
                        <?= htmlspecialchars($ev['event_name']) ?> (#<?= $ev['id'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:#64748b; font-size:0.8em; display:block; margin-top:4px;">(Hold Ctrl / Cmd to select multiple. Super Admins always have access to all events.)</small>
            </div>

            <div style="text-align:right; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" onclick="closeEditAdminModal()" style="background:#e2e8f0; color:#4a5568; border:none; padding:9px 16px; border-radius:6px; font-weight:bold; cursor:pointer; margin-right:8px;">Cancel</button>
                <button type="submit" class="primary" style="padding:9px 20px; font-size:0.9em;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

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

function handleEditRoleChange(select) {
    const eventGroup = document.getElementById('editEventAccessGroup');
    if (!eventGroup) return;
    if (select.value === 'super_admin') {
        eventGroup.style.opacity = '0.5';
    } else {
        eventGroup.style.opacity = '1';
    }
}

function openEditAdminModal(adminData) {
    document.getElementById('edit_admin_id').value = adminData.id || '';
    document.getElementById('edit_admin_username').value = adminData.username || '';
    document.getElementById('edit_admin_display_name').value = adminData.display_name || '';
    document.getElementById('edit_admin_password').value = '';

    const roleSelect = document.getElementById('edit_admin_role');
    if (roleSelect && roleSelect.tagName === 'SELECT') {
        roleSelect.value = adminData.role || (adminData.is_super_admin == 1 ? 'super_admin' : 'event_admin');
        handleEditRoleChange(roleSelect);
    }

    const eventSelect = document.getElementById('edit_admin_event_ids');
    if (eventSelect) {
        const assigned = (adminData.event_ids ? String(adminData.event_ids).split(',') : []).map(s => s.trim());
        for (let i = 0; i < eventSelect.options.length; i++) {
            eventSelect.options[i].selected = assigned.includes(eventSelect.options[i].value);
        }
    }

    const modal = document.getElementById('editAdminModal');
    if (modal) modal.style.display = 'flex';
}

function closeEditAdminModal() {
    const modal = document.getElementById('editAdminModal');
    if (modal) modal.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const roleEl = document.getElementById('newRoleSelect');
    if (roleEl) handleRoleChange(roleEl);

    document.querySelectorAll('.btn-edit-admin').forEach(btn => {
        btn.addEventListener('click', function() {
            try {
                const data = JSON.parse(this.dataset.admin);
                openEditAdminModal(data);
            } catch (err) {
                console.error('Error opening edit admin modal:', err);
            }
        });
    });
});
</script>
