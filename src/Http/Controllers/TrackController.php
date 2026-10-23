<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Http\Controllers;

use AmjadIqbal\PennantExperiments\ExperimentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints for the JavaScript helper. The participant always comes from the server side
 * (session user or visitor cookie), never from the request body.
 */
final class TrackController
{
    public function __construct(private readonly ExperimentManager $experiments) {}

    public function variant(string $experiment): JsonResponse
    {
        abort_unless($this->experiments->has($experiment), 404);

        return response()->json(['experiment' => $experiment, 'variant' => $this->experiments->variant($experiment)]);
    }

    public function expose(Request $request): JsonResponse
    {
        $experiment = $this->experiment($request);

        return response()->json(['experiment' => $experiment, 'variant' => $this->experiments->expose($experiment)]);
    }

    public function convert(Request $request): JsonResponse
    {
        $experiment = $this->experiment($request);
        $goal = (string) $request->input('goal', 'conversion');

        if (! $this->experiments->definition($experiment)->hasGoal($goal)) {
            return response()->json(['message' => "Unknown goal [{$goal}]."], 422);
        }

        return response()->json(['experiment' => $experiment, 'goal' => $goal, 'recorded' => $this->experiments->convert($experiment, $goal)]);
    }

    private function experiment(Request $request): string
    {
        $experiment = $request->input('experiment');
        abort_unless(is_string($experiment) && $this->experiments->has($experiment), 404, 'Unknown experiment.');

        return $experiment;
    }
}
