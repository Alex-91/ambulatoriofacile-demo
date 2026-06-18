<?php

namespace App\Controllers;

class OtpController extends BaseController
{
    public function show()
    {
        $code = $this->request->getGet('code');

        if (!$code) {
            return view('otp/error', [
                'message' => 'Codice OTP non valido o mancante.'
            ]);
        }

        return view('otp/show', ['code' => $code]);
    }
}
