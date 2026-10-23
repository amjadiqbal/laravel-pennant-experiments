<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments;

use Closure;
use InvalidArgumentException;

/**
 * One experiment as defined in code: its variants and weights, goals and options.
 */
final class Definition
{
    /**
     * @param  array<string, int>  $weights  variant => weight; the first variant is the control
     * @param  list<string>  $goals
     * @param  (Closure(mixed): bool)|null  $eligible
     */
    public function __construct(
        public readonly string $name,
        public readonly array $weights,
        public readonly array $goals,
        public readonly ?string $store = null,
        public readonly ?Closure $eligible = null,
        public readonly ?string $description = null,
        public readonly ?float $minimumDetectableEffect = null,
        public readonly ?int $sampleSize = null,
        public readonly ?string $salt = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('An experiment needs a name.');
        }
        if (count($weights) < 2) {
            throw new InvalidArgumentException("Experiment [{$name}] needs at least two variants.");
        }
        foreach ($weights as $variant => $weight) {
            // Checked at runtime too: make() takes untyped input.
            if (! is_string($variant) || $variant === '' || ! is_int($weight) || $weight < 0) { // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
                throw new InvalidArgumentException("Experiment [{$name}]: variants are names with whole-number weights of 0 or more.");
            }
        }
        if (array_sum($weights) <= 0) {
            throw new InvalidArgumentException("Experiment [{$name}]: at least one variant needs a weight above 0.");
        }
        if ($goals === []) {
            throw new InvalidArgumentException("Experiment [{$name}] needs at least one goal.");
        }
        if ($minimumDetectableEffect !== null && $minimumDetectableEffect <= 0) {
            throw new InvalidArgumentException("Experiment [{$name}]: the minimum detectable effect is a relative lift above 0, e.g. 0.1 for +10%.");
        }
        if ($sampleSize !== null && $sampleSize < 1) {
            throw new InvalidArgumentException("Experiment [{$name}]: the planned sample size is per variant and must be at least 1.");
        }
    }

    /**
     * Build from the array form used by Experiments::define().
     *
     * Variants may be a list (equal weights) or variant => weight.
     *
     * @param  array<int|string, string|int>  $variants
     * @param  array<string, mixed>  $options
     */
    public static function make(string $name, array $variants, array $options = []): self
    {
        $weights = [];
        foreach ($variants as $key => $value) {
            if (is_int($key)) {
                $weights[(string) $value] = 1;
            } else {
                $weights[$key] = $value;
            }
        }

        $goals = array_values(array_map('strval', (array) ($options['goals'] ?? ['conversion'])));

        return new self(
            name: $name,
            weights: $weights,
            goals: array_values(array_unique($goals)),
            store: isset($options['store']) ? (string) $options['store'] : null,
            eligible: isset($options['eligible']) ? Closure::fromCallable($options['eligible']) : null,
            description: isset($options['description']) ? (string) $options['description'] : null,
            minimumDetectableEffect: isset($options['minimum_detectable_effect']) ? (float) $options['minimum_detectable_effect'] : null,
            sampleSize: isset($options['sample_size']) ? (int) $options['sample_size'] : null,
            salt: isset($options['salt']) ? (string) $options['salt'] : null,
        );
    }

    public function control(): string
    {
        return (string) array_key_first($this->weights);
    }

    /** @return list<string> */
    public function variants(): array
    {
        return array_map('strval', array_keys($this->weights));
    }

    public function hasVariant(mixed $variant): bool
    {
        return is_string($variant) && array_key_exists($variant, $this->weights);
    }

    public function hasGoal(string $goal): bool
    {
        return in_array($goal, $this->goals, true);
    }

    /**
     * Deterministic weighted pick from a point in [0, 1): the same scope always lands on the
     * same variant while the weights stay the same.
     */
    public function pick(float $point): string
    {
        $total = array_sum($this->weights);
        $cumulative = 0;
        foreach ($this->weights as $variant => $weight) {
            $cumulative += $weight;
            if ($point * $total < $cumulative) {
                return (string) $variant;
            }
        }

        return (string) array_key_last(array_filter($this->weights));
    }

    /** A point in [0, 1) from sha256(salt | serialized scope), uniform for practical purposes. */
    public function bucket(string $serializedScope): float
    {
        $hash = hash('sha256', ($this->salt ?? $this->name).'|'.$serializedScope);

        // 52 bits: exact in a double.
        return hexdec(substr($hash, 0, 13)) / 4503599627370496;
    }
}
