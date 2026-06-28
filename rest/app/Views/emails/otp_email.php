<?php
$brandName = (string) ($brandName ?? 'AmbulatorioFacile');
$greeting = (string) ($greeting ?? 'Gentile utente,');
$notificationTitle = (string) ($notificationTitle ?? 'Codice OTP');
$notificationMessage = (string) ($notificationMessage ?? '');
$ctaCaption = (string) ($ctaCaption ?? '');
$otpLabel = (string) ($otpLabel ?? 'Il tuo codice OTP');
$otp = (string) ($otp ?? '');
$otpSecurityNote = (string) ($otpSecurityNote ?? '');
$otpValidityNote = (string) ($otpValidityNote ?? '');
$footerNote = (string) ($footerNote ?? '');
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($brandName) ?> - OTP</title>
</head>
<body style="margin:0; padding:24px; background:#f4f6fb; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #dbe2ea; border-radius:18px; overflow:hidden;">
        <div style="padding:24px 28px; background:#0f172a; color:#ffffff;">
            <div style="font-size:13px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.8;"><?= esc($brandName) ?></div>
            <div style="margin-top:8px; font-size:24px; font-weight:700;"><?= esc($notificationTitle) ?></div>
        </div>

        <div style="padding:28px;">
            <p style="margin:0 0 16px; font-size:16px; line-height:1.6;"><?= esc($greeting) ?></p>

            <?php if ($notificationMessage !== ''): ?>
                <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#374151;"><?= esc($notificationMessage) ?></p>
            <?php endif; ?>

            <div style="margin:0 0 22px; padding:20px; border-radius:16px; background:#eff6ff; border:1px solid #bfdbfe; text-align:center;">
                <div style="font-size:12px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#1d4ed8;"><?= esc($otpLabel) ?></div>
                <div style="margin-top:10px; font-size:34px; font-weight:700; letter-spacing:0.22em; color:#0f172a;"><?= esc($otp) ?></div>
            </div>

            <p style="margin:0 0 10px; font-size:14px; line-height:1.6; color:#374151;"><?= esc($otpSecurityNote) ?></p>
            <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#374151;"><?= esc($otpValidityNote) ?></p>

            <?php if ($ctaCaption !== ''): ?>
                <p style="margin:0 0 18px; font-size:14px; line-height:1.6; color:#475569;"><?= esc($ctaCaption) ?></p>
            <?php endif; ?>

            <p style="margin:0; font-size:13px; line-height:1.6; color:#64748b;"><?= esc($footerNote) ?></p>
        </div>
    </div>
</body>
</html>
