<?php


namespace App\Services\Sms\Interfaces;


interface SanitizerInterface
{
    public function sanitizeNumber(string $number) : string;
}