<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\RequestContext;
use App\Helpers\RlsScope;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;

final class RlsScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
    }

    public function test_ownership_filter_returns_null_when_no_auth(): void
    {
        $this->assertNull(RlsScope::ownershipFilter('clients'));
    }

    public function test_ownership_filter_returns_null_for_unknown_collection(): void
    {
        RequestContext::setAuth(['sub' => '000000000000000000000000', 'role' => 'coach']);
        $this->assertNull(RlsScope::ownershipFilter('unknown_collection'));
    }

    public function test_ownership_filter_returns_null_when_role_has_no_access(): void
    {
        // workout_logs has coach => null
        RequestContext::setAuth(['sub' => '000000000000000000000000', 'role' => 'coach']);
        $this->assertNull(RlsScope::ownershipFilter('workout_logs'));
    }

    public function test_coach_ownership_filter_for_coach_owned_collection(): void
    {
        $oid = new ObjectId();
        RequestContext::setAuth(['sub' => (string) $oid, 'role' => 'coach']);

        $expected = ['coach_id' => $oid];
        $this->assertEquals($expected, RlsScope::ownershipFilter('clients'));
        $this->assertEquals($expected, RlsScope::ownershipFilter('coaching_sessions'));
        $this->assertEquals($expected, RlsScope::ownershipFilter('checkin_meetings'));
    }

    public function test_client_ownership_filter_for_client_owned_collection(): void
    {
        $oid = new ObjectId();
        RequestContext::setAuth(['sub' => (string) $oid, 'role' => 'client']);

        $this->assertEquals(['client_id' => $oid], RlsScope::ownershipFilter('workout_logs'));
        $this->assertEquals(['client_id' => $oid], RlsScope::ownershipFilter('notifications'));
    }

    public function test_client_ownership_filter_for_workout_plans(): void
    {
        $oid = new ObjectId();
        RequestContext::setAuth(['sub' => (string) $oid, 'role' => 'client']);

        $filter = RlsScope::ownershipFilter('workout_plans');
        $this->assertArrayHasKey('$or', $filter);
        $this->assertCount(2, $filter['$or']);
    }

    public function test_client_ownership_filter_for_live_training_with_coach_context(): void
    {
        $clientOid = new ObjectId();
        $coachOid  = new ObjectId();
        RequestContext::setAuth([
            'sub'      => (string) $clientOid,
            'role'     => 'client',
            'coach_id' => (string) $coachOid,
        ]);

        $this->assertEquals(
            ['coach_id' => $coachOid],
            RlsScope::ownershipFilter('live_training_sessions')
        );
    }

    public function test_client_ownership_filter_for_live_training_without_coach_context(): void
    {
        $clientOid = new ObjectId();
        RequestContext::setAuth([
            'sub'  => (string) $clientOid,
            'role' => 'client',
        ]);

        // Falls back to an impossible _id match so nothing leaks
        $filter = RlsScope::ownershipFilter('live_training_sessions');
        $this->assertArrayHasKey('_id', $filter);
    }

    public function test_apply_merges_ownership_into_empty_filter(): void
    {
        $oid = new ObjectId();
        RequestContext::setAuth(['sub' => (string) $oid, 'role' => 'coach']);

        $this->assertEquals(
            ['coach_id' => $oid],
            RlsScope::apply('clients', [])
        );
    }

    public function test_apply_merges_ownership_into_existing_filter(): void
    {
        $oid = new ObjectId();
        RequestContext::setAuth(['sub' => (string) $oid, 'role' => 'coach']);

        $result = RlsScope::apply('clients', ['active' => true]);
        $this->assertArrayHasKey('$and', $result);
        $this->assertCount(2, $result['$and']);
    }

    public function test_apply_appends_to_existing_and(): void
    {
        $oid = new ObjectId();
        RequestContext::setAuth(['sub' => (string) $oid, 'role' => 'coach']);

        $existing = ['$and' => [['status' => 'active']]];
        $result   = RlsScope::apply('clients', $existing);

        $this->assertCount(2, $result['$and']);
        $this->assertEquals(['status' => 'active'], $result['$and'][0]);
        $this->assertEquals(['coach_id' => $oid], $result['$and'][1]);
    }
}
