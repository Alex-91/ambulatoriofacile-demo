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
echo $brandName . "\n";
echo $notificationTitle . "\n\n";
echo $greeting . "\n\n";

if ($notificationMessage !== '') {
    echo $notificationMessage . "\n\n";
}

echo $otpLabel . ': ' . $otp . "\n";

if ($otpSecurityNote !== '') {
    echo $otpSecurityNote . "\n";
}

if ($otpValidityNote !== '') {
    echo $otpValidityNote . "\n";
}

echo "\n";

if ($ctaCaption !== '') {
    echo $ctaCaption . "\n\n";
}

echo $footerNote . "\n";
