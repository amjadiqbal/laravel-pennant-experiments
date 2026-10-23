<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tracking endpoints only accept writes from this site's own pages: the Origin header
 * (or, without one, the Referer) must match the request's scheme, host and port. On top of
 * the web group's CSRF check and the rate limit.
 */
final class VerifyOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! config('pennant-experiments.tracking.check_origin', true)) {
            return $next($request);
        }

        $expected = self::normalize($request->getSchemeAndHttpHost());
        $origin = $request->headers->get('Origin');
        $source = $origin !== null && $origin !== '' && $origin !== 'null' ? $origin : $request->headers->get('Referer');

        abort_unless($source !== null && $expected !== null && self::normalize($source) === $expected, 403, 'Cross-origin tracking request refused.');

        return $next($request);
    }

    /** scheme://host[:port], with the default port for the scheme left out. */
    public static function normalize(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme.'://'.strtolower($parts['host']).($port !== null ? ':'.$port : '');
    }
}
