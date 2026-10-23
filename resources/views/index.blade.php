@extends('pennant-experiments::layout')

@section('title', 'Experiments')

@section('content')
    <h1>Experiments</h1>
    <p class="sub">A/B and multivariate tests defined with <code>Experiments::define()</code>. Only scopes that actually saw a variant are counted.</p>

    <div class="panel">
        @if ($rows === [])
            <p class="muted">No experiments defined yet. Add one in a service provider:</p>
            <pre><code>Experiments::define('checkout-button', ['control' => 50, 'green' => 50], ['goals' => ['purchase']]);</code></pre>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Experiment</th>
                        <th>Status</th>
                        <th class="hide-sm">Variants</th>
                        <th class="hide-sm">Goals</th>
                        <th class="num">Exposed</th>
                        <th>First goal</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php
                            $goal = $row['definition']->goals[0];
                            $best = collect($row['results']->rows($goal))->where('control', false)->sortByDesc('probability')->first();
                        @endphp
                        <tr>
                            <td>
                                <a class="mono" href="{{ route('pennant-experiments.show', $row['name']) }}"><b>{{ $row['name'] }}</b></a>
                                @if ($row['definition']->description)<div class="muted small">{{ $row['definition']->description }}</div>@endif
                            </td>
                            <td>
                                @if (($row['state']['status'] ?? 'running') === 'ended')
                                    <span class="pill off">ended</span> <span class="small">winner <b>{{ $row['state']['winner'] }}</b></span>
                                @else
                                    <span class="pill on">running</span>
                                @endif
                            </td>
                            <td class="hide-sm small">
                                @foreach ($row['definition']->weights as $variant => $weight)
                                    <span class="mono">{{ $variant }}</span> <span class="muted">{{ $weight }}</span>@unless ($loop->last) · @endunless
                                @endforeach
                            </td>
                            <td class="hide-sm small mono">{{ implode(', ', $row['definition']->goals) }}</td>
                            <td class="num">{{ number_format($row['exposed']) }}</td>
                            <td class="small">
                                @if ($best === null || $best['probability'] === null)
                                    <span class="muted">no data yet</span>
                                @else
                                    <span class="mono">{{ $best['variant'] }}</span>: {{ \AmjadIqbal\PennantExperiments\Support\Format::percent($best['probability'], 1) }} to beat control
                                    @if ($best['verdict'] === \AmjadIqbal\PennantExperiments\Results::TOO_EARLY) <span class="pill warn">too early</span>@endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($orphans !== [])
        <div class="panel">
            <h2>Recorded but no longer defined</h2>
            <p class="muted small">These have data in the experiments table but no <code>Experiments::define()</code> in code any more, so they can't be shown or changed.</p>
            <ul class="small">
                @foreach ($orphans as $name => $state)
                    <li class="mono">{{ $name }} <span class="muted">({{ $state['status'] }}{{ $state['winner'] ? ', winner '.$state['winner'] : '' }})</span></li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
