<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Console;

use AmjadIqbal\PennantExperiments\ExperimentManager;
use Illuminate\Console\Command;

final class EndCommand extends Command
{
    protected $signature = 'pennant-experiments:end {experiment} {--winner= : The variant everyone gets from now on} {--force : Skip the confirmation}';

    protected $description = 'End an A/B experiment and roll out the winning variant to everyone';

    public function handle(ExperimentManager $experiments): int
    {
        $name = (string) $this->argument('experiment');
        if (! $experiments->has($name)) {
            $this->error("Unknown experiment [{$name}].");

            return self::FAILURE;
        }
        if (! $experiments->isRunning($name)) {
            $this->error("[{$name}] has already ended (winner: {$experiments->winner($name)}).");

            return self::FAILURE;
        }

        $definition = $experiments->definition($name);
        $winner = $this->option('winner');
        if (! is_string($winner) || ! $definition->hasVariant($winner)) {
            $this->error('Pass --winner= with one of: '.implode(', ', $definition->variants()).'.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("End [{$name}] and give everyone \"{$winner}\"?", true)) {
            return self::FAILURE;
        }

        $experiments->end($name, $winner, 'console');
        $this->info("Ended [{$name}]. Everyone now gets \"{$winner}\"; stored Pennant values were updated and nothing more is recorded.");

        return self::SUCCESS;
    }
}
