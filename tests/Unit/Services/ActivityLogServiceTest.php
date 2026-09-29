<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Helpers\RequestContext;
use App\Services\ActivityLogService;
use PHPUnit\Framework\TestCase;

final class ActivityLogServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
    }

    public function test_log_returns_early_when_no_auth(): void
    {
        // No auth set — should return silently without throwing
        $this->expectNotToPerformAssertions();
        ActivityLogService::log('client_created', '000000000000000000000001', 'Alice');
    }

    public function test_log_returns_early_for_client_role(): void
    {
        RequestContext::setAuth([
            'sub'      => '000000000000000000000001',
            'role'     => 'client',
            'name'     => 'Alice',
            'coach_id' => '000000000000000000000002',
        ]);

        $this->expectNotToPerformAssertions();
        ActivityLogService::log('client_created', '000000000000000000000001', 'Alice');
    }

    public function test_log_returns_early_when_org_id_missing(): void
    {
        RequestContext::setAuth([
            'sub'  => '000000000000000000000001',
            'role' => 'staff',
            'name' => 'Bob',
            // coach_id / org_id intentionally missing
        ]);

        $this->expectNotToPerformAssertions();
        ActivityLogService::log('client_created', '000000000000000000000001', 'Alice');
    }
}
