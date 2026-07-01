<?php

declare(strict_types=1);

namespace App\Helpers;

use MongoDB\BSON\ObjectId;

/**
 * Centralised safe-construction of Mongo ObjectIds.
 *
 * `new ObjectId($str)` throws on anything that isn't a 24-char hex
 * string. In most call sites that "anything" comes from a user-supplied
 * id or — worse — a JWT `sub` claim that the JWT layer doesn't
 * validate for ObjectId format. The throw propagates to the global
 * exception handler and the client gets a 500.
 *
 * Use `Id::isValid($str)` to gate a 400 response, or
 * `Id::try($str)` to get an ObjectId-or-null (for filters).
 */
final class Id
{
    /**
     * True iff $id is a syntactically valid 24-char hex ObjectId string.
     */
    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-fA-F]{24}$/', $id) === 1;
    }

    /**
     * Construct an ObjectId from a string, returning null on invalid
     * input instead of throwing. Caller decides what null means.
     */
    public static function try(mixed $id): ?ObjectId
    {
        if (!self::isValid($id)) {
            return null;
        }
        try {
            return new ObjectId($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Construct an ObjectId from a string, returning null on invalid
     * input. Like `try()` but coerces scalars first.
     */
    public static function tryFromMixed(mixed $id): ?ObjectId
    {
        if (is_array($id) || $id === null) {
            return null;
        }
        return self::try((string) $id);
    }
}
