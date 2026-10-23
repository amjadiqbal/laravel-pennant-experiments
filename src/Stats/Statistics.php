<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Stats;

use InvalidArgumentException;

/**
 * Comparisons of two conversion rates (converters / exposed), variant against control.
 */
final class Statistics
{
    /**
     * Two-proportion z-test with the pooled standard error under H0 (p1 = p2), two-sided,
     * plus a Wald confidence interval for the difference (unpooled standard error).
     *
     * @return array{z: float|null, p: float|null, diff: float, ci: array{0: float, 1: float}|null, lift: float|null}
     */
    public static function zTest(int $controlConversions, int $controlExposed, int $variantConversions, int $variantExposed, float $confidence = 0.95): array
    {
        self::guard($controlConversions, $controlExposed);
        self::guard($variantConversions, $variantExposed);

        if ($controlExposed === 0 || $variantExposed === 0) {
            return ['z' => null, 'p' => null, 'diff' => 0.0, 'ci' => null, 'lift' => null];
        }

        $p1 = $controlConversions / $controlExposed;
        $p2 = $variantConversions / $variantExposed;
        $diff = $p2 - $p1;
        $lift = $p1 > 0 ? $diff / $p1 : null;

        $pooled = ($controlConversions + $variantConversions) / ($controlExposed + $variantExposed);
        $sePooled = sqrt($pooled * (1 - $pooled) * (1 / $controlExposed + 1 / $variantExposed));

        $seDiff = sqrt($p1 * (1 - $p1) / $controlExposed + $p2 * (1 - $p2) / $variantExposed);
        $zCrit = Distributions::normalQuantile(1 - (1 - $confidence) / 2);
        $ci = [$diff - $zCrit * $seDiff, $diff + $zCrit * $seDiff];

        if ($sePooled == 0.0) {
            // Both groups at 0% or both at 100%: no evidence of any difference.
            return ['z' => 0.0, 'p' => 1.0, 'diff' => $diff, 'ci' => $ci, 'lift' => $lift];
        }

        $z = $diff / $sePooled;

        return ['z' => $z, 'p' => Distributions::twoSidedP($z), 'diff' => $diff, 'ci' => $ci, 'lift' => $lift];
    }

    /**
     * P(rate_variant > rate_control) with independent Beta(1 + conversions, 1 + misses) posteriors
     * (uniform prior). Exact closed form from Evan Miller, "Formulas for Bayesian A/B Testing" (2015):
     *
     *   P = sum_{i=0}^{aB-1} B(aA + i, bA + bB) / ((bB + i) B(1 + i, bB) B(aA, bA))
     *
     * The sum has aB terms (variant conversions + 1). Above $exactLimit terms it switches to the
     * normal approximation of the two Beta posteriors, which is very accurate at that size.
     */
    public static function probabilityToBeat(int $controlConversions, int $controlExposed, int $variantConversions, int $variantExposed, int $exactLimit = 20000): float
    {
        self::guard($controlConversions, $controlExposed);
        self::guard($variantConversions, $variantExposed);

        $aA = 1.0 + $controlConversions;
        $bA = 1.0 + $controlExposed - $controlConversions;
        $aB = 1.0 + $variantConversions;
        $bB = 1.0 + $variantExposed - $variantConversions;

        if ($aB > $exactLimit) {
            return self::probabilityToBeatApprox($aA, $bA, $aB, $bB);
        }

        $constant = -Distributions::logBeta($aA, $bA);
        $total = 0.0;
        for ($i = 0; $i < (int) $aB; $i++) {
            $total += exp(
                Distributions::logBeta($aA + $i, $bA + $bB)
                - log($bB + $i)
                - Distributions::logBeta(1.0 + $i, $bB)
                + $constant
            );
        }

        return max(0.0, min(1.0, $total));
    }

    /** Normal approximation of P(X_B > X_A) for X ~ Beta(a, b). */
    public static function probabilityToBeatApprox(float $aA, float $bA, float $aB, float $bB): float
    {
        $mean = fn (float $a, float $b): float => $a / ($a + $b);
        $var = fn (float $a, float $b): float => $a * $b / (($a + $b) ** 2 * ($a + $b + 1));
        $sd = sqrt($var($aA, $bA) + $var($aB, $bB));

        return Distributions::normalCdf(($mean($aB, $bB) - $mean($aA, $bA)) / $sd);
    }

    /**
     * Visitors needed in EACH group to detect a change from $baseline to $baseline * (1 + $relativeLift)
     * with a two-sided test at $alpha and the given power (Fleiss, "Statistical Methods for Rates
     * and Proportions", formula without continuity correction):
     *
     *   n = (z_{1-a/2} sqrt(2 pbar qbar) + z_{1-b} sqrt(p1 q1 + p2 q2))^2 / (p2 - p1)^2
     */
    public static function sampleSizePerGroup(float $baseline, float $relativeLift, float $alpha = 0.05, float $power = 0.8): ?int
    {
        $p1 = $baseline;
        $p2 = $baseline * (1 + $relativeLift);

        if ($p1 <= 0 || $p1 >= 1 || $p2 <= 0 || $p2 >= 1 || $p1 == $p2) {
            return null;
        }

        $zAlpha = Distributions::normalQuantile(1 - $alpha / 2);
        $zBeta = Distributions::normalQuantile($power);
        $pBar = ($p1 + $p2) / 2;

        $n = ($zAlpha * sqrt(2 * $pBar * (1 - $pBar)) + $zBeta * sqrt($p1 * (1 - $p1) + $p2 * (1 - $p2))) ** 2 / ($p2 - $p1) ** 2;

        return (int) ceil($n - 1e-9);
    }

    /**
     * Wilson score interval for one conversion rate (better than the Wald interval near 0% and
     * for small samples).
     *
     * @return array{0: float, 1: float}|null
     */
    public static function wilson(int $conversions, int $exposed, float $confidence = 0.95): ?array
    {
        self::guard($conversions, $exposed);
        if ($exposed === 0) {
            return null;
        }

        $z = Distributions::normalQuantile(1 - (1 - $confidence) / 2);
        $p = $conversions / $exposed;
        $z2n = $z * $z / $exposed;
        $center = ($p + $z2n / 2) / (1 + $z2n);
        $half = $z * sqrt($p * (1 - $p) / $exposed + $z2n / (4 * $exposed)) / (1 + $z2n);

        return [max(0.0, $center - $half), min(1.0, $center + $half)];
    }

    private static function guard(int $conversions, int $exposed): void
    {
        if ($exposed < 0 || $conversions < 0 || $conversions > $exposed) {
            throw new InvalidArgumentException("Conversions must be between 0 and the exposed count (got {$conversions} of {$exposed}).");
        }
    }
}
