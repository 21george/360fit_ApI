<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Field-level change detection for HTTP PUT/PATCH payloads.
 *
 * Shared by ClientController and CoachController (and any other
 * controller that needs to fire side effects — e.g. notifications,
 * audit logs — only when a field actually changed rather than just
 * being present in the request body).
 *
 * Behavior contract:
 *   - null and the empty string are DISTINCT (a "field explicitly
 *     cleared" change is a real change).
 *   - numeric strings and their numeric counterparts are EQUAL
 *     ("85" === 85, 1.0 === 1).
 *   - booleans are NOT coerced to strings.
 *   - arrays (e.g. sub-documents like social_media) are compared
 *     by JSON canonicalization so order-independent equality holds.
 *   - if the pre-update record is null (not found), returns []
 *     defensively rather than firing side effects for an
 *     unverifiable change set.
 *
 * Pure: no I/O, no logging, no exceptions on ordinary input.
 */
final class Diff
{
    /**
     * @param array<string,mixed> $body          Submitted values.
     * @param object|null        $before        Pre-update record (or null if not found).
     * @param string[]           $allowedFields Restrict to these field names.
     * @return string[]   Field names whose value differs from the pre-update record.
     */
    public static function changedFields(array $body, ?object $before, array $allowedFields): array
    {
        if ($before === null) return [];

        $changed = [];
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $body)) continue;
            if (self::valuesEqual($body[$field], $before->$field ?? null)) continue;
            $changed[] = $field;
        }
        return $changed;
    }

    /**
     * Compare two scalar/array/BSON values for change-detection.
     * See class docblock for the full contract.
     */
    public static function valuesEqual(mixed $a, mixed $b): bool
    {
        // Fast path: same type and value (handles null === null, etc).
        if ($a === $b) return true;

        // Arrays (e.g. sub-documents): canonical JSON compare.
        // Order-independent for associative arrays; sequential arrays
        // require matching order. Good enough for our form payloads
        // which are always associative.
        if (is_array($a) || is_array($b)) {
            return self::canonicalJson($a) === self::canonicalJson($b);
        }

        // null handling. Any mismatch involving null (null vs "",
        // null vs "x", "" vs null) is a real change. The only
        // equal-null case is null === null, already caught above.
        if ($a === null || $b === null) {
            return false;
        }

        // Non-null mismatch: coerce via string for type-tolerant
        // comparison. ("85" === 85) holds, (1.0 === 1) holds,
        // (true !== "1") — bools stringify to "" / "1" inconsistently
        // across drivers so we treat them as strict.
        if (is_bool($a) || is_bool($b)) {
            return false;
        }
        return (string) $a === (string) $b;
    }

    /**
     * JSON-canonicalize a value for order-independent comparison.
     * Objects (BSON) are treated as their public-property arrays.
     */
    private static function canonicalJson(mixed $v): string
    {
        if (is_object($v)) {
            $v = get_object_vars($v);
        }
        if (is_array($v)) {
            // Sort keys recursively so {a:1,b:2} === {b:2,a:1}.
            $sorted = [];
            foreach ($v as $k => $vv) {
                $sorted[$k] = is_array($vv) || is_object($vv) ? self::canonicalJson($vv) : $vv;
            }
            ksort($sorted);
            return json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $v;
    }
}
