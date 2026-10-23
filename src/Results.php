<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments;

use AmjadIqbal\PennantExperiments\Stats\Statistics;

/**
 * Results of one experiment: per goal, each variant against the control.
 *
 * Verdicts follow the planned-sample rule: a z-test result is only called significant once every
 * variant reached the planned sample size (set explicitly, or derived from the minimum detectable
 * effect and the control's rate). Before that the numbers are shown, but labelled as provisional,
 * because stopping at the first significant peek inflates false positives.
 */
final class Results
{
    public const TOO_EARLY = 'too_early';

    public const KEEP_RUNNING = 'keep_running';

    public const BETTER = 'better';

    public const WORSE = 'worse';

    public const NO_DIFFERENCE = 'no_difference';

    public const CONTROL = 'control';

    /**
     * @param  array<string, int>  $exposures  variant => exposed scopes
     * @param  array<string, array<string, int>>  $conversions  goal => variant => converted scopes
     */
    public function __construct(
        public readonly Definition $definition,
        public readonly array $exposures,
        public readonly array $conversions,
    ) {}

    public function totalExposed(): int
    {
        return array_sum($this->exposures);
    }

    public function exposed(string $variant): int
    {
        return $this->exposures[$variant] ?? 0;
    }

    public function converted(string $goal, string $variant): int
    {
        return min($this->conversions[$goal][$variant] ?? 0, $this->exposed($variant));
    }

    /** Per-comparison alpha: the configured alpha split across the challengers (Bonferroni). */
    public function alpha(): float
    {
        $challengers = max(1, count($this->definition->weights) - 1);

        return (float) config('pennant-experiments.statistics.alpha', 0.05) / $challengers;
    }

    /**
     * Planned exposures per variant for a goal, or null when neither a sample size nor a
     * minimum detectable effect is set (or the control has no conversions yet to base it on).
     */
    public function plannedSample(string $goal): ?int
    {
        if ($this->definition->sampleSize !== null) {
            return $this->definition->sampleSize;
        }
        if ($this->definition->minimumDetectableEffect === null) {
            return null;
        }

        $control = $this->definition->control();
        $n = $this->exposed($control);
        if ($n === 0 || $this->converted($goal, $control) === 0) {
            return null;
        }

        return Statistics::sampleSizePerGroup(
            $this->converted($goal, $control) / $n,
            $this->definition->minimumDetectableEffect,
            $this->alpha(),
            (float) config('pennant-experiments.statistics.power', 0.8),
        );
    }

    /** Every variant has the configured minimum exposures and conversions for this goal. */
    public function hasMinimumData(string $goal): bool
    {
        $minExposures = (int) config('pennant-experiments.statistics.min_exposures', 100);
        $minConversions = (int) config('pennant-experiments.statistics.min_conversions', 10);

        foreach ($this->definition->variants() as $variant) {
            if ($this->exposed($variant) < $minExposures || $this->converted($goal, $variant) < $minConversions) {
                return false;
            }
        }

        return true;
    }

    public function reachedPlan(string $goal): bool
    {
        $planned = $this->plannedSample($goal);
        if ($planned === null) {
            return false;
        }
        foreach ($this->definition->variants() as $variant) {
            if ($this->exposed($variant) < $planned) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{variant: string, control: bool, exposed: int, converted: int, rate: float|null, lift: float|null, diff: float|null, ci: array{0: float, 1: float}|null, z: float|null, p: float|null, significant: bool, probability: float|null, verdict: string}>
     */
    public function rows(string $goal): array
    {
        $control = $this->definition->control();
        $n1 = $this->exposed($control);
        $x1 = $this->converted($goal, $control);
        $alpha = $this->alpha();
        $enough = $this->hasMinimumData($goal);
        $planned = $this->plannedSample($goal) !== null;
        $reached = $this->reachedPlan($goal);

        $rows = [];
        foreach ($this->definition->variants() as $variant) {
            $n = $this->exposed($variant);
            $x = $this->converted($goal, $variant);
            $row = [
                'variant' => $variant,
                'control' => $variant === $control,
                'exposed' => $n,
                'converted' => $x,
                'rate' => $n > 0 ? $x / $n : null,
                'lift' => null, 'diff' => null, 'ci' => null, 'z' => null, 'p' => null,
                'significant' => false,
                'probability' => null,
                'verdict' => self::CONTROL,
            ];

            if ($variant !== $control) {
                if ($n > 0 && $n1 > 0) {
                    $test = Statistics::zTest($x1, $n1, $x, $n, 1 - $alpha);
                    $row['lift'] = $test['lift'];
                    $row['diff'] = $test['diff'];
                    $row['ci'] = $test['ci'];
                    $row['z'] = $test['z'];
                    $row['p'] = $test['p'];
                    $row['significant'] = $test['p'] !== null && $test['p'] < $alpha;
                    $row['probability'] = Statistics::probabilityToBeat($x1, $n1, $x, $n);
                }

                $row['verdict'] = match (true) {
                    ! $enough || $row['p'] === null => self::TOO_EARLY,
                    $planned && ! $reached => self::KEEP_RUNNING,
                    $row['significant'] => $row['diff'] > 0 ? self::BETTER : self::WORSE,
                    default => self::NO_DIFFERENCE,
                };
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public static function verdictLabel(string $verdict, bool $planned): string
    {
        return match ($verdict) {
            self::TOO_EARLY => 'Too early',
            self::KEEP_RUNNING => 'Keep running',
            self::BETTER => $planned ? 'Better than control' : 'Better (no planned sample)',
            self::WORSE => $planned ? 'Worse than control' : 'Worse (no planned sample)',
            self::NO_DIFFERENCE => 'No significant difference',
            default => 'Control',
        };
    }
}
