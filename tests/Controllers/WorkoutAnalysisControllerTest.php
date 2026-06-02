<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\WorkoutAnalysisController;
use App\Config\Database;
use App\Helpers\Request;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\TestCase;

class WorkoutAnalysisControllerTest extends TestCase
{
    private string $coachId;
    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Request::setTestBody(null);
        if (self::$testDb === null) {
            $this->markTestSkipped('MongoDB not available');
        }

        $this->coachId  = (string) new ObjectId();
        $this->clientId = (string) new ObjectId();

        // Seed coach
        Database::collection('coaches')->insertOne([
            '_id' => new ObjectId($this->coachId),
            'email' => 'coach@example.com',
            'name' => 'Test Coach',
        ]);

        // Seed client
        Database::collection('clients')->insertOne([
            '_id'       => new ObjectId($this->clientId),
            'coach_id'  => new ObjectId($this->coachId),
            'name'      => 'Test Client',
            'email'     => 'client@example.com',
            'active'    => true,
            'language'  => 'en',
            'created_at' => new UTCDateTime(),
        ]);

        // Seed workout logs (90 days)
        $now = time();
        for ($i = 0; $i < 10; $i++) {
            Database::collection('workout_logs')->insertOne([
                'client_id'       => new ObjectId($this->clientId),
                'workout_plan_id' => new ObjectId(),
                'day'             => 'day_' . $i,
                'exercises'       => [
                    [
                        'name' => 'Bench Press',
                        'sets_completed' => [
                            ['set_number' => 1, 'kg' => 80 + $i * 2, 'reps_done' => 8],
                            ['set_number' => 2, 'kg' => 80 + $i * 2, 'reps_done' => 8],
                        ],
                    ],
                ],
                'completed_at'    => new UTCDateTime(($now - $i * 86400) * 1000),
            ]);
        }

        // Seed workout plan
        Database::collection('workout_plans')->insertOne([
            'coach_id'   => new ObjectId($this->coachId),
            'client_id'  => new ObjectId($this->clientId),
            'title'      => 'Test Plan',
            'status'     => 'active',
            'week_start' => new UTCDateTime(),
            'days'       => array_fill(0, 12, ['day' => 'day', 'exercises' => []]),
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);

        // Seed body measurements
        Database::collection('body_measurements')->insertOne([
            'client_id'  => new ObjectId($this->clientId),
            'weight_kg'  => 80.0,
            'chest_cm'   => 100,
            'waist_cm'   => 85,
            'hips_cm'    => 95,
            'body_fat_pct' => 18.0,
            'recorded_at' => new UTCDateTime(($now - 60 * 86400) * 1000),
        ]);
        Database::collection('body_measurements')->insertOne([
            'client_id'  => new ObjectId($this->clientId),
            'weight_kg'  => 78.5,
            'chest_cm'   => 101,
            'waist_cm'   => 83,
            'hips_cm'    => 94,
            'body_fat_pct' => 16.5,
            'recorded_at' => new UTCDateTime($now * 1000),
        ]);
    }

    public function testCreateAnalysis(): void
    {
        $e = $this->callController(WorkoutAnalysisController::class, 'create', [
            '_auth' => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'    => $this->clientId,
        ]);

        $this->assertSame(201, $e->getHttpCode());
        $data = $e->getData();
        $this->assertSame('pending', $data['data']['status']);
        $this->assertNotEmpty($data['data']['metrics']);
        $this->assertNotEmpty($data['data']['recommendations']);
        $this->assertSame($this->clientId, $data['data']['client_id']);
    }

    public function testCreateAnalysisRejectsDuplicatePending(): void
    {
        // Create first analysis
        $this->callController(WorkoutAnalysisController::class, 'create', [
            '_auth' => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'    => $this->clientId,
        ]);

        // Second should fail
        $e = $this->callController(WorkoutAnalysisController::class, 'create', [
            '_auth' => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'    => $this->clientId,
        ]);

        $this->assertSame(409, $e->getHttpCode());
    }

    public function testListAnalyses(): void
    {
        // Seed an analysis directly
        Database::collection('client_workout_analyses')->insertOne([
            'client_id'       => new ObjectId($this->clientId),
            'coach_id'        => new ObjectId($this->coachId),
            'status'          => 'approved',
            'generated_at'    => new UTCDateTime(),
            'metrics'         => ['completion_rate' => 0.8],
            'recommendations' => [],
        ]);

        $e = $this->callController(WorkoutAnalysisController::class, 'index', [
            '_auth' => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'    => $this->clientId,
        ]);

        $this->assertSame(200, $e->getHttpCode());
        $data = $e->getData();
        $this->assertCount(1, $data['data']);
        $this->assertSame('approved', $data['data'][0]['status']);
    }

    public function testApproveAnalysis(): void
    {
        $result = Database::collection('client_workout_analyses')->insertOne([
            'client_id'       => new ObjectId($this->clientId),
            'coach_id'        => new ObjectId($this->coachId),
            'status'          => 'pending',
            'generated_at'    => new UTCDateTime(),
            'metrics'         => [],
            'recommendations' => [],
        ]);
        $analysisId = (string) $result->getInsertedId();

        $e = $this->callController(WorkoutAnalysisController::class, 'approve', [
            '_auth'      => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'         => $this->clientId,
            'analysisId' => $analysisId,
        ]);

        $this->assertSame(200, $e->getHttpCode());

        $doc = Database::collection('client_workout_analyses')->findOne(['_id' => $result->getInsertedId()]);
        $this->assertSame('approved', $doc['status']);
    }

    public function testRejectAnalysis(): void
    {
        $result = Database::collection('client_workout_analyses')->insertOne([
            'client_id'       => new ObjectId($this->clientId),
            'coach_id'        => new ObjectId($this->coachId),
            'status'          => 'pending',
            'generated_at'    => new UTCDateTime(),
            'metrics'         => [],
            'recommendations' => [],
        ]);
        $analysisId = (string) $result->getInsertedId();

        Request::setTestBody(json_encode(['coach_notes' => 'Not suitable right now']));
        $e = $this->callController(WorkoutAnalysisController::class, 'reject', [
            '_auth'      => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'         => $this->clientId,
            'analysisId' => $analysisId,
        ]);

        $this->assertSame(200, $e->getHttpCode());

        $doc = Database::collection('client_workout_analyses')->findOne(['_id' => $result->getInsertedId()]);
        $this->assertSame('rejected', $doc['status']);
        $this->assertSame('Not suitable right now', $doc['coach_notes']);
    }

    public function testAssignPlanWithExistingPlanId(): void
    {
        $planResult = Database::collection('workout_plans')->insertOne([
            'coach_id'   => new ObjectId($this->coachId),
            'client_id'  => new ObjectId($this->clientId),
            'title'      => 'Assigned Plan',
            'status'     => 'active',
            'week_start' => new UTCDateTime(),
            'days'       => [],
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
        ]);
        $planId = (string) $planResult->getInsertedId();

        $result = Database::collection('client_workout_analyses')->insertOne([
            'client_id'       => new ObjectId($this->clientId),
            'coach_id'        => new ObjectId($this->coachId),
            'status'          => 'pending',
            'generated_at'    => new UTCDateTime(),
            'metrics'         => [],
            'recommendations' => [],
        ]);
        $analysisId = (string) $result->getInsertedId();

        Request::setTestBody(json_encode(['plan_id' => $planId]));
        $e = $this->callController(WorkoutAnalysisController::class, 'assign', [
            '_auth'      => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'         => $this->clientId,
            'analysisId' => $analysisId,
        ]);

        $this->assertSame(200, $e->getHttpCode());
        $data = $e->getData();
        $this->assertSame($planId, $data['data']['assigned_plan_id']);

        $doc = Database::collection('client_workout_analyses')->findOne(['_id' => $result->getInsertedId()]);
        $this->assertSame('assigned', $doc['status']);
    }

    public function testRecommendationsPreview(): void
    {
        $e = $this->callController(WorkoutAnalysisController::class, 'recommendations', [
            '_auth' => ['sub' => $this->coachId, 'role' => 'coach'],
            'id'    => $this->clientId,
        ]);

        $this->assertSame(200, $e->getHttpCode());
        $data = $e->getData();
        $this->assertSame($this->clientId, $data['data']['client_id']);
        $this->assertNotEmpty($data['data']['metrics']);
        $this->assertNotEmpty($data['data']['recommendations']);
    }
}
