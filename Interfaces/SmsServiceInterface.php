<?php

namespace App\Services\Sms\Interfaces;

interface SmsServiceInterface
{
    public function push(array $numbers, string $message);
}