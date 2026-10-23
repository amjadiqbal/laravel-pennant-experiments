<?php

use AmjadIqbal\PennantExperiments\Definition;

it('takes a list (equal weights) or variant => weight, first is the control', function () {
    $list = Definition::make('a', ['control', 'green', 'blue']);
    expect($list->weights)->toBe(['control' => 1, 'green' => 1, 'blue' => 1])
        ->and($list->control())->toBe('control')
        ->and($list->goals)->toBe(['conversion']);

    $weighted = Definition::make('b', ['old' => 80, 'new' => 20], ['goals' => ['signup', 'purchase', 'signup']]);
    expect($weighted->control())->toBe('old')->and($weighted->goals)->toBe(['signup', 'purchase']);
});

it('rejects invalid definitions', function (array $variants, array $options) {
    expect(fn () => Definition::make('x', $variants, $options))->toThrow(InvalidArgumentException::class);
})->with([
    'one variant' => [['only'], []],
    'negative weight' => [['a' => 1, 'b' => -1], []],
    'all zero' => [['a' => 0, 'b' => 0], []],
    'no goals' => [['a', 'b'], ['goals' => []]],
    'bad mde' => [['a', 'b'], ['minimum_detectable_effect' => 0]],
    'bad sample size' => [['a', 'b'], ['sample_size' => 0]],
]);

it('picks by weight from a point in [0, 1)', function () {
    $d = Definition::make('x', ['a' => 1, 'b' => 3, 'c' => 0, 'd' => 1]);
    expect($d->pick(0.0))->toBe('a')
        ->and($d->pick(0.199))->toBe('a')
        ->and($d->pick(0.2))->toBe('b')
        ->and($d->pick(0.79))->toBe('b')
        ->and($d->pick(0.8))->toBe('d')
        ->and($d->pick(0.9999999))->toBe('d');
});

it('buckets deterministically and spreads scopes evenly by weight', function () {
    $d = Definition::make('checkout', ['control' => 70, 'green' => 30]);
    expect($d->bucket('App\Models\User|1'))->toBe($d->bucket('App\Models\User|1'));

    $counts = ['control' => 0, 'green' => 0];
    for ($i = 1; $i <= 20000; $i++) {
        $counts[$d->pick($d->bucket("App\\Models\\User|{$i}"))]++;
    }
    // 30% of 20,000 = 6,000, sd = sqrt(20000 * .3 * .7) = 65; allow 4 sd.
    expect($counts['green'])->toBeGreaterThan(6000 - 260)->toBeLessThan(6000 + 260);
});

it('uses a different split per experiment (salt = name)', function () {
    $a = Definition::make('one', ['x', 'y']);
    $b = Definition::make('two', ['x', 'y']);
    $same = 0;
    for ($i = 1; $i <= 2000; $i++) {
        $same += (int) ($a->pick($a->bucket("u|{$i}")) === $b->pick($b->bucket("u|{$i}")));
    }
    expect($same)->toBeGreaterThan(850)->toBeLessThan(1150);
});
