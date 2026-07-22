<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;

/**
 * USDA FoodData Central API wrapper with local caching.
 *
 * Queries the USDA database for food nutritional information,
 * caches results locally in the `foods` collection to reduce
 * external API calls and allow offline usage.
 */
class FoodService
{
    private const BASE_URL = 'https://api.nal.usda.gov/fdc/v1';
    private const PAGE_SIZE = 25;

    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = $_ENV['USDA_API_KEY'] ?? null;
    }

    /**
     * Search foods by name via the USDA API.
     *
     * @return array{foods: list<array>, totalHits: int}
     */
    public function searchFoods(string $query, int $page = 1): array
    {
        $url = self::BASE_URL . '/foods/search';
        if ($this->apiKey) {
            $url .= '?' . http_build_query(['api_key' => $this->apiKey]);
        }

        $body = json_encode([
            'query'      => $query,
            'pageSize'   => self::PAGE_SIZE,
            'pageNumber' => max(1, $page),
            'dataType'   => ['Foundation', 'SR Legacy', 'Survey (FNDDS)', 'Branded'],
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $result === false) {
            throw new \RuntimeException('USDA API search failed (HTTP ' . $code . ')');
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid USDA API response');
        }

        $foods = [];
        foreach ($data['foods'] ?? [] as $item) {
            $foods[] = $this->normalizeFoodItem($item);
        }

        // Cache USDA results locally
        $this->cacheFoods($foods);

        return [
            'foods'     => $foods,
            'totalHits' => (int) ($data['totalHits'] ?? 0),
        ];
    }

    /**
     * Get detailed nutrient data for a specific FDC ID.
     *
     * @return array{name: string, fdc_id: int, nutrients_per_100g: array}
     */
    public function getFoodDetails(int $fdcId): array
    {
        // Try local cache first
        $cached = Database::collection('foods')->findOne([
            'source'  => 'usda',
            'fdc_id'  => $fdcId,
        ]);
        if ($cached) {
            return $this->normalizeCachedFood($cached);
        }

        $url = self::BASE_URL . "/food/{$fdcId}";
        if ($this->apiKey) {
            $url .= '?' . http_build_query(['api_key' => $this->apiKey]);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $result === false) {
            throw new \RuntimeException('USDA API food details failed (HTTP ' . $code . ')');
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid USDA API response');
        }

        $food = $this->normalizeFoodItem($data);
        $this->cacheFoods([$food]);

        return $food;
    }

    /**
     * Search local foods collection (system + coach custom foods).
     *
     * @return list<array{name: string, fdc_id: int|null, nutrients_per_100g: array, source: string}>
     */
    public function searchLocalFoods(string $query, ?ObjectId $coachId = null): array
    {
        $nameFilter = [
            '$or' => [
                ['name' => ['$regex' => $query, '$options' => 'i']],
                ['name' => ['$regex' => '\\b' . preg_quote($query, '/') . '\\b', '$options' => 'i']],
            ],
        ];

        // Coaches see system foods (coach_id: null) + their own custom foods
        // Clients only see system foods
        $ownershipFilter = $coachId
            ? ['$or' => [['coach_id' => null], ['coach_id' => $coachId]]]
            : ['coach_id' => null];

        $filter = ['$and' => [$nameFilter, $ownershipFilter]];

        $docs = Database::collection('foods')
            ->find($filter, ['limit' => self::PAGE_SIZE])
            ->toArray();

        return array_map(fn($doc) => $this->normalizeCachedFood($doc), $docs);
    }

    /**
     * Create a custom food entry in the local database.
     */
    public function createCustomFood(ObjectId $coachId, string $name, array $nutrientsPer100g): array
    {
        $now = new \MongoDB\BSON\UTCDateTime();
        $result = Database::collection('foods')->insertOne([
            'name'               => $name,
            'source'             => 'custom',
            'fdc_id'             => null,
            'coach_id'           => $coachId,
            'nutrients_per_100g' => $nutrientsPer100g,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        return [
            'id'                 => (string) $result->getInsertedId(),
            'name'               => $name,
            'source'             => 'custom',
            'nutrients_per_100g' => $nutrientsPer100g,
        ];
    }

    /**
     * Normalize a USDA API response item into our standard format.
     */
    private function normalizeFoodItem(array $item): array
    {
        $nutrients = $this->extractNutrients($item['foodNutrients'] ?? []);

        return [
            'name'               => $item['description'] ?? ($item['description'] ?? 'Unknown'),
            'fdc_id'             => (int) ($item['fdcId'] ?? 0),
            'nutrients_per_100g' => $nutrients,
            'source'             => 'usda',
        ];
    }

    /**
     * Normalize a locally cached MongoDB document.
     */
    private function normalizeCachedFood(object $doc): array
    {
        $data = (array) $doc;
        return [
            'id'                 => isset($data['_id']) ? (string) $data['_id'] : null,
            'name'               => $data['name'] ?? 'Unknown',
            'fdc_id'             => isset($data['fdc_id']) ? (int) $data['fdc_id'] : null,
            'nutrients_per_100g' => $data['nutrients_per_100g'] ?? [],
            'source'             => $data['source'] ?? 'usda',
        ];
    }

    /**
     * Cache USDA food items in the local `foods` collection.
     */
    private function cacheFoods(array $foods): void
    {
        foreach ($foods as $food) {
            if (empty($food['fdc_id']) || $food['source'] !== 'usda') {
                continue;
            }

            Database::collection('foods')->updateOne(
                ['source' => 'usda', 'fdc_id' => $food['fdc_id']],
                ['$set' => [
                    'name'               => $food['name'],
                    'nutrients_per_100g' => $food['nutrients_per_100g'],
                    'source'             => 'usda',
                    'updated_at'         => new \MongoDB\BSON\UTCDateTime(),
                ]],
                ['upsert' => true]
            );
        }
    }

    /**
     * Extract key nutrients per 100g from USDA foodNutrients array.
     */
    private function extractNutrients(array $foodNutrients): array
    {
        $mapping = [
            'calories'   => ['Energy', 'energy', 'calories'],
            'protein_g'  => ['Protein', 'protein'],
            'carbs_g'    => ['Carbohydrate, by difference', 'Carbohydrates', 'carbs'],
            'fat_g'      => ['Total lipid (fat)', 'Fat', 'fat'],
        ];

        $result = array_fill_keys(array_keys($mapping), 0.0);

        foreach ($foodNutrients as $n) {
            $name  = $n['nutrientName'] ?? ($n['nutrient']['name'] ?? '');
            $value = (float) ($n['value'] ?? ($n['amount'] ?? 0));

            foreach ($mapping as $key => $aliases) {
                foreach ($aliases as $alias) {
                    if (stripos($name, $alias) !== false) {
                        // Ensure value is per 100g. USDA often returns per 100g already,
                        // but check unitName to be safe.
                        $unit = $n['unitName'] ?? ($n['nutrient']['unitName'] ?? '');
                        if (stripos($unit, 'kJ') !== false) {
                            $value = $value / 4.184; // kJ -> kcal
                        }
                        $result[$key] = round($value, 2);
                        break 2;
                    }
                }
            }
        }

        return $result;
    }
}
