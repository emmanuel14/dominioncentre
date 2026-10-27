<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'');

function html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function jsonReply(int $status, string $message, bool $ok = false): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') jsonReply(405, 'Method not allowed.');
$reference = trim((string) ($method === 'POST' ? ($_POST['ref'] ?? '') : ($_GET['ref'] ?? '')));
$token = trim((string) ($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));
$valid = preg_match('/^DF3-[A-F0-9]{8}$/D', $reference) && preg_match('/^[a-f0-9]{64}$/D', $token);
$configPath = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : dirname(__DIR__) . '/config.php';
$config = is_file($configPath) ? require $configPath : null;
$table = is_array($config) ? (string) ($config['registrations_table'] ?? 'applications') : '';
$state = 'invalid';
$pdo = null;
try {
    if (!$valid || !is_array($config) || empty($config['dsn']) || !preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
        if ($method === 'POST') jsonReply(403, 'This verification link is invalid or unavailable.');
    } else {
        $pdo = new PDO($config['dsn'], $config['db_user'] ?? '', $config['db_password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($method === 'POST') {
            if (($_POST['consent'] ?? '') !== 'yes') jsonReply(422, 'You must explicitly confirm your consent.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT guardian_verification_status, guardian_verification_token_hash, guardian_verification_expires_at FROM `{$table}` WHERE registration_reference = ? AND guardian_consent = 1 FOR UPDATE");
            $stmt->execute([$reference]);
            $row = $stmt->fetch();
            if (!$row || !is_string($row['guardian_verification_token_hash']) || !hash_equals($row['guardian_verification_token_hash'], hash('sha256', $token))) {
                $pdo->rollBack(); jsonReply(403, 'This verification link is invalid or has already been used.');
            }
            if ($row['guardian_verification_status'] !== 'pending' || empty($row['guardian_verification_expires_at']) || strtotime($row['guardian_verification_expires_at'] . ' UTC') <= time()) {
                $pdo->rollBack(); jsonReply(410, 'This verification link has expired or has already been used. Contact the organisers.');
            }
            $update = $pdo->prepare("UPDATE `{$table}` SET guardian_verification_status = 'verified', guardian_verified_at = UTC_TIMESTAMP(), guardian_verification_token_hash = NULL, guardian_verification_expires_at = NULL WHERE registration_reference = ? AND guardian_verification_status = 'pending'");
            $update->execute([$reference]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Guardian status update failed.');
            $pdo->commit();
            jsonReply(200, 'Thank you. Your parent/guardian consent has been verified for UPRISING 2026.', true);
        }
        $stmt = $pdo->prepare("SELECT guardian_verification_status, guardian_verification_token_hash, guardian_verification_expires_at FROM `{$table}` WHERE registration_reference = ? AND guardian_consent = 1");
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        if ($row && $row['guardian_verification_status'] === 'pending' && is_string($row['guardian_verification_token_hash']) && hash_equals($row['guardian_verification_token_hash'], hash('sha256', $token))) {
            $state = (!empty($row['guardian_verification_expires_at']) && strtotime($row['guardian_verification_expires_at'] . ' UTC') > time()) ? 'pending' : 'expired';
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Guardian verification failure: ' . $e->getMessage());
    if ($method === 'POST') jsonReply(500, 'Verification is temporarily unavailable. Please try again later.');
    $state = 'unavailable';
}
$ref = html($reference); $tok = html($token);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>UPRISING 2026 | Guardian Verification</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f1f6f2;color:#183d27;font-family:Arial,Helvetica,sans-serif}header{background:#143d28;color:#fff;text-align:center;padding:36px 16px}header h1{margin:0}main{max-width:690px;margin:35px auto;padding:0 16px}.card{background:#fff;padding:32px;border-radius:14px;box-shadow:0 12px 36px #143d281b;line-height:1.7}.notice{background:#effaf2;border-left:4px solid #198444;padding:16px;border-radius:7px}label{display:flex;gap:12px;align-items:flex-start;margin:24px 0}label input{margin-top:7px}button{background:#15803d;border:0;color:white;font-size:16px;font-weight:700;padding:14px 22px;border-radius:8px;cursor:pointer}button:disabled{opacity:.6}#result{margin-top:20px}.error{color:#a01c1c}</style></head>
<body><header><h1>UPRISING 2026</h1><p>Parent / Guardian Verification</p></header><main><div class="card">
<?php if ($state === 'pending'): ?>
<h2>Review and confirm consent</h2><p>Registration reference: <strong><?= $ref ?></strong></p>
<div class="notice">A contestant under 18 has named you as their parent or legal guardian. Confirm only if you are legally authorised to approve this contestant's participation.</div>
<p>By confirming, you acknowledge that you have reviewed the UPRISING 3.0 Kingdom Content &amp; Performance Policy, Competition Terms &amp; Conditions, Privacy Notice and safeguarding arrangements. You authorise the minor's participation and confirm that the registration information supplied to you is accurate.</p>
<form id="verifyForm" method="post"><input type="hidden" name="ref" value="<?= $ref ?>"><input type="hidden" name="token" value="<?= $tok ?>">
<label><input type="checkbox" name="consent" value="yes" required> <span>I confirm that I am the parent or legally authorised guardian, have reviewed the stated requirements, and explicitly authorise this contestant's participation in UPRISING 2026.</span></label>
<button type="submit" id="btn">Verify my consent</button><p id="result" role="status"></p></form>
<?php elseif ($state === 'expired'): ?><h2>Link expired</h2><p>This verification link has expired. Please contact the UPRISING 2026 organisers and quote reference <strong><?= $ref ?></strong> to request a new link.</p>
<?php elseif ($state === 'unavailable'): ?><h2>Temporarily unavailable</h2><p>We cannot check this link right now. Please try again later.</p>
<?php else: ?><h2>Link unavailable</h2><p>This link is invalid or has already been used. If you need assistance, contact the organisers using the registration reference.</p>
<?php endif; ?></div></main>
<script>const form=document.getElementById('verifyForm');if(form){form.addEventListener('submit',async e=>{e.preventDefault();const btn=document.getElementById('btn'),result=document.getElementById('result');btn.disabled=true;result.textContent='Verifying…';try{const r=await fetch('verify-guardian.php',{method:'POST',body:new FormData(form),headers:{Accept:'application/json'}});const d=await r.json();if(!r.ok||!d.ok)throw Error(d.message||'Verification failed');result.textContent=d.message;result.className='notice';form.querySelectorAll('input').forEach(i=>i.disabled=true);btn.hidden=true;}catch(err){result.textContent=err.message;result.className='error';btn.disabled=false;}})}</script></body></html>
