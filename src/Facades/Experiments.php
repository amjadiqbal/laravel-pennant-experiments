<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Facades;

use AmjadIqbal\PennantExperiments\ExperimentManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AmjadIqbal\PennantExperiments\Definition define(string $name, array<int|string, string|int> $variants, array<string, mixed> $options = [])
 * @method static string variant(string $name, mixed $scope = null)
 * @method static string expose(string $name, mixed $scope = null)
 * @method static bool convert(string $name, string $goal = 'conversion', mixed $scope = null)
 * @method static void end(string $name, string $winner, ?string $by = null)
 * @method static void restart(string $name)
 * @method static \AmjadIqbal\PennantExperiments\Results results(string $name)
 * @method static string|null winner(string $name)
 * @method static bool isRunning(string $name)
 * @method static mixed participant()
 * @method static \AmjadIqbal\PennantExperiments\Visitor visitor()
 * @method static void resolveScopeUsing(\Closure $resolver)
 * @method static array<string, \AmjadIqbal\PennantExperiments\Definition> definitions()
 *
 * @see ExperimentManager
 */
final class Experiments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ExperimentManager::class;
    }
}
