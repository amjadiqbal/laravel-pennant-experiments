<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Support;

final class Format
{
    public static function percent(?float $value, int $decimals = 2): string
    {
        return $value === null ? '–' : number_format($value * 100, $decimals).'%';
    }

    /** A probability, never rounded to a certainty it isn't: "> 99.9%" / "< 0.1%" at the ends. */
    public static function probability(?float $value): string
    {
        return match (true) {
            $value === null => '–',
            $value > 0.999 => '> 99.9%',
            $value < 0.001 => '< 0.1%',
            default => number_format($value * 100, 1).'%',
        };
    }

    public static function signedPercent(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '–';
        }

        return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value) * 100, $decimals).'%';
    }

    /**
     * A difference of rates in percentage points, e.g. [+0.08, +1.70] pp.
     *
     * @param  array{0: float, 1: float}|null  $ci
     */
    public static function interval(?array $ci): string
    {
        if ($ci === null) {
            return '–';
        }

        // A difference of two proportions lies in [-1, 1]. The Wald interval can overshoot that at
        // tiny samples; the displayed bound is capped and marked, the raw value is left in the results.
        $bound = fn (float $value): string => $value > 1.0 ? self::points(1.0).' (capped)'
            : ($value < -1.0 ? self::points(-1.0).' (capped)' : self::points($value));

        return '['.$bound($ci[0]).', '.$bound($ci[1]).'] pp';
    }

    public static function points(float $value): string
    {
        return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value) * 100, 2);
    }

    public static function pValue(?float $p): string
    {
        if ($p === null) {
            return '–';
        }

        return $p < 0.0001 ? '< 0.0001' : number_format($p, 4);
    }

    public static function number(float $value, int $decimals = 2): string
    {
        $formatted = number_format($value, $decimals);

        // Only trim decimals: 80 stays "80", 0.0250 becomes "0.025".
        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
