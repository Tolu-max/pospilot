<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function add(string|int|float $left, string|int|float $right): string
    {
        return self::decimal($left)->plus(self::decimal($right))->toScale(2)->__toString();
    }

    public static function subtract(string|int|float $left, string|int|float $right): string
    {
        return self::decimal($left)->minus(self::decimal($right))->toScale(2)->__toString();
    }

    public static function multiply(string|int|float $left, string|int|float $right): string
    {
        return self::decimal($left)->multipliedBy(self::decimal($right))->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    public static function percentage(string|int|float $amount, string|int|float $percentage): string
    {
        return self::decimal($amount)->multipliedBy(self::decimal($percentage))->dividedBy(100, 2, RoundingMode::HalfUp)->__toString();
    }

    public static function compare(string|int|float $left, string|int|float $right): int
    {
        return self::decimal($left)->compareTo(self::decimal($right));
    }

    private static function decimal(string|int|float $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }
}
