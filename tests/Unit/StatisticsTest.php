<?php

use AmjadIqbal\PennantExperiments\Stats\Distributions;
use AmjadIqbal\PennantExperiments\Stats\Statistics;
use AmjadIqbal\PennantExperiments\Support\Format;

/*
 * Reference values: textbook examples where they exist, otherwise independent computations
 * (Python's math.erfc / statistics.NormalDist, and numerical integration of the Beta posteriors
 * with numpy on a 2,000,000-point grid), recorded in research/laravel-pennant-experiments-PROGRESS.md.
 */

it('matches standard normal table values', function () {
    expect(Distributions::normalCdf(0))->toBe(0.5)
        ->and(Distributions::normalCdf(1.96))->toEqualWithDelta(0.9750021048517795, 1e-14)
        ->and(Distributions::normalCdf(-1.0))->toEqualWithDelta(0.15865525393145707, 1e-14)
        ->and(Distributions::normalSf(5.0))->toEqualWithDelta(2.866515718791945e-07, 1e-19)
        ->and(Distributions::normalQuantile(0.975))->toEqualWithDelta(1.959963984540054, 1e-12)
        ->and(Distributions::normalQuantile(0.8))->toEqualWithDelta(0.8416212335729143, 1e-12)
        ->and(Distributions::normalQuantile(0.001))->toEqualWithDelta(-3.090232306167813, 1e-11);
});

it('computes erfc and log-gamma to double precision', function () {
    foreach ([[0.1, 0.8875370839817152], [0.5, 0.4795001221869535], [1.5, 0.033894853524689274], [4.0, 1.541725790028002e-08], [7.0, 4.183825607779414e-23]] as [$x, $ref]) {
        expect(Distributions::erfc($x))->toEqualWithDelta($ref, abs($ref) * 1e-13);
    }
    expect(Distributions::logGamma(0.5))->toEqualWithDelta(log(sqrt(M_PI)), 1e-13)   // Gamma(1/2) = sqrt(pi)
        ->and(Distributions::logGamma(10))->toEqualWithDelta(log(362880), 1e-12)   // 9!
        ->and(Distributions::logGamma(1))->toEqualWithDelta(0.0, 1e-14);
});

it('reproduces the OpenIntro mammogram example (two-proportion z-test)', function () {
    // OpenIntro Statistics, 4th ed., section 6.2: 500 of 44,425 (mammogram) vs 505 of 44,405 (control) died
    // of breast cancer; pooled p = 0.0113, z = -0.17, p-value about 0.87: no evidence of a difference.
    $test = Statistics::zTest(505, 44405, 500, 44425);

    expect(round($test['z'], 2))->toBe(-0.17)
        ->and($test['p'])->toEqualWithDelta(0.8683157113444111, 1e-10)
        ->and($test['ci'][0])->toEqualWithDelta(-0.0015086820232596456, 1e-12)
        ->and($test['ci'][1])->toEqualWithDelta(0.0012733437288970611, 1e-12);
});

it('computes z, p and the difference interval for typical A/B data', function () {
    $test = Statistics::zTest(207, 5050, 252, 5050);
    expect($test['z'])->toEqualWithDelta(2.1498384028, 1e-9)
        ->and($test['p'])->toEqualWithDelta(0.0315679994, 1e-9)
        ->and($test['ci'][0])->toEqualWithDelta(0.0007888717, 1e-9)
        ->and($test['ci'][1])->toEqualWithDelta(0.0170329104, 1e-9)
        ->and($test['lift'])->toEqualWithDelta(252 / 207 - 1, 1e-12);

    $test = Statistics::zTest(20, 100, 30, 100);
    expect($test['z'])->toEqualWithDelta(1.6329931619, 1e-9)
        ->and($test['p'])->toEqualWithDelta(0.1024704349, 1e-9);

    $test = Statistics::zTest(120, 2400, 150, 2380);
    expect($test['z'])->toEqualWithDelta(1.9503952538, 1e-9)
        ->and($test['p'])->toEqualWithDelta(0.0511290271, 1e-9)
        ->and($test['ci'][0])->toEqualWithDelta(-0.00006463, 1e-8);
});

it('handles empty and degenerate groups without dividing by zero', function () {
    expect(Statistics::zTest(0, 0, 3, 10)['p'])->toBeNull()
        ->and(Statistics::zTest(0, 50, 0, 50)['p'])->toBe(1.0)
        ->and(Statistics::zTest(50, 50, 50, 50)['p'])->toBe(1.0)
        ->and(Statistics::zTest(0, 50, 5, 50)['lift'])->toBeNull();
    expect(fn () => Statistics::zTest(11, 10, 1, 10))->toThrow(InvalidArgumentException::class);
});

it('gives exact Bayesian probabilities where they are known in closed form', function () {
    // Beta(2,1) vs Beta(1,1): P(B > A) = integral of x * 2x dx = 2/3.
    expect(Statistics::probabilityToBeat(0, 0, 1, 1))->toEqualWithDelta(2 / 3, 1e-12)
        // Uniform B vs A = Beta(1,2) (0 of 1): also 2/3.
        ->and(Statistics::probabilityToBeat(0, 1, 0, 0))->toEqualWithDelta(2 / 3, 1e-12)
        // Identical posteriors: exactly one half.
        ->and(Statistics::probabilityToBeat(3, 10, 3, 10))->toEqualWithDelta(0.5, 1e-12)
        ->and(Statistics::probabilityToBeat(0, 0, 0, 0))->toEqualWithDelta(0.5, 1e-12);
});

it('matches numerical integration of the Beta posteriors', function () {
    // numpy, trapezoid rule on 2,000,000 points; agreement to about 1e-6 is the grid's own accuracy.
    expect(Statistics::probabilityToBeat(20, 100, 30, 100))->toEqualWithDelta(0.94760310, 2e-6)
        ->and(Statistics::probabilityToBeat(207, 5050, 252, 5050))->toEqualWithDelta(0.98417827, 2e-6)
        ->and(Statistics::probabilityToBeat(50, 1000, 40, 1000))->toEqualWithDelta(0.14161852, 2e-6)
        ->and(Statistics::probabilityToBeat(120, 2400, 150, 2380))->toEqualWithDelta(0.97432195, 2e-6);
});

it('is symmetric: P(B beats A) + P(A beats B) = 1', function () {
    $ab = Statistics::probabilityToBeat(120, 2400, 150, 2380);
    $ba = Statistics::probabilityToBeat(150, 2380, 120, 2400);
    expect($ab + $ba)->toEqualWithDelta(1.0, 1e-10);
});

it('switches to the normal approximation for very large counts and agrees with the exact sum', function () {
    $exact = Statistics::probabilityToBeat(900, 20000, 980, 20000);
    $approx = Statistics::probabilityToBeat(900, 20000, 980, 20000, exactLimit: 10);
    expect($approx)->toEqualWithDelta($exact, 5e-4);
});

it('computes the classic sample size (Fleiss, no continuity correction)', function () {
    // 10% -> 15%, alpha 0.05 two-sided, power 0.8: 686 per group (685.6 rounded up).
    expect(Statistics::sampleSizePerGroup(0.10, 0.5))->toBe(686)
        ->and(Statistics::sampleSizePerGroup(0.05, 0.2))->toBe(8158)
        ->and(Statistics::sampleSizePerGroup(0.20, 0.25, 0.01, 0.9))->toBe(2074)
        ->and(Statistics::sampleSizePerGroup(0.0, 0.1))->toBeNull()
        ->and(Statistics::sampleSizePerGroup(0.6, 1.0))->toBeNull();
});

it('computes the Wilson score interval', function () {
    // Wilson (1927); e.g. 1 of 10 at 95%: [0.0179, 0.4042]; reference from Python.
    [$low, $high] = Statistics::wilson(1, 10);
    expect($low)->toEqualWithDelta(0.0178762131, 1e-9)->and($high)->toEqualWithDelta(0.4041500268, 1e-9);
    expect(Statistics::wilson(0, 0))->toBeNull();
});

it('never shows a probability as a certainty', function () {
    expect(Format::probability(0.9997))->toBe('> 99.9%')
        ->and(Format::probability(0.0002))->toBe('< 0.1%')
        ->and(Format::probability(0.98418))->toBe('98.4%')
        ->and(Format::probability(null))->toBe('–');
});

it('formats plain numbers without eating whole-number zeros', function () {
    expect(Format::number(80.0, 0))->toBe('80')
        ->and(Format::number(100.0, 1))->toBe('100')
        ->and(Format::number(0.025, 4))->toBe('0.025')
        ->and(Format::number(97.5, 1))->toBe('97.5');
});

it('uses the exact sum up to 20,000 challenger conversions and the approximation above', function () {
    $approx = fn (int $x, int $n) => Statistics::probabilityToBeatApprox(1.0 + 19000, 1.0 + 400000 - 19000, 1.0 + $x, 1.0 + $n - $x);

    // At exactly 20,000 the exact sum is used (it differs from the approximation in the last digits) ...
    expect(Statistics::probabilityToBeat(19000, 400000, 20000, 400000))->not->toBe($approx(20000, 400000))
        // ... and both agree closely, as they should at this size.
        ->toEqualWithDelta($approx(20000, 400000), 1e-4);
    // Above it, the approximation itself is returned.
    expect(Statistics::probabilityToBeat(19000, 400000, 20001, 400000))->toBe($approx(20001, 400000));
});
