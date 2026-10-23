<?php

declare(strict_types=1);

namespace AmjadIqbal\PennantExperiments;

use Laravel\Pennant\Contracts\FeatureScopeSerializeable;

/**
 * A guest, identified by a random id kept in a long-lived cookie. Pennant stores it as "visitor|<id>".
 */
final class Visitor implements FeatureScopeSerializeable
{
    public const PREFIX = 'visitor|';

    public function __construct(public readonly string $id) {}

    public function featureScopeSerialize(): string
    {
        return self::PREFIX.$this->id;
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
    }
}
