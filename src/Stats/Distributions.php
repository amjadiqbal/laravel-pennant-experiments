<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Stats;

use InvalidArgumentException;

/**
 * The few special functions the tests need, in plain PHP (no extension, no network).
 *
 * - erfc(): W. J. Cody's rational Chebyshev approximations (Math. Comp. 23, 1969),
 *   relative error below 1e-15 in double precision, so p-values are exact to print precision.
 * - normalQuantile(): P. J. Acklam's algorithm (relative error 1.15e-9) refined with one
 *   Halley step against normalCdf(), which brings it to full double precision.
 * - logGamma(): Lanczos approximation, g = 7, n = 9 (as in Numerical Recipes, 3rd ed., 6.1),
 *   with the reflection formula below 0.5.
 */
final class Distributions
{
    /** Standard normal cumulative distribution function, P(Z <= z). */
    public static function normalCdf(float $z): float
    {
        return 0.5 * self::erfc(-$z / M_SQRT2);
    }

    /** Upper tail P(Z > z), computed directly so tiny tails keep their precision. */
    public static function normalSf(float $z): float
    {
        return 0.5 * self::erfc($z / M_SQRT2);
    }

    /** Two-sided p-value for a z statistic: P(|Z| >= |z|). */
    public static function twoSidedP(float $z): float
    {
        return min(1.0, self::erfc(abs($z) / M_SQRT2));
    }

    public static function erfc(float $x): float
    {
        $ax = abs($x);

        if ($ax < 0.5) {
            return 1.0 - self::erfSmall($x);
        }

        $value = $ax < 4.0 ? self::erfcMiddle($ax) : self::erfcLarge($ax);

        return $x < 0 ? 2.0 - $value : $value;
    }

    public static function erf(float $x): float
    {
        return abs($x) < 0.5 ? self::erfSmall($x) : 1.0 - self::erfc($x);
    }

    /** Inverse of normalCdf(): the z with P(Z <= z) = p. */
    public static function normalQuantile(float $p): float
    {
        if (! ($p > 0.0 && $p < 1.0)) {
            throw new InvalidArgumentException('normalQuantile() needs 0 < p < 1.');
        }

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $low = 0.02425;

        if ($p < $low) {
            $q = sqrt(-2 * log($p));
            $x = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        } elseif ($p <= 1 - $low) {
            $q = $p - 0.5;
            $r = $q * $q;
            $x = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        } else {
            $q = sqrt(-2 * log(1 - $p));
            $x = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        // One Halley refinement step.
        $e = self::normalCdf($x) - $p;
        $u = $e * sqrt(2 * M_PI) * exp($x * $x / 2);

        return $x - $u / (1 + $x * $u / 2);
    }

    public static function logGamma(float $x): float
    {
        if ($x <= 0.0 && floor($x) === $x) {
            throw new InvalidArgumentException('logGamma() is undefined at zero and negative integers.');
        }

        if ($x < 0.5) {
            // Reflection: Gamma(x) Gamma(1 - x) = pi / sin(pi x).
            return log(M_PI / abs(sin(M_PI * $x))) - self::logGamma(1.0 - $x);
        }

        $g = 7.0;
        $coefficients = [
            0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313,
            -176.61502916214059, 12.507343278686905, -0.13857109526572012,
            9.9843695780195716e-6, 1.5056327351493116e-7,
        ];

        $x -= 1.0;
        $sum = $coefficients[0];
        for ($i = 1; $i < 9; $i++) {
            $sum += $coefficients[$i] / ($x + $i);
        }
        $t = $x + $g + 0.5;

        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($sum);
    }

    /** ln B(a, b) = ln Gamma(a) + ln Gamma(b) - ln Gamma(a + b). */
    public static function logBeta(float $a, float $b): float
    {
        return self::logGamma($a) + self::logGamma($b) - self::logGamma($a + $b);
    }

    // Cody's erf for |x| < 0.5.
    private static function erfSmall(float $x): float
    {
        $a = [3.16112374387056560e00, 1.13864154151050156e02, 3.77485237685302021e02, 3.20937758913846947e03, 1.85777706184603153e-1];
        $b = [2.36012909523441209e01, 2.44024637934444173e02, 1.28261652607737228e03, 2.84423683343917062e03];
        $z = $x * $x;
        $num = $a[4] * $z;
        $den = $z;
        for ($i = 0; $i < 3; $i++) {
            $num = ($num + $a[$i]) * $z;
            $den = ($den + $b[$i]) * $z;
        }

        return $x * ($num + $a[3]) / ($den + $b[3]);
    }

    // Cody's erfc for 0.5 <= x < 4.
    private static function erfcMiddle(float $x): float
    {
        $c = [5.64188496988670089e-1, 8.88314979438837594e00, 6.61191906371416295e01, 2.98635138197400131e02, 8.81952221241769090e02, 1.71204761263407058e03, 2.05107837782607147e03, 1.23033935479799725e03, 2.15311535474403846e-8];
        $d = [1.57449261107098347e01, 1.17693950891312499e02, 5.37181101862009858e02, 1.62138957456669019e03, 3.29079923573345963e03, 4.36261909014324716e03, 3.43936767414372164e03, 1.23033935480374942e03];
        $num = $c[8] * $x;
        $den = $x;
        for ($i = 0; $i < 7; $i++) {
            $num = ($num + $c[$i]) * $x;
            $den = ($den + $d[$i]) * $x;
        }

        return exp(-$x * $x) * ($num + $c[7]) / ($den + $d[7]);
    }

    // Cody's erfc for x >= 4.
    private static function erfcLarge(float $x): float
    {
        $p = [3.05326634961232344e-1, 3.60344899949804439e-1, 1.25781726111229246e-1, 1.60837851487422766e-2, 6.58749161529837803e-4, 1.63153871373020978e-2];
        $q = [2.56852019228982242e00, 1.87295284992346725e00, 5.27905102951428412e-1, 6.05183413124413191e-2, 2.33520497626869185e-3];
        if ($x >= 26.6) {
            return 0.0;
        }
        $z = 1.0 / ($x * $x);
        $num = $p[5] * $z;
        $den = $z;
        for ($i = 0; $i < 4; $i++) {
            $num = ($num + $p[$i]) * $z;
            $den = ($den + $q[$i]) * $z;
        }
        $r = $z * ($num + $p[4]) / ($den + $q[4]);
        $r = (1 / sqrt(M_PI) - $r) / $x;

        return exp(-$x * $x) * $r;
    }
}
