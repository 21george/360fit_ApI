<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response, ScopedCollection};
use App\Services\FoodService;
use MongoDB\BSON\ObjectId;

class FoodController
{
    private FoodService $foodService;

    public function __construct()
    {
        $this->foodService = new FoodService();
    }

    /**
     * GET /foods/search?q=chicken&page=1
     *
     * Search foods by name across local cache + USDA API.
     */
    public function search(array $params): void
    {
        $query = trim((string) Request::get('q', ''));
        $page  = max(1, (int) Request::get('page', 1));

        if ($query === '') {
            Response::error('Search query is required', 422, ['q' => ['Query parameter "q" is required']]);
        }

        $coachId = new ObjectId($params['_auth']['sub']);

        // 1. Search local foods (custom + cached USDA)
        $localFoods = $this->foodService->searchLocalFoods($query, $coachId);

        // 2. Search USDA API
        $usdaFoods = [];
        $totalHits = count($localFoods);
        try {
            $usdaResult = $this->foodService->searchFoods($query, $page);
            $usdaFoods  = $usdaResult['foods'] ?? [];
            $totalHits  = $usdaResult['totalHits'] ?? count($usdaFoods);
        } catch (\Throwable $e) {
            // USDA API failure is non-fatal; fall back to local results only
            error_log('USDA API search failed: ' . $e->getMessage());
        }

        // 3. Merge and deduplicate by fdc_id
        $seenFdcIds = [];
        $foods = [];

        foreach ($localFoods as $food) {
            if (!empty($food['fdc_id'])) {
                $seenFdcIds[$food['fdc_id']] = true;
            }
            $foods[] = $food;
        }

        foreach ($usdaFoods as $food) {
            if (!empty($food['fdc_id']) && isset($seenFdcIds[$food['fdc_id']])) {
                continue;
            }
            $foods[] = $food;
        }

        Response::success([
            'foods'      => $foods,
            'total_hits' => $totalHits,
            'page'       => $page,
        ]);
    }

    /**
     * GET /foods
     *
     * List custom foods created by the authenticated coach.
     */
    public function index(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);

        $foods = ScopedCollection::for('foods')->find(
            ['coach_id' => $coachId, 'source' => 'custom'],
            ['sort' => ['created_at' => -1]]
        )->toArray();

        Response::success(array_map(fn($f) => $this->format($f), $foods));
    }

    /**
     * GET /foods/:id
     *
     * Get a single food by its MongoDB _id.
     */
    public function show(array $params): void
    {
        $foodId  = new ObjectId($params['id']);
        $coachId = new ObjectId($params['_auth']['sub']);
        $food    = ScopedCollection::for('foods')->findOne(['_id' => $foodId]);

        if (!$food) {
            Response::error('Food not found', 404);
        }

        // Custom foods are private to the coach who created them
        $data = (array) $food;
        if (($data['source'] ?? '') === 'custom' && isset($data['coach_id'])) {
            if ((string) $data['coach_id'] !== (string) $coachId) {
                Response::error('Food not found', 404);
            }
        }

        Response::success($this->format($food));
    }

    /**
     * POST /foods
     *
     * Create a custom food entry.
     */
    public function store(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, [
            'name' => 'required',
            'nutrients_per_100g' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $nutrients = $body['nutrients_per_100g'];
        if (!is_array($nutrients)) {
            Response::error('nutrients_per_100g must be an object', 422);
        }

        // Validate required nutrient keys
        $requiredKeys = ['calories', 'protein_g', 'carbs_g', 'fat_g'];
        $missingKeys  = array_diff($requiredKeys, array_keys($nutrients));
        if (!empty($missingKeys)) {
            Response::error(
                'Missing required nutrient fields: ' . implode(', ', $missingKeys),
                422
            );
        }

        $result = $this->foodService->createCustomFood($coachId, trim($body['name']), $nutrients);

        Response::success($result, 'Custom food created', 201);
    }

    /**
     * GET /foods/fdc/:fdcId
     *
     * Get full USDA food details by FDC ID via USDA API (cached locally).
     */
    public function details(array $params): void
    {
        $fdcId = (int) ($params['fdcId'] ?? 0);
        if ($fdcId <= 0) {
            Response::error('Invalid FDC ID', 422);
        }

        try {
            $food = $this->foodService->getFoodDetails($fdcId);
            Response::success($food);
        } catch (\Throwable $e) {
            Response::error('Food not found', 404);
        }
    }

    /**
     * GET /foods/calculate?quantity=150g&food_id=...  (or &fdc_id=...)
     *
     * Calculate nutrients for a food given a quantity string.
     */
    public function calculate(array $params): void
    {
        $quantity = trim((string) Request::get('quantity', ''));
        $foodId   = trim((string) Request::get('food_id', ''));
        $fdcId    = (int) Request::get('fdc_id', 0);

        if ($quantity === '') {
            Response::error('quantity is required', 422);
        }
        if ($foodId === '' && $fdcId === 0) {
            Response::error('Either food_id or fdc_id is required', 422);
        }

        $food = null;
        if ($foodId !== '') {
            $food = ScopedCollection::for('foods')->findOne(['_id' => new ObjectId($foodId)]);
            // Prevent cross-coach access to custom foods
            if ($food) {
                $data = (array) $food;
                if (($data['source'] ?? '') === 'custom' && isset($data['coach_id'])) {
                    $coachId = new ObjectId($params['_auth']['sub']);
                    if ((string) $data['coach_id'] !== (string) $coachId) {
                        Response::error('Food not found', 404);
                    }
                }
            }
        } elseif ($fdcId > 0) {
            $food = ScopedCollection::for('foods')->findOne(['fdc_id' => $fdcId]);

            // Not cached locally — fetch from USDA API using the USDA_API_KEY
            if (!$food) {
                try {
                    $details   = $this->foodService->getFoodDetails($fdcId);
                    $nutrients = $details['nutrients_per_100g'] ?? [];

                    $quantityG = $this->parseQuantityToGrams($quantity);
                    if ($quantityG <= 0) {
                        Response::error('Invalid quantity. Use formats like: 150g, 1kg, 8oz, 2 cups', 422);
                    }

                    $factor = $quantityG / 100;
                    Response::success([
                        'calories'   => round((float)($nutrients['calories']  ?? 0) * $factor, 1),
                        'protein_g'  => round((float)($nutrients['protein_g'] ?? 0) * $factor, 1),
                        'carbs_g'    => round((float)($nutrients['carbs_g']   ?? 0) * $factor, 1),
                        'fat_g'      => round((float)($nutrients['fat_g']     ?? 0) * $factor, 1),
                        'quantity_g' => $quantityG,
                    ]);
                    return;
                } catch (\Throwable $e) {
                    error_log('USDA API food details failed: ' . $e->getMessage());
                    Response::error('Food not found', 404);
                }
            }
        }

        if (!$food) {
            Response::error('Food not found', 404);
        }

        $quantityG = $this->parseQuantityToGrams($quantity);
        if ($quantityG <= 0) {
            Response::error('Invalid quantity. Use formats like: 150g, 1kg, 8oz, 2 cups', 422);
        }

        $nutrients = (array) ($food->nutrients_per_100g ?? []);
        $factor    = $quantityG / 100;

        Response::success([
            'calories'   => round((float)($nutrients['calories']  ?? 0) * $factor, 1),
            'protein_g'  => round((float)($nutrients['protein_g'] ?? 0) * $factor, 1),
            'carbs_g'    => round((float)($nutrients['carbs_g']   ?? 0) * $factor, 1),
            'fat_g'      => round((float)($nutrients['fat_g']     ?? 0) * $factor, 1),
            'quantity_g' => $quantityG,
        ]);
    }

    private function parseQuantityToGrams(string $input): float
    {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(g|kg|ml|l|oz|lb|cup|tbsp|tsp)?\s*$/i', $input, $m)) {
            return 0;
        }
        $val  = (float) $m[1];
        $unit = strtolower($m[2] ?? 'g');
        return match ($unit) {
            'kg'   => $val * 1000,
            'l'    => $val * 1000,
            'ml'   => $val,
            'oz'   => $val * 28.35,
            'lb'   => $val * 453.6,
            'cup'  => $val * 240,
            'tbsp' => $val * 15,
            'tsp'  => $val * 5,
            default => $val,
        };
    }

    private function format(object $doc): array
    {
        $data = (array) $doc;
        return [
            'id'                 => isset($data['_id']) ? (string) $data['_id'] : '',
            'name'               => $data['name'] ?? '',
            'fdc_id'             => $data['fdc_id'] ?? null,
            'nutrients_per_100g' => $data['nutrients_per_100g'] ?? [],
            'source'             => $data['source'] ?? 'custom',
            'created_at'         => isset($data['created_at']) ? (string) $data['created_at'] : '',
            'updated_at'         => isset($data['updated_at']) ? (string) $data['updated_at'] : '',
        ];
    }
}
