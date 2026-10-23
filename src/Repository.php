<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * The package's three tables, through the query builder (no models, so no global scopes or events).
 */
final class Repository
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function connection(): ConnectionInterface
    {
        return $this->db->connection(config('pennant-experiments.connection'));
    }

    public function experiments(): Builder
    {
        return $this->connection()->table((string) config('pennant-experiments.tables.experiments', 'pennant_experiments'));
    }

    public function exposures(): Builder
    {
        return $this->connection()->table((string) config('pennant-experiments.tables.exposures', 'pennant_experiment_exposures'));
    }

    public function conversions(): Builder
    {
        return $this->connection()->table((string) config('pennant-experiments.tables.conversions', 'pennant_experiment_conversions'));
    }

    /** @return array{status: string, winner: ?string, started_at: ?string, ended_at: ?string, ended_by: ?string}|null */
    public function state(string $experiment): ?array
    {
        $row = $this->experiments()->where('name', $experiment)->first();

        return $row === null ? null : [
            'status' => (string) $row->status,
            'winner' => $row->winner === null ? null : (string) $row->winner,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'ended_at' => $row->ended_at === null ? null : (string) $row->ended_at,
            'ended_by' => $row->ended_by === null ? null : (string) $row->ended_by,
        ];
    }

    /** @return array<string, array{status: string, winner: ?string, started_at: ?string, ended_at: ?string, ended_by: ?string}> */
    public function allStates(): array
    {
        $states = [];
        foreach ($this->experiments()->orderBy('name')->get() as $row) {
            $states[(string) $row->name] = [
                'status' => (string) $row->status,
                'winner' => $row->winner === null ? null : (string) $row->winner,
                'started_at' => $row->started_at === null ? null : (string) $row->started_at,
                'ended_at' => $row->ended_at === null ? null : (string) $row->ended_at,
                'ended_by' => $row->ended_by === null ? null : (string) $row->ended_by,
            ];
        }

        return $states;
    }

    /** Insert the run-state row once; started_at is the first exposure. */
    public function touch(string $experiment): void
    {
        $now = Carbon::now();
        $this->experiments()->insertOrIgnore([
            'name' => $experiment,
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function end(string $experiment, string $winner, ?string $by): void
    {
        $this->touch($experiment);
        $now = Carbon::now();
        $this->experiments()->where('name', $experiment)->update([
            'status' => 'ended',
            'winner' => $winner,
            'ended_at' => $now,
            'ended_by' => $by,
            'updated_at' => $now,
        ]);
    }

    /** Delete the experiment's data and run it again from now. */
    public function reset(string $experiment): void
    {
        $this->connection()->transaction(function () use ($experiment): void {
            $this->conversions()->where('experiment', $experiment)->delete();
            $this->exposures()->where('experiment', $experiment)->delete();
            $this->touch($experiment);
            $now = Carbon::now();
            $this->experiments()->where('name', $experiment)->update([
                'status' => 'running',
                'winner' => null,
                'started_at' => $now,
                'ended_at' => null,
                'ended_by' => null,
                'updated_at' => $now,
            ]);
        });
    }

    /** True when this call recorded the exposure (false: the scope was already exposed). */
    public function recordExposure(string $experiment, string $variant, string $scope): bool
    {
        return $this->exposures()->insertOrIgnore([
            'experiment' => $experiment,
            'variant' => $variant,
            'scope' => $scope,
            'exposed_at' => Carbon::now(),
        ]) > 0;
    }

    public function exposedVariant(string $experiment, string $scope): ?string
    {
        $variant = $this->exposures()->where('experiment', $experiment)->where('scope', $scope)->value('variant');

        return $variant === null ? null : (string) $variant;
    }

    public function recordConversion(string $experiment, string $goal, string $variant, string $scope): bool
    {
        return $this->conversions()->insertOrIgnore([
            'experiment' => $experiment,
            'goal' => $goal,
            'variant' => $variant,
            'scope' => $scope,
            'converted_at' => Carbon::now(),
        ]) > 0;
    }

    /** @return array<string, int> variant => exposed scopes */
    public function exposureCounts(string $experiment): array
    {
        return $this->exposures()
            ->where('experiment', $experiment)
            ->selectRaw('variant, count(*) as aggregate')
            ->groupBy('variant')
            ->pluck('aggregate', 'variant')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /** @return array<string, array<string, int>> goal => variant => converted scopes */
    public function conversionCounts(string $experiment): array
    {
        $counts = [];
        $rows = $this->conversions()
            ->where('experiment', $experiment)
            ->selectRaw('goal, variant, count(*) as aggregate')
            ->groupBy('goal', 'variant')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->goal][(string) $row->variant] = (int) $row->aggregate;
        }

        return $counts;
    }

    public function firstExposureAt(string $experiment): ?string
    {
        $at = $this->exposures()->where('experiment', $experiment)->min('exposed_at');

        return $at === null ? null : (string) $at;
    }

    /**
     * Move a guest's rows to the user they signed in as, for experiments where the user has no exposure yet.
     *
     * @return array<string, string> experiment => variant that was moved
     */
    public function moveScope(string $from, string $to): array
    {
        $moved = [];

        $this->connection()->transaction(function () use ($from, $to, &$moved): void {
            $rows = $this->exposures()->where('scope', $from)->get(['experiment', 'variant']);
            foreach ($rows as $row) {
                $experiment = (string) $row->experiment;
                if ($this->exposures()->where('experiment', $experiment)->where('scope', $to)->exists()) {
                    continue;
                }
                $this->exposures()->where('experiment', $experiment)->where('scope', $from)->update(['scope' => $to]);
                // Goals the user already converted on keep the user's row; the guest's duplicate is dropped.
                $taken = $this->conversions()->where('experiment', $experiment)->where('scope', $to)->pluck('goal')->all();
                $this->conversions()->where('experiment', $experiment)->where('scope', $from)->whereIn('goal', $taken)->delete();
                $this->conversions()->where('experiment', $experiment)->where('scope', $from)->update(['scope' => $to]);
                $moved[$experiment] = (string) $row->variant;
            }
        });

        return $moved;
    }
}
