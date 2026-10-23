<?php

use AmjadIqbal\PennantExperiments\Definition;
use AmjadIqbal\PennantExperiments\Facades\Experiments;
use AmjadIqbal\PennantExperiments\Results;

function results(array $weights, array $exposed, array $converted, array $options = []): Results
{
    return new Results(Definition::make('exp', $weights, $options), $exposed, ['conversion' => $converted]);
}

it('says "too early" below the minimum data, whatever the p-value', function () {
    $r = results(['control', 'b'], ['control' => 60, 'b' => 60], ['control' => 2, 'b' => 20]);
    $row = $r->rows('conversion')[1];
    expect($row['p'])->toBeLessThan(0.001)->and($row['verdict'])->toBe(Results::TOO_EARLY);
});

it('calls a planned, reached, significant result better (or worse)', function () {
    $r = results(['control', 'b'], ['control' => 5050, 'b' => 5050], ['control' => 207, 'b' => 252], ['sample_size' => 5000]);
    $row = $r->rows('conversion')[1];
    expect($row['p'])->toEqualWithDelta(0.03157, 1e-5)
        ->and($row['probability'])->toEqualWithDelta(0.98418, 1e-5)
        ->and($row['verdict'])->toBe(Results::BETTER);

    $worse = results(['control', 'b'], ['control' => 5050, 'b' => 5050], ['control' => 252, 'b' => 207], ['sample_size' => 5000]);
    expect($worse->rows('conversion')[1]['verdict'])->toBe(Results::WORSE);
});

it('says "keep running" before the planned sample, even when p < alpha (no peeking)', function () {
    $r = results(['control', 'b'], ['control' => 5050, 'b' => 5050], ['control' => 207, 'b' => 252], ['sample_size' => 8000]);
    expect($r->rows('conversion')[1]['verdict'])->toBe(Results::KEEP_RUNNING);
});

it('derives the planned sample from the minimum detectable effect and the control rate', function () {
    // Control 10%, +50% lift wanted, alpha .05, power .8 -> 686 per variant (Fleiss).
    $r = results(['control', 'b'], ['control' => 1000, 'b' => 1000], ['control' => 100, 'b' => 150], ['minimum_detectable_effect' => 0.5]);
    expect($r->plannedSample('conversion'))->toBe(686)->and($r->reachedPlan('conversion'))->toBeTrue();
});

it('splits alpha across challengers (Bonferroni)', function () {
    $r = results(['control', 'b', 'c', 'd'], ['control' => 5050, 'b' => 5050, 'c' => 5050, 'd' => 5050], ['control' => 207, 'b' => 252, 'c' => 207, 'd' => 207], ['sample_size' => 5000]);
    expect($r->alpha())->toEqualWithDelta(0.05 / 3, 1e-12);
    // p = 0.0316 is significant at .05 but not at .0167.
    expect($r->rows('conversion')[1]['verdict'])->toBe(Results::NO_DIFFERENCE);
});

it('labels a result without a plan as provisional', function () {
    $r = results(['control', 'b'], ['control' => 5050, 'b' => 5050], ['control' => 207, 'b' => 252]);
    expect($r->rows('conversion')[1]['verdict'])->toBe(Results::BETTER)
        ->and(Results::verdictLabel(Results::BETTER, false))->toBe('Better (no planned sample)');
});

it('builds results from recorded data', function () {
    Experiments::define('live', ['control', 'b']);
    foreach (range(1, 12) as $n) {
        $user = $this->user($n);
        Experiments::expose('live', $user);
        if ($n % 3 === 0) {
            Experiments::convert('live', 'conversion', $user);
        }
    }
    $r = Experiments::results('live');
    expect($r->totalExposed())->toBe(12)
        ->and(array_sum(array_column($r->rows('conversion'), 'converted')))->toBe(4);
});
