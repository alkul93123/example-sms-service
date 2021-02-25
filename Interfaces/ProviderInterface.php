<?php


namespace App\Services\Sms\Interfaces;


interface ProviderInterface
{
    public function setNumbers(array $numbers) : ProviderInterface;
    public function setMessage(string $message) : ProviderInterface;
    public function send();
}