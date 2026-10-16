<?php

namespace App\Support;

class ServiceFee
{
    // DECIMAL(10,2): plain non-negative pesos, at most eight whole digits.
    public const PATTERN = '/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/';

    public static function format(?string $amount, string $unknown = 'Not recorded'): string
    {
        if ($amount === null) {
            return $unknown;
        }
        [$pesos, $centavos] = array_pad(explode('.', $amount, 2), 2, '00');

        return '₱'.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $pesos).'.'.str_pad($centavos, 2, '0');
    }
}
