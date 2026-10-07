<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Exercise Library Service — caches exercises from WGER API.
 *
 * WGER (wger.de) is a free, open-source fitness database with 2000+ exercises,
 * muscle groups, equipment, and images. No API key required.
 *
 * Endpoints:
 *   GET https://wger.de/api/v2/exercise/?language=2&limit=...
 *   GET https://wger.de/api/v2/exerciseinfo/{id}/
 *   GET https://wger.de/api/v2/exerciseimage/?exercise={id}
 *
 * We cache exercises in the local `exercises` collection to avoid rate limits
 * and to support full-text search.
 */
class ExerciseLibraryService
{
    private const WGER_BASE = 'https://wger.de/api/v2';
    private const CACHE_TTL_DAYS = 7;

    /**
     * Search cached exercises by name, category, or muscle.
     */
    public function searchExercises(string $query, ?string $category = null, ?string $muscle = null, int $page = 1, int $perPage = 20): array
    {
        $filter = [];
        if ($query !== '') {
            $filter['$text'] = ['$search' => $query];
        }
        if ($category !== null && $category !== '') {
            $filter['category'] = $category;
        }
        if ($muscle !== null && $muscle !== '') {
            $filter['muscles'] = ['$in' => [$muscle]];
        }

        $col = Database::collection('exercises');
        $total = $col->countDocuments($filter);
        $docs = $col->find($filter, [
            'skip'  => ($page - 1) * $perPage,
            'limit' => $perPage,
            'sort'  => $query !== '' ? ['score' => ['$meta' => 'textScore']] : ['name' => 1],
        ])->toArray();

        return [
            'data'       => array_map(fn($d) => $this->format($d), $docs),
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get a single cached exercise by its internal MongoDB ID.
     */
    public function getExerciseById(string $id): ?array
    {
        $doc = Database::collection('exercises')->findOne(['_id' => new ObjectId($id)]);
        return $doc ? $this->format($doc) : null;
    }

    /**
     * Get a single cached exercise by its WGER external ID.
     */
    public function getExerciseByExternalId(int $externalId): ?array
    {
        $doc = Database::collection('exercises')->findOne(['external_id' => $externalId]);
        return $doc ? $this->format($doc) : null;
    }

    /**
     * Sync exercises from WGER into the local cache.
     * Returns number of exercises synced.
     */
    public function syncExercises(): int
    {
        $count = 0;

        // 1. Fetch categories
        $categories = $this->wgerGet('/exercisecategory/');
        $categoryMap = [];
        foreach ($categories['results'] ?? [] as $cat) {
            $categoryMap[$cat['id']] = $cat['name'];
        }

        // 2. Fetch muscles
        $muscles = $this->wgerGet('/muscle/');
        $muscleMap = [];
        foreach ($muscles['results'] ?? [] as $m) {
            $muscleMap[$m['id']] = $m['name'];
        }

        // 3. Fetch equipment
        $equipment = $this->wgerGet('/equipment/');
        $equipmentMap = [];
        foreach ($equipment['results'] ?? [] as $eq) {
            $equipmentMap[$eq['id']] = $eq['name'];
        }

        // 4. Fetch exercises (language=2 is English)
        $url = '/exercise/?language=2&limit=999';
        while ($url) {
            $data = $this->wgerGet($url);
            if (empty($data['results'])) break;

            foreach ($data['results'] as $ex) {
                $this->upsertExercise($ex, $categoryMap, $muscleMap, $equipmentMap);
                $count++;
            }

            $url = $data['next'] ?? null;
            // Strip base URL to get relative path for next iteration
            if (is_string($url)) {
                $url = str_replace(self::WGER_BASE, '', $url);
            }
        }

        return $count;
    }

    /**
     * Fetch and store images for a single exercise.
     */
    public function syncExerciseImages(int $externalId): void
    {
        $images = $this->wgerGet("/exerciseimage/?exercise={$externalId}&limit=50");
        $imageUrls = [];
        foreach ($images['results'] ?? [] as $img) {
            if (!empty($img['image'])) {
                $imageUrls[] = $img['image'];
            }
        }

        if (!empty($imageUrls)) {
            Database::collection('exercises')->updateOne(
                ['external_id' => $externalId],
                ['$set' => ['images' => $imageUrls, 'updated_at' => new UTCDateTime()]]
            );
        }
    }

    private function upsertExercise(array $ex, array $categoryMap, array $muscleMap, array $equipmentMap): void
    {
        $externalId = (int) $ex['id'];
        $category = $categoryMap[$ex['category'] ?? 0] ?? 'Unknown';

        $muscles = [];
        foreach ($ex['muscles'] ?? [] as $mid) {
            if (isset($muscleMap[$mid])) $muscles[] = $muscleMap[$mid];
        }
        foreach ($ex['muscles_secondary'] ?? [] as $mid) {
            if (isset($muscleMap[$mid])) $muscles[] = $muscleMap[$mid];
        }
        $muscles = array_values(array_unique($muscles));

        $equipments = [];
        foreach ($ex['equipment'] ?? [] as $eid) {
            if (isset($equipmentMap[$eid])) $equipments[] = $equipmentMap[$eid];
        }

        $doc = [
            'external_id'  => $externalId,
            'name'         => trim($ex['name'] ?? ''),
            'category'     => $category,
            'muscles'      => $muscles,
            'equipment'    => $equipments,
            'description'  => strip_tags($ex['description'] ?? ''),
            'images'       => [],
            'source'       => 'wger',
            'updated_at'   => new UTCDateTime(),
        ];

        Database::collection('exercises')->updateOne(
            ['external_id' => $externalId],
            ['$set' => $doc, '$setOnInsert' => ['created_at' => new UTCDateTime()]],
            ['upsert' => true]
        );
    }

    private function wgerGet(string $endpoint): array
    {
        $url = self::WGER_BASE . $endpoint;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException("WGER API request failed: {$url} (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("WGER API returned invalid JSON: {$url}");
        }

        return $data;
    }

    private function format(object $doc): array
    {
        $d = (array) $doc;
        return [
            'id'          => (string) $d['_id'],
            'external_id' => $d['external_id'] ?? 0,
            'name'        => $d['name'] ?? '',
            'category'    => $d['category'] ?? '',
            'muscles'     => $d['muscles'] ?? [],
            'equipment'   => $d['equipment'] ?? [],
            'description' => $d['description'] ?? '',
            'images'      => $d['images'] ?? [],
            'source'      => $d['source'] ?? 'wger',
        ];
    }
}
