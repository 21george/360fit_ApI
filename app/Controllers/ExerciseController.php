<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Request, Response};
use App\Services\ExerciseLibraryService;
use MongoDB\BSON\ObjectId;

/**
 * Exercise Library Controller
 *
 * Provides cached access to the WGER exercise database.
 */
class ExerciseController
{
    private ExerciseLibraryService $service;

    public function __construct()
    {
        $this->service = new ExerciseLibraryService();
    }

    /**
     * GET /exercises
     *
     * Search exercises by name, category, or muscle.
     * Query: q (text search), category, muscle, page, per_page
     */
    public function index(array $params): void
    {
        $query = trim((string) Request::get('q', ''));
        $category = Request::get('category');
        $muscle = Request::get('muscle');
        $page = max(1, (int) Request::get('page', 1));
        $perPage = min(50, max(1, (int) Request::get('per_page', 20)));

        $result = $this->service->searchExercises(
            $query,
            is_string($category) ? $category : null,
            is_string($muscle) ? $muscle : null,
            $page,
            $perPage
        );

        Response::paginated($result['data'], $result['total'], $result['page'], $result['per_page']);
    }

    /**
     * GET /exercises/:id
     *
     * Get a single exercise by its internal MongoDB ID.
     */
    public function show(array $params): void
    {
        $id = $params['id'] ?? '';
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            Response::error('Invalid exercise ID', 400);
        }

        $exercise = $this->service->getExerciseById($id);
        if (!$exercise) {
            Response::error('Exercise not found', 404);
        }

        Response::success($exercise);
    }

    /**
     * POST /exercises/sync
     *
     * Trigger a full sync from WGER API. Restricted to owner/admin.
     */
    public function sync(array $params): void
    {
        $role = $params['_role'] ?? null;
        if (!in_array($role, ['owner', 'admin'], true)) {
            Response::error('Forbidden', 403);
        }

        try {
            $count = $this->service->syncExercises();
            Response::success(['synced' => $count], "{$count} exercise(s) synced from WGER");
        } catch (\Throwable $e) {
            error_log('Exercise sync failed: ' . $e->getMessage());
            Response::error('Failed to sync exercises from external source', 502);
        }
    }

    /**
     * POST /exercises/:id/sync-images
     *
     * Fetch and store images for a single exercise from WGER.
     */
    public function syncImages(array $params): void
    {
        $id = $params['id'] ?? '';
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            Response::error('Invalid exercise ID', 400);
        }

        $exercise = $this->service->getExerciseById($id);
        if (!$exercise) {
            Response::error('Exercise not found', 404);
        }

        try {
            $this->service->syncExerciseImages($exercise['external_id']);
            $updated = $this->service->getExerciseById($id);
            Response::success($updated, 'Images synced');
        } catch (\Throwable $e) {
            error_log('Exercise image sync failed: ' . $e->getMessage());
            Response::error('Failed to sync images', 502);
        }
    }
}
