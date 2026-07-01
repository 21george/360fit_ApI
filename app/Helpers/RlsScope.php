<?php

declare(strict_types=1);

namespace App\Helpers;

use MongoDB\BSON\ObjectId;

/**
 * Row-Level Security scope configuration.
 * Maps MongoDB collections to ownership fields for each user role.
 */
class RlsScope
{
    /**
     * Collection → ownership field mapping.
     * Key: collection name
     * Value: [
     *   'coach' => field name OR callable that returns filter array,
     *   'client' => field name OR callable that returns filter array,
     * ]
     */
    private const OWNERSHIP_MAP = [
        /* ── Coach-owned resources ───────────────────────────────────────── */
        'clients'              => ['coach' => 'coach_id',           'client' => '_id'],
        'workout_plans'        => ['coach' => 'coach_id',           'client' => null], // complex: client_id OR client_ids
        'nutrition_plans'      => ['coach' => 'coach_id',           'client' => 'client_id'],
        'checkin_meetings'     => ['coach' => 'coach_id',           'client' => 'client_id'],
        'coaching_sessions'    => ['coach' => 'coach_id',           'client' => 'client_id'],
        'live_training_sessions' => ['coach' => 'coach_id',       'client' => null],

        /* ── Client-owned resources ──────────────────────────────────────── */
        'workout_logs'         => ['coach' => null,                 'client' => 'client_id'],
        'body_measurements'    => ['coach' => null,                 'client' => 'client_id'],
        'media_uploads'        => ['coach' => null,                 'client' => 'client_id'],
        'messages'             => ['coach' => null,                 'client' => 'client_id'],
        'notifications'        => ['coach' => null,                 'client' => 'client_id'],
        'message_typing_status'=> ['coach' => null,                 'client' => 'client_id'],

        /* ── Self resources ────────────────────────────────────────────── */
        'coaches'              => ['coach' => '_id',                'client' => null],
        'refresh_tokens'       => ['coach' => null,                 'client' => null], // handled separately
        'rate_limits'          => ['coach' => null,                 'client' => null], // handled separately
    ];

    /**
     * Return the ownership filter for a collection and role.
     * Returns null if the role has no access to this collection
     * or if no RLS is configured (allow manual filtering).
     *
     * If the JWT 'sub' is not a valid ObjectId string (which would
     * only happen with a forged or malformed token — the JWT decode
     * itself does not validate ObjectId format), we return null
     * ownership rather than letting an InvalidArgumentException
     * propagate to a 500. Callers will fall through to whatever
     * application-level filters they have already applied.
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
