<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\WorkoutAnalysisService;
use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

class WorkoutAnalysisServiceTest extends TestCase
{
    private string $coachId;
    private string $clientId;
    private WorkoutAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$testDb === null) {
            $this->markTestSkipped('MongoDB not available');
        }

        $this->service = new WorkoutAnalysisService();
        $this->coachId  = (string) new ObjectId();
        $this->clientId = (string) new ObjectId();

        Database::collection('coaches')->insertOne([
            '_id' => new ObjectId($this->coachId),
            'email' => 'coach@example.com',
            'name' => 'Test Coach',
        ]);

        Database::collection('clients')->insertOne([
            '_id'       => new ObjectId($this->clientId),
            'coach_id'  => new ObjectId($this->coachId),
            'name'      => 'Test Client',
            'email'     => 'client@example.com',
            'active'    => true,
            'language'  => 'en',
            'created_at' => new UTCDateTime(),
        ]);
    }

    private function seedLogs(int $count, int $startWeight = 60, int $increment = 2): void
    {
        $now = time();
        for ($i = 0; $i < $count; $i++) {
            Database::collection('workout_logs')->insertOne([
                'client_id'       => new ObjectId($this->clientId),
                'workout_plan_id' => new ObjectId(),
                'day'             => 'day_' . $i,
                'exercises'       => [
                    [
                        'name' => 'Squat',
                        'sets_completed' => [
                            ['set_number' => 1, 'kg' => $startWeight + $i * $increment, 'reps_done' => 8],
                            ['set_number' => 2, 'kg' => $startWeight + $i * $increment, 'reps_done' => 8],
                        ],
                    ],
                ],
                'completed_at'    => new UTCDateTime(($now - $i * 86400) * 1000),
            ]);
        }
    }

    private function seedPlan(int $days = 10): void
    {
        Database::collection('workout_plans')->insertOne([
            'coach_id'   => new ObjectId($this->coachId),
            'client_id'  => new ObjectId($this->clientId),
            'title'      => 'Test Plan',
            'status'     => 'active',
            'week_start' => new UTCDateTime(),
            'days'       => array_fill(0, $days, ['day' => 'day', 'exercises' => []]),
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);
    }

    public function testAnalyzeReturnsCorrectStructure(): void
    {
        $this->seedLogs(5);
        $this->seedPlan();

        $result = $this->service->analyze($this->clientId, $this->coachId);

        $this->assertSame('pending', $result['status']);
        $this->assertSame($this->clientId, $result['client_id']);
        $this->assertSame($this->coachId, $result['coach_id']);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertNotEmpty($result['recommendations']);

        $metrics = $result['metrics'];
        $this->assertArrayHasKey('completion_rate', $metrics);
        $this->assertArrayHasKey('consistency_score', $metrics);
        $this->assertArrayHasKey('progression_score', $metrics);
        $this->assertArrayHasKey('pr_count_30d', $metrics);
        $this->assertArrayHasKey('streak_days', $metrics);
        $this->assertArrayHasKey('total_volume_lifted_kg', $metrics);
    }

    public function testAnalyzeWithEmptyLogs(): void
    {
        $this->seedPlan();

        $result = $this->service->analyze($this->clientId, $this->coachId);

        $this->assertSame('pending', $result['status']);
        $metrics = $result['metrics'];
        $this->assertSame(0.0, $metrics['completion_rate']);
        $this->assertSame(0, $metrics['total_volume_lifted_kg']);
        $this->assertSame(0, $metrics['consistency_score']);
        $this->assertNotEmpty($result['recommendations']);
    }

    public function testAnalyzeDetectsPositiveProgression(): void
    {
        // Seed logs with increasing weight
        $this->seedLogs(20, 60, 3);
        $this->seedPlan(20);

        $result = $this->service->analyze($this->clientId, $this->coachId);
        $metrics = $result['metrics'];

        $this->assertGreaterThan(0, $metrics['total_volume_lifted_kg']);
        $this->assertGreaterThanOrEqual(0, $metrics['progression_score']);

        // With steadily increasing weights, should get a positive recommendation
        $rec = $result['recommendations'][0];
        $this->assertArrayHasKey('confidence', $rec);
        $this->assertArrayHasKey('reasoning', $rec);
        $this->assertArrayHasKey('focus_area', $rec);
        $this->assertGreaterThan(0, $rec['confidence']);
        $this->assertLessThanOrEqual(1.0, $rec['confidence']);
    }

    public function testAnalyzeWithBodyMeasurements(): void
    {
        $this->seedLogs(5);
        $this->seedPlan();

        $now = time();
        Database::collection('body_measurements')->insertOne([
            'client_id'   => new ObjectId($this->clientId),
            'weight_kg'   => 85.0,
            'body_fat_pct' => 20.0,
            'recorded_at' => new UTCDateTime(($now - 60 * 86400) * 1000),
        ]);
        Database::collection('body_measurements')->insertOne([
            'client_id'   => new ObjectId($this->clientId),
            'weight_kg'   => 82.0,
            'body_fat_pct' => 17.0,
            'recorded_at' => new UTCDateTime($now * 1000),
        ]);

        $result = $this->service->analyze($this->clientId, $this->coachId);
        $metrics = $result['metrics'];

        $this->assertSame(-3.0, $metrics['body_weight_change_kg']);
        $this->assertSame(-3.0, $metrics['body_fat_change_pct']);

        // Should include a body-composition recommendation
        $focusAreas = array_map(fn($r) => $r['focus_area'], $result['recommendations']);
        $this->assertContains('endurance', $focusAreas);
    }

    public function testAnalyzeThrowsForMissingClient(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->analyze((string) new ObjectId(), $this->coachId);
    }
}
