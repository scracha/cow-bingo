<?php
// views/ipad_guide_modal.php

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
       || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
       || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
       ? 'https' : 'http';

$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost', ENT_QUOTES, 'UTF-8');
$baseDir = rtrim(htmlspecialchars(dirname($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8'), '/\\');
$detectedUrl = $scheme . '://' . $host . $baseDir . '/admin.php';
$detectedPosUrl = $scheme . '://' . $host . $baseDir . '/pos.php';

$adminUrl = (defined('SITE_URL') && SITE_URL)
    ? rtrim(SITE_URL, '/') . '/cow-bingo/admin.php'
    : $detectedUrl;

$posUrl = (defined('SITE_URL') && SITE_URL)
    ? rtrim(SITE_URL, '/') . '/cow-bingo/pos.php'
    : $detectedPosUrl;
?>
<div id="ipadGuideModal" class="modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center;">
    <div class="modal-box" style="background:#fff; border-radius:12px; width:92%; max-width:620px; max-height:90vh; overflow-y:auto; padding:24px 28px; box-shadow:0 10px 30px rgba(0,0,0,0.4); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#2d3748; line-height:1.6;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h2 style="margin:0; font-size:1.4em; color:#2d5016;">📱 iPad POS Kiosk Setup Guide</h2>
            <button type="button" onclick="closeIpadGuideModal()" style="background:none; border:none; font-size:1.6em; cursor:pointer; color:#718096; line-height:1;">&times;</button>
        </div>

        <p style="font-size:0.95em; margin-bottom:16px;">
            Follow these steps to lock down an iPad into a single-purpose full-screen Point-of-Sale terminal for cash sellers:
        </p>

        <ol style="padding-left:22px; font-size:0.92em; display:flex; flex-direction:column; gap:12px;">
            <li>
                <strong>Open Safari on the iPad</strong> and navigate to the Seller POS terminal:<br>
                <code style="display:inline-block; background:#edf2f7; padding:4px 8px; border-radius:4px; color:#2d5016; font-weight:bold; word-break:break-all; margin-top:4px;"><?= $posUrl ?></code>
                <br><small style="color:#718096;">(Or log in via <code><?= $adminUrl ?></code> with your iPad Seller account to be auto-routed)</small>
            </li>
            <li>
                <strong>Add to Home Screen (Full Screen App)</strong>:<br>
                Tap the <em>Share icon</em> (box with an arrow pointing up), scroll down, and select <strong>Add to Home Screen</strong>. Tap <strong>Add</strong>.
            </li>
            <li>
                <strong>Enable Guided Access (Kiosk Lockdown)</strong>:<br>
                Go to iPad <strong>Settings &rarr; Accessibility &rarr; Guided Access</strong>.<br>
                Turn Guided Access <strong>ON</strong> and set a 4-digit Passcode.
            </li>
            <li>
                <strong>Launch &amp; Lock</strong>:<br>
                Open the newly added <em>Cow Pat Bingo</em> icon from the Home Screen. Once loaded, <strong>triple-click the top or Home button</strong>, then tap <strong>Start</strong> in the top-right corner to lock the iPad.
            </li>
            <li>
                <strong>Exiting Kiosk Mode</strong>:<br>
                Triple-click the top or Home button again, enter your Passcode, and tap <strong>End</strong>.
            </li>
        </ol>

        <div style="text-align:right; margin-top:24px; border-top:1px solid #e2e8f0; padding-top:14px;">
            <button type="button" onclick="closeIpadGuideModal()" style="background:#2d5016; color:#fff; border:none; padding:9px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Got It</button>
        </div>
    </div>
</div>
<script>
function openIpadGuideModal() {
    const el = document.getElementById('ipadGuideModal');
    if (el) el.style.display = 'flex';
}
function closeIpadGuideModal() {
    const el = document.getElementById('ipadGuideModal');
    if (el) el.style.display = 'none';
}
</script>
