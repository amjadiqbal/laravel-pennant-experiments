<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Console;

use AmjadIqbal\PennantExperiments\ExperimentManager;
use AmjadIqbal\PennantExperiments\Results;
use AmjadIqbal\PennantExperiments\Support\Format;
use Illuminate\Console\Command;

final class ResultsCommand extends Command
{
    protected $signature = 'pennant-experiments:results {experiment? : Leave out to list every experiment}';

    protected $description = 'Show A/B experiment results: exposures, conversion rates, z-test and probability to beat control';

    public function handle(ExperimentManager $experiments): int
    {
        $name = $this->argument('experiment');

        if (! is_string($name)) {
            $rows = [];
            foreach ($experiments->definitions() as $experiment => $definition) {
                $rows[] = [$experiment, $experiments->winner($experiment) === null ? 'running' : 'ended → '.$experiments->winner($experiment), implode(', ', $definition->variants()), implode(', ', $definition->goals), $experiments->results($experiment)->totalExposed()];
            }
            $this->table(['Experiment', 'Status', 'Variants', 'Goals', 'Exposed'], $rows);

            return self::SUCCESS;
        }

        if (! $experiments->has($name)) {
            $this->error("Unknown experiment [{$name}].");

            return self::FAILURE;
        }

        $results = $experiments->results($name);
        $winner = $experiments->winner($name);
        $this->line("<options=bold>{$name}</>".($winner !== null ? "  ended, winner: {$winner}" : '  running'));
        $this->line(sprintf('alpha per comparison: %s (Bonferroni over %d challenger(s))', Format::number($results->alpha(), 4), max(1, count($results->definition->weights) - 1)));

        foreach ($results->definition->goals as $goal) {
            $planned = $results->plannedSample($goal);
            $this->newLine();
            $this->line("<options=bold>Goal: {$goal}</>".($planned !== null ? "  planned sample: {$planned} per variant" : '  no planned sample size'));
            $rows = [];
            foreach ($results->rows($goal) as $row) {
                $rows[] = [
                    $row['variant'].($row['control'] ? ' (control)' : ''),
                    $row['exposed'],
                    $row['converted'],
                    Format::percent($row['rate']),
                    $row['control'] ? '' : Format::signedPercent($row['lift']),
                    $row['control'] ? '' : Format::interval($row['ci']),
                    $row['control'] ? '' : Format::pValue($row['p']),
                    $row['control'] ? '' : Format::percent($row['probability'], 1),
                    Results::verdictLabel($row['verdict'], $planned !== null),
                ];
            }
            $this->table(['Variant', 'Exposed', 'Converted', 'Rate', 'Lift', 'CI of difference', 'p-value', 'P(beats control)', 'Verdict'], $rows);
            if (! $results->hasMinimumData($goal)) {
                $this->warn(sprintf('Not enough data yet: every variant needs %d exposures and %d conversions.', (int) config('pennant-experiments.statistics.min_exposures'), (int) config('pennant-experiments.statistics.min_conversions')));
            }
        }

        return self::SUCCESS;
    }
}
