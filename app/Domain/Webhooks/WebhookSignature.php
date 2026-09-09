<?php

namespace App\Domain\Webhooks;

class WebhookSignature
{
    public static function header(string $secret, string $body, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret));
    }

    public static function verify(string $secret, string $body, string $header): bool
    {
        if ( ! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $matches)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $matches[1].'.'.$body, $secret), $matches[2]);
    }
}
