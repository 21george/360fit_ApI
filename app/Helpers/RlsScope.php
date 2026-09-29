<?php

declare(strict_types=1);

namespace App\Helpers;

use MongoDB\BSON\ObjectId;

/**
 * Row-Level Security scope configuration.
 * Maps MongoDB collections to ownership fields for each user role.
 *
 * Usage guideline:
 *   - Use ScopedCollection::for('name') when the collection has an RLS entry
 *     for the current role AND the endpoint only operates on that role's own
 *     data.  The ownership filter is injected automatically.
 *   - Use Database::collection('name') directly when:
 *       1. No auth context exists yet (login, register, public endpoints).
 *       2. The collection is not in OWNERSHIP_MAP.
 *       3. The caller is a coach but the collection is client-owned
 *          (workout_logs, messages, notifications) — the coach must query
 *          by explicit client_id instead.
 *       4. The query is an aggregation with complex $match stages that RLS
 *          would incorrectly narrow.
 *   - When using direct Database::collection() on a collection that IS in the
 *     map, add an inline comment explaining why ScopedCollection is bypassed.
 */
class RlsScope
{
    /**
     * Collection → ownership field mapping.
     * Key: collection name
     * Value: [
     *   'coach' => field name OR callable(ObjectId $userId): array,
     *   'client' => field name OR callable(ObjectId $userId): array,
     * ]
     *
     * Callables are used where a single field doesn't capture the access
     * rule (e.g. a plan may be assigned to a client by `client_id` OR
     * by inclusion in `client_ids`).
     */
    private const OWNERSHIP_MAP = [
        /* ── Coach-owned resources ───────────────────────────────────────── */
        'clients'              => ['coach' => 'coach_id',           'staff' => 'coach_id',           'client' => '_id'],
        'workout_plans'        => ['coach' => 'coach_id',           'staff' => 'coach_id',           'client' => [self::class, 'workoutPlansClientFilter']],
        'nutrition_plans'      => ['coach' => 'coach_id',           'staff' => 'coach_id',           'client' => [self::class, 'nutritionPlansClientFilter']],
        'checkin_meetings'     => ['coach' => 'coach_id',           'staff' => 'coach_id',           'client' => 'client_id'],
        'coaching_sessions'    => ['coach' => 'coach_id',           'staff' => 'coach_id',           'client' => 'client_id'],
        'live_training_sessions' => ['coach' => 'coach_id',         'staff' => 'coach_id',           'client' => [self::class, 'liveTrainingClientFilter']],
        'foods'                  => ['coach' => null,               'staff' => null,                 'client' => null], // public cache + manual custom-food scoping

        /* ── Client-owned resources (coach has no direct access) ─────────── */
        'workout_logs'         => ['coach' => null,                 'staff' => null,                 'client' => 'client_id'],
        'body_measurements'    => ['coach' => null,                 'staff' => null,                 'client' => 'client_id'],
        'media_uploads'        => ['coach' => null,                 'staff' => null,                 'client' => 'client_id'],
        'messages'             => ['coach' => null,                 'staff' => null,                 'client' => 'client_id'],
        'notifications'        => ['coach' => null,                 'staff' => null,                 'client' => 'client_id'],
        'message_typing_status'=> ['coach' => null,                 'staff' => null,                 'client' => 'client_id'],

        /* ── Self resources ────────────────────────────────────────────── */
        'coaches'              => ['coach' => '_id',                'staff' => null,                 'client' => null],
        'refresh_tokens'       => ['coach' => null,                 'staff' => null,                 'client' => null], // handled separately
        'rate_limits'          => ['coach' => null,                 'staff' => null,                 'client' => null], // handled separately
    ];

    /**
     * Ownership filter for ($collection, current role), or null when:
     *   - no role/userId in context,
     *   - collection or role has no RLS entry, or
     *   - the JWT 'sub' is not a valid ObjectId (malformed/forged —
     *     we fall through to the caller's manual filter rather than 500).
     */
    public static function ownershipFilter(string $collection): ?array
    {
        $role = RequestContext::getRole();
        $userId = RequestContext::getUserId();

        if (!$role || !$userId) {
            return null;
        }

        if (!isset(self::OWNERSHIP_MAP[$collection])) {
            return null; // No RLS configured — allow manual filtering
        }

        $field = self::OWNERSHIP_MAP[$collection][$role] ?? null;

        if ($field === null) {
            return null; // Role has no default access to this collection
        }

        // Staff JWTs are scoped by org_id (coach_id), not the individual staff sub
        if ($role === 'staff') {
            $orgId = RequestContext::getCoachId();
            if (!$orgId) {
                return null;
            }
            $oid = self::safeObjectId($orgId);
            if ($oid === null) {
                return null;
            }
            if (is_callable($field)) {
                return $field($oid);
            }
            return [$field => $oid];
        }

        $oid = self::safeObjectId($userId);
        if ($oid === null) {
            return null; // Malformed sub — bail to manual filter
        }

        if (is_callable($field)) {
            return $field($oid);
        }

        return [$field => $oid];
    }

    /**
     * Build an ObjectId from a string, returning null on invalid input
     * instead of throwing. Centralises the validation that the
     * OWNERSHIP_MAP requires.
     */
    private static function safeObjectId(string $id): ?ObjectId
    {
        try {
            return new ObjectId($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Client-role filter for `nutrition_plans`: a plan is visible to a
     * client if they are the primary `client_id` OR they appear in the
     * `client_ids` group array.
     */
    public static function nutritionPlansClientFilter(ObjectId $userId): array
    {
        return [
            '$or' => [
                ['client_id'  => $userId],
                ['client_ids' => ['$in' => [$userId]]],
            ],
        ];
    }

    /**
     * Client-role filter for `workout_plans`: a plan is visible to a
     * client if they are the primary `client_id` OR they appear in the
     * `client_ids` group array.
     */
    public static function workoutPlansClientFilter(ObjectId $userId): array
    {
        return [
            '$or' => [
                ['client_id'  => $userId],
                ['client_ids' => ['$in' => [$userId]]],
            ],
        ];
    }

    /**
     * Client-role filter for `live_training_sessions`: a client sees
     * sessions hosted by their own coach. The coach_id is read from
     * RequestContext (populated by ClientMiddleware from the JWT
     * lookup). If it is missing, return a filter that matches
     * nothing rather than leaking all sessions.
     */
    public static function liveTrainingClientFilter(ObjectId $userId): array
    {
        $coachId = RequestContext::getCoachId();
        if ($coachId) {
            try {
                return ['coach_id' => new ObjectId($coachId)];
            } catch (\Throwable) {
                // fall through to safe fallback
            }
        }
        // ObjectId(24×'0') is reserved (all-zero); no real document
        // will ever match, so this safely returns an empty result.
        return ['_id' => new ObjectId('000000000000000000000000')];
    }

    /**
     * Merge the ownership filter into an existing query filter.
     * If no ownership is configured, returns the filter unchanged.
     */
    public static function apply(string $collection, array $filter): array
    {
        $ownership = self::ownershipFilter($collection);

        if ($ownership === null) {
            return $filter;
        }

        if (empty($filter)) {
            return $ownership;
        }

        // If filter already has $and, append to it
        if (isset($filter['$and'])) {
            $filter['$and'][] = $ownership;
            return $filter;
        }

        // Otherwise wrap in $and
        return ['$and' => [$filter, $ownership]];
    }
}
