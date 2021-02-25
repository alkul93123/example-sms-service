<?php

namespace App\Services\Sms\Core;

use App\Services\Sms\Interfaces\SanitizerInterface;

class BaseSanitizer implements SanitizerInterface
{

    public function sanitizeNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number);
    }
}