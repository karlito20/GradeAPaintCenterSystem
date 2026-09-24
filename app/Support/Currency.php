<?php

namespace App\Support;

final class Currency
{
    public static function format(int|string|float $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }
}
