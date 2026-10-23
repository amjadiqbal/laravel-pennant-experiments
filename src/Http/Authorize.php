<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::check('viewPennantExperiments', [$request]), 403);

        if (! $request->isMethodSafe()) {
            abort_if((bool) config('pennant-experiments.read_only'), 403, 'Pennant experiments are read-only.');
            abort_unless(Gate::check('managePennantExperiments', [$request]), 403);
        }

        return $next($request);
    }

    public static function canManage(): bool
    {
        return ! config('pennant-experiments.read_only') && Gate::check('managePennantExperiments', [request()]);
    }
}
