@extends('pennant-experiments::layout')

@php
    use AmjadIqbal\PennantExperiments\Results;
    use AmjadIqbal\PennantExperiments\Stats\Statistics;
    use AmjadIqbal\PennantExperiments\Support\Format;
    $ended = ($state['status'] ?? 'running') === 'ended';
    $minExposures = (int) config('pennant-experiments.statistics.min_exposures');
    $minConversions = (int) config('pennant-experiments.statistics.min_conversions');
    $challengers = max(1, count($definition->weights) - 1);
@endphp

@section('title', $definition->name)

@section('content')
    <p class="small"><a href="{{ route('pennant-experiments.index') }}">← All experiments</a></p>
    <div class="row" style="justify-content: space-between; align-items: flex-start">
        <div>
            <h1 class="mono">{{ $definition->name }}</h1>
            <p class="sub">
                @if ($definition->description){{ $definition->description }} · @endif
                @if ($ended)
                    <span class="pill off">ended</span> winner <b class="mono">{{ $state['winner'] }}</b> for everyone since {{ $state['ended_at'] }}@if ($state['ended_by']) by {{ $state['ended_by'] }}@endif
                @else
                    <span class="pill on">running</span>@if ($firstExposure) since {{ $firstExposure }}@endif
                @endif
            </p>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><b>{{ number_format($results->totalExposed()) }}</b><span>exposed scopes</span></div>
        @foreach ($definition->weights as $variant => $weight)
            <div class="stat"><b>{{ number_format($results->exposed($variant)) }}</b><span><span class="mono">{{ $variant }}</span>@if ($loop->first && $variant !== 'control') (control)@endif · weight {{ $weight }} of {{ array_sum($definition->weights) }}</span></div>
        @endforeach
    </div>

    @foreach ($definition->goals as $goal)
        @php
            $rows = $results->rows($goal);
            $planned = $results->plannedSample($goal);
            $enough = $results->hasMinimumData($goal);
            $intervals = [];
            foreach ($rows as $r) { $intervals[$r['variant']] = Statistics::wilson($r['converted'], $r['exposed'], 1 - $results->alpha()); }
            $scale = max(0.0001, ...array_values(array_map(fn ($i) => $i[1] ?? 0, $intervals))) * 1.08;
            $anyConversions = array_sum(array_column($rows, 'converted')) > 0;
        @endphp
        <div class="panel">
            <div class="row" style="justify-content: space-between">
                <h2>Goal: <span class="mono">{{ $goal }}</span></h2>
                <span class="small muted">
                    @if ($planned !== null)
                        planned sample {{ number_format($planned) }} per variant
                        @if ($definition->sampleSize === null)(for a {{ Format::signedPercent($definition->minimumDetectableEffect, 0) }} lift at the control's rate, power {{ Format::number((float) config('pennant-experiments.statistics.power') * 100, 0) }}%)@endif
                    @else
                        no planned sample size
                    @endif
                </span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Variant</th>
                        <th class="num">Exposed</th>
                        <th class="num">Converted</th>
                        <th class="num">Rate</th>
                        <th class="hide-sm">Rate and {{ Format::number((1 - $results->alpha()) * 100, 1) }}% interval</th>
                        <th class="num">Lift</th>
                        <th class="num hide-sm">Difference, interval</th>
                        <th class="num">p-value</th>
                        <th class="num">P(beats control)</th>
                        <th>Verdict</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php $ci = $intervals[$row['variant']]; @endphp
                        <tr>
                            <td><span class="mono"><b>{{ $row['variant'] }}</b></span>@if ($row['control'] && $row['variant'] !== 'control') <span class="pill outline">control</span>@endif @if ($ended && $state['winner'] === $row['variant'])<span class="pill accent">winner</span>@endif</td>
                            <td class="num">{{ number_format($row['exposed']) }}</td>
                            <td class="num">{{ number_format($row['converted']) }}</td>
                            <td class="num"><b>{{ Format::percent($row['rate']) }}</b></td>
                            <td class="hide-sm">
                                @if (! $anyConversions)
                                    <span class="muted small">no conversions yet</span>
                                @elseif ($row['rate'] !== null)
                                    <div class="rate" title="{{ $row['variant'] }}: {{ Format::percent($row['rate']) }}, interval {{ Format::percent($ci[0]) }} to {{ Format::percent($ci[1]) }}">
                                        <span class="track"></span>
                                        <span class="fill {{ $row['control'] ? 'control' : 'variant' }}" style="width: {{ round($row['rate'] / $scale * 100, 2) }}%"></span>
                                        <span class="ci" style="left: {{ round($ci[0] / $scale * 100, 2) }}%; width: {{ max(0.5, round(($ci[1] - $ci[0]) / $scale * 100, 2)) }}%"></span>
                                    </div>
                                @endif
                            </td>
                            <td class="num">{{ $row['control'] ? '' : Format::signedPercent($row['lift']) }}</td>
                            <td class="num hide-sm small">{{ $row['control'] ? '' : ($anyConversions && $row['ci'] !== null ? Format::points((float) $row['diff']).' pp '.Format::interval($row['ci']) : '–') }}</td>
                            <td class="num">{{ $row['control'] ? '' : Format::pValue($row['p']) }}</td>
                            <td class="num">{{ $row['control'] ? '' : Format::percent($row['probability'], 1) }}</td>
                            <td>
                                @if (! $row['control'])
                                    <span @class(['pill', 'on' => $row['verdict'] === Results::BETTER, 'danger' => $row['verdict'] === Results::WORSE, 'warn' => in_array($row['verdict'], [Results::TOO_EARLY, Results::KEEP_RUNNING], true), 'outline' => $row['verdict'] === Results::NO_DIFFERENCE])>{{ Results::verdictLabel($row['verdict'], $planned !== null) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @unless ($enough)
                <p class="note warn"><b>Too early to read.</b> Every variant needs at least {{ number_format($minExposures) }} exposed scopes and {{ number_format($minConversions) }} conversions before the numbers mean much. They are shown, but no verdict is given.</p>
            @endunless
            @if ($enough && $planned !== null && ! $results->reachedPlan($goal))
                <p class="note warn"><b>Keep running until every variant has {{ number_format($planned) }} exposed scopes.</b> Don't stop early because a p-value dips below {{ Format::number($results->alpha(), 4) }}: looking repeatedly and stopping at the first "significant" result makes false winners far more likely than the {{ Format::number($results->alpha() * 100, 2) }}% you planned for.</p>
            @endif
            @if ($planned === null)
                <p class="note">No planned sample size. Set <code>sample_size</code> (per variant) or <code>minimum_detectable_effect</code> on the experiment, decide it before you start, and read the z-test only once it is reached. A "better" verdict without one is provisional.</p>
            @endif
        </div>
    @endforeach

    <div class="panel">
        <h2>How to read this</h2>
        <ul class="small muted" style="margin: 0; padding-left: 18px">
            <li><b>Rate</b> = scopes that converted ÷ scopes that saw the experiment. Each scope counts once per goal.</li>
            <li><b>p-value</b>: two-proportion z-test (pooled), two-sided, against the control. A variant is significant when p &lt; {{ Format::number($results->alpha(), 4) }}@if ($challengers > 1) ({{ Format::number((float) config('pennant-experiments.statistics.alpha'), 2) }} split across {{ $challengers }} challengers, Bonferroni)@endif.</li>
            <li><b>P(beats control)</b>: the Bayesian probability that the variant's true rate is higher than the control's, with a flat Beta(1, 1) prior. It says how likely the variant is better, not by how much.</li>
            <li>The interval on each bar is the Wilson score interval for that variant's rate; the difference interval is for variant minus control, in percentage points.</li>
        </ul>
    </div>

    <div class="panel">
        @if ($ended)
            <h2>Ended</h2>
            <p class="muted">Everyone gets <b class="mono">{{ $state['winner'] }}</b>, stored Pennant values were updated, and nothing more is recorded.</p>
            <form method="post" action="{{ route('pennant-experiments.restart', $definition->name) }}" class="inline" data-confirm="Run this experiment again? Stored values from the rollout are removed and everyone is assigned afresh.">
                @csrf
                <button class="btn" type="submit" @disabled(! $canManage)>Run again</button>
            </form>
        @else
            <h2>End the experiment</h2>
            <p class="muted small">Everyone gets the variant you pick, from now on. Stored Pennant values for this feature are rewritten to it, and no more exposures or conversions are recorded.</p>
            <form method="post" action="{{ route('pennant-experiments.end', $definition->name) }}" class="row" data-confirm="End the experiment and give everyone the chosen variant?">
                @csrf
                <label class="small muted" for="winner">Winner</label>
                <select name="winner" id="winner" @disabled(! $canManage)>
                    @foreach ($definition->variants() as $variant)
                        <option value="{{ $variant }}">{{ $variant }}{{ $loop->first && $variant !== 'control' ? ' (control)' : '' }}</option>
                    @endforeach
                </select>
                <button class="btn primary" type="submit" @disabled(! $canManage)>End and roll out</button>
            </form>
        @endif
    </div>
@endsection
