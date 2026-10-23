<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Http\Controllers;

use AmjadIqbal\PennantExperiments\ExperimentManager;
use AmjadIqbal\PennantExperiments\Http\Authorize;
use AmjadIqbal\PennantExperiments\PennantExperimentsServiceProvider;
use AmjadIqbal\PennantExperiments\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class ResultsController
{
    public function __construct(
        private readonly ExperimentManager $experiments,
        private readonly Repository $repository,
    ) {}

    public function index(): View
    {
        $states = $this->repository->allStates();
        $rows = [];

        foreach ($this->experiments->definitions() as $name => $definition) {
            $results = $this->experiments->results($name);
            $rows[] = [
                'name' => $name,
                'definition' => $definition,
                'state' => $states[$name] ?? null,
                'exposed' => $results->totalExposed(),
                'results' => $results,
            ];
        }

        // Experiments with recorded data whose definition was removed from code.
        $orphans = array_diff_key($states, $this->experiments->definitions());

        return view('pennant-experiments::index', $this->shared() + ['rows' => $rows, 'orphans' => $orphans]);
    }

    public function show(string $experiment): View
    {
        abort_unless($this->experiments->has($experiment), 404);

        return view('pennant-experiments::show', $this->shared() + [
            'definition' => $this->experiments->definition($experiment),
            'state' => $this->experiments->state($experiment),
            'results' => $this->experiments->results($experiment),
            'firstExposure' => $this->repository->firstExposureAt($experiment),
        ]);
    }

    public function end(Request $request, string $experiment): RedirectResponse
    {
        abort_unless($this->experiments->has($experiment), 404);
        $definition = $this->experiments->definition($experiment);
        $winner = (string) $request->input('winner');

        if (! $definition->hasVariant($winner)) {
            return back()->withErrors(['winner' => 'Pick one of the variants as the winner.']);
        }
        if (! $this->experiments->isRunning($experiment)) {
            return back()->withErrors(['winner' => 'This experiment has already ended.']);
        }

        $this->experiments->end($experiment, $winner, $this->actor($request));

        return redirect()->route('pennant-experiments.show', $experiment)
            ->with('pennant-experiments.status', "Ended. Everyone now gets \"{$winner}\", and nothing more is recorded.");
    }

    public function restart(Request $request, string $experiment): RedirectResponse
    {
        abort_unless($this->experiments->has($experiment), 404);
        $this->experiments->restart($experiment);

        return redirect()->route('pennant-experiments.show', $experiment)
            ->with('pennant-experiments.status', 'Running again. Stored values from the rollout were removed, so everyone is assigned afresh.');
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'canManage' => Authorize::canManage(),
            'version' => PennantExperimentsServiceProvider::version(),
            'dashboard' => Route::has('pennant-dashboard.index'),
        ];
    }

    private function actor(Request $request): string
    {
        $user = $request->user();
        if ($user === null) {
            return 'guest';
        }
        foreach (['name', 'email'] as $attribute) {
            $value = $user->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) $user->getAuthIdentifier();
    }
}
