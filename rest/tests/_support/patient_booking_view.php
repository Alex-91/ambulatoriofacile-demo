<?php
require __DIR__.'/whatsapp_booking_bootstrap.php';
helper(['url','form']);
$bookingBootstrap=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
echo view('patient_booking/index',compact('bookingBootstrap'));
