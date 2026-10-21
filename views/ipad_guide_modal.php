<?php
// views/ipad_guide_modal.php
?>
<div id="ipadModal" class="modal-overlay" onclick="closeOnBackdrop(event)">
    <div class="modal-content">
        <button type="button" class="modal-close" onclick="closeIpadModal()">&times;</button>
        <h2 style="margin-top:0; color:#2d5016; border-bottom:2px solid #2d5016; padding-bottom:10px;">
            📱 iPad Seller Setup Workflow
        </h2>
        <p style="color:#555; font-size:0.95em; margin-bottom:20px;">
            Follow this checklist on each iPad before giving it to student runners on event day.
        </p>

        <div class="guide-step">
            <div class="step-num">1</div>
            <div class="step-body">
                <h4>Create an Account for Each iPad</h4>
                <p>In the <strong>Manage Admins &amp; iPad Sellers</strong> form, create a seller account:</p>
                <p>• <strong>Username:</strong> <code>ipad1</code>, <code>ipad2</code>, etc.<br>
                   • <strong>Display Name:</strong> Physical device tag (e.g. <code>iPad 1 - Blue</code>)<br>
                   • <strong>Role:</strong> Select <code>📱 iPad / Cash Seller (pos.php only)</code><br>
                   • <strong>Events Access:</strong> Select your active Cow Bingo event.</p>
            </div>
        </div>

        <div class="guide-step">
            <div class="step-num">2</div>
            <div class="step-body">
                <h4>Open &amp; Log In on the iPad</h4>
                <p>Connect iPad to Wi-Fi/Hotspot, open Safari, and go to:</p>
                <p><code><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain') . dirname($_SERVER['PHP_SELF']) ?>/admin.php</code></p>
                <p>Log in with the iPad's credentials. It will instantly forward directly to <code>pos.php</code>.</p>
            </div>
        </div>

        <div class="guide-step">
            <div class="step-num">3</div>
            <div class="step-body">
                <h4>Add to Home Screen (Kiosk Mode)</h4>
                <p>1. Tap the Safari <strong>Share Icon</strong> (square with up-arrow ⎋).<br>
                   2. Tap <strong>"Add to Home Screen"</strong>.<br>
                   3. Launching from this icon opens the app full-screen without the URL address bar.</p>
            </div>
        </div>

        <div class="guide-step">
            <div class="step-num">4</div>
            <div class="step-body">
                <h4>Lock App with "Guided Access"</h4>
                <p>1. Go to iPad <strong>Settings &gt; Accessibility &gt; Guided Access</strong> and turn it ON.<br>
                   2. Open the Cow Bingo web app.<br>
                   3. <strong>Triple-click Top/Home button</strong>, choose a PIN, and tap Start.<br>
                   4. The iPad is locked to Cow Bingo so students cannot close it.</p>
            </div>
        </div>

        <div class="guide-step">
            <div class="step-num">5</div>
            <div class="step-body">
                <h4>Selling &amp; Reconciliation</h4>
                <p>Buyers tap squares (or runners hit <code>+3 Random</code>). Bulk discounts calculate automatically. Tap <strong>Collect Cash</strong>. At the end of the day, check the <code>Assigned By</code> column in the Cells table to balance the cash envelopes.</p>
            </div>
        </div>

        <div class="tip-box">
            💡 <strong>Tip:</strong> Keep screen brightness around 75% and disable auto-lock. Keep a 10,000mAh power bank at the central desk for top-ups.
        </div>

        <div style="text-align:right; margin-top:20px;">
            <button type="button" class="primary" onclick="closeIpadModal()">Got it, Close Guide</button>
        </div>
    </div>
</div>

<script>
function openIpadModal() {
    document.getElementById('ipadModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeIpadModal() {
    document.getElementById('ipadModal').classList.remove('active');
    document.body.style.overflow = '';
}
function closeOnBackdrop(e) {
    if (e.target.id === 'ipadModal') closeIpadModal();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeIpadModal(); });
</script>