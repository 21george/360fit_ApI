<?php

namespace App\Services;

use App\Config\Database;
use MongoDB\BSON\ObjectId;

class ExcelImportService
{
    private array $columnMap = [];
    private array $warnings  = [];
    private array $created   = [];

    /** Valid day names for normalisation */
    private const VALID_DAYS = [
        'monday' => 'monday', 'mon' => 'monday',
        'tuesday' => 'tuesday', 'tue' => 'tuesday', 'tues' => 'tuesday',
        'wednesday' => 'wednesday', 'wed' => 'wednesday',
        'thursday' => 'thursday', 'thu' => 'thursday', 'thurs' => 'thursday',
        'friday' => 'friday', 'fri' => 'friday',
        'saturday' => 'saturday', 'sat' => 'saturday',
        'sunday' => 'sunday', 'sun' => 'sunday',
    ];

    /** Column aliases for flexible header detection */
    private const COLUMN_ALIASES = [
        'client_name'   => ['client_name', 'client', 'name', 'athlete', 'client name'],
        'week_start'    => ['week_start', 'week', 'date', 'start_date', 'week start', 'week_start_date'],
        'day'           => ['day', 'weekday', 'day_of_week', 'day of week'],
        'exercise'      => ['exercise', 'exercise_name', 'movement', 'lift', 'exercise name'],
        'sets'          => ['sets', 'set', 'num_sets', 'num sets'],
        'reps'          => ['reps', 'repetitions', 'rep_range', 'rep range'],
        'rest_seconds'  => ['rest', 'rest_seconds', 'rest_time', 'rest (sec)', 'recovery', 'rest (seconds)', 'rest sec'],
        'notes'         => ['notes', 'instructions', 'note', 'coaching_notes', 'coaching notes', 'comments'],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Public entry point
    // ─────────────────────────────────────────────────────────────────────────

    public function import(string $filePath, string $coachId): array
    {
        $this->warnings = [];
        $this->created  = [];

        // 1. Parse file into rows
        $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'csv'  => $this->parseCsv($filePath),
            default => $this->parseXlsx($filePath),
        };

        if (empty($rows)) {
            throw new \RuntimeException('File is empty or could not be parsed');
        }

        // 2. Detect column mapping from header row
        $headers          = array_shift($rows);
        $this->columnMap  = $this->detectColumns($headers);

        error_log('Excel Import - Column map: ' . json_encode($this->columnMap));
        error_log('Excel Import - Headers found: ' . json_encode($headers));

        // week_start is no longer strictly required — we default it
        $requiredFields  = ['client_name', 'day', 'exercise'];
        $missingFields   = array_diff($requiredFields, array_keys($this->columnMap));
        if (!empty($missingFields)) {
            throw new \RuntimeException('Missing required columns: ' . implode(', ', $missingFields));
        }

        // 3. Process rows into plans
        $plansBatch    = [];
        $clientCache   = [];
        $totalRows     = count($rows);
        $processedRows = 0;
        $skippedRows   = 0;

        foreach ($rows as $lineNum => $row) {
            // Skip completely empty rows
            if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $data = $this->mapRow($row);
            if (!$data) {
                $skippedRows++;
                continue;
            }

            // ── Validate client_name ──────────────────────────────────────
            $clientName = trim((string) ($data['client_name'] ?? ''));
            if (empty($clientName)) {
                $this->warnings[] = "Row " . ($lineNum + 2) . ": Missing client name, skipped";
                $skippedRows++;
                continue;
            }

            // Cache client lookups — normalise for flexible matching
            $normalizedName = mb_strtolower(preg_replace('/\s+/', ' ', $clientName));
            if (!isset($clientCache[$normalizedName])) {
                $client = Database::getInstance()->getCollection('clients')->findOne([
                    'coach_id' => new ObjectId($coachId),
                    'name'     => ['$regex' => '^' . preg_quote(trim($clientName), '/') . '$', '$options' => 'i'],
                    'active'   => true,
                ]);
                $clientCache[$normalizedName] = $client;
                if (!$client) {
                    $this->warnings[] = "Client '$clientName' not found — create this client first";
                }
            }

            $client = $clientCache[$normalizedName];
            if (!$client) {
                $skippedRows++;
                continue;
            }

            // ── Validate exercise name ────────────────────────────────────
            $exerciseName = trim((string) ($data['exercise'] ?? ''));
            if (empty($exerciseName)) {
                $this->warnings[] = "Row " . ($lineNum + 2) . ": Missing exercise name, skipped";
                $skippedRows++;
                continue;
            }

            // ── Validate & normalise day ───────────────────────────────────
            $rawDay = strtolower(trim((string) ($data['day'] ?? '')));
            $day    = self::VALID_DAYS[$rawDay] ?? null;
            if (!$day) {
                // Try numeric day (1=Monday … 7=Sunday)
                $numericDay = (int) $rawDay;
                if ($numericDay >= 1 && $numericDay <= 7) {
                    $dayMap = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
                    $day = $dayMap[$numericDay];
                }
            }
            if (!$day) {
                $this->warnings[] = "Row " . ($lineNum + 2) . ": Unrecognised day '{$rawDay}', skipped";
                $skippedRows++;
                continue;
            }

            // ── Validate & parse week_start ────────────────────────────────
            $rawWeekStart = trim((string) ($data['week_start'] ?? ''));
            if (empty($rawWeekStart)) {
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                $this->warnings[] = "Row " . ($lineNum + 2) . ": Missing week_start, defaulting to {$weekStart}";
            } else {
                $weekStart = $this->parseDate($rawWeekStart);
                if ($weekStart === null) {
                    $this->warnings[] = "Row " . ($lineNum + 2) . ": Invalid date '{$rawWeekStart}', skipped";
                    $skippedRows++;
                    continue;
                }
            }

            $processedRows++;
            $clientId = (string) $client['_id'];

            // ── Build or retrieve plan ─────────────────────────────────────
            $planKey = "$clientId|$weekStart";
            if (!isset($plansBatch[$planKey])) {
                $plansBatch[$planKey] = [
                    'coach_id'            => new ObjectId($coachId),
                    'client_id'           => new ObjectId($clientId),
                    'title'               => "Imported Week of $weekStart",
                    'week_start'          => new \MongoDB\BSON\UTCDateTime(strtotime($weekStart) * 1000),
                    'status'              => 'active',
                    'days'                => [],
                    'imported_from_excel' => true,
                    'created_at'          => new \MongoDB\BSON\UTCDateTime(),
                    'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
                ];
            }

            // ── Build exercise entry ───────────────────────────────────────
            $exerciseOrder = $this->nextExerciseOrder($plansBatch[$planKey]['days'], $day);

            $exercise = [
                'exercise_id'    => new ObjectId(),
                'name'           => $exerciseName,
                'sets'           => $this->parseIntOrDefault($data['sets'] ?? null, 3),
                'reps'           => (string) ($data['reps'] ?? '10'),
                'rest_seconds'   => $this->parseIntOrDefault($data['rest_seconds'] ?? null, 60),
                'notes'          => isset($data['notes']) && $data['notes'] !== '' ? $data['notes'] : null,
                'demo_video_url' => null,
                'order'          => $exerciseOrder,
            ];

            // Append exercise to existing day, or create new day
            $dayFound = false;
            foreach ($plansBatch[$planKey]['days'] as &$d) {
                if ($d['day'] === $day) {
                    $d['exercises'][] = $exercise;
                    $dayFound = true;
                    break;
                }
            }
            unset($d);

            if (!$dayFound) {
                $plansBatch[$planKey]['days'][] = [
                    'day'       => $day,
                    'exercises' => [$exercise],
                ];
            }
        }

        // 4. Insert all plans
        foreach ($plansBatch as $plan) {
            $result          = Database::getInstance()->getCollection('workout_plans')->insertOne($plan);
            $this->created[] = (string) $result->getInsertedId();
        }

        error_log('Excel Import - Total rows: ' . $totalRows . ', Processed: ' . $processedRows . ', Skipped: ' . $skippedRows);
        error_log('Excel Import - Plans created: ' . count($this->created) . ', IDs: ' . json_encode($this->created));
        error_log('Excel Import - Warnings: ' . json_encode($this->warnings));

        return [
            'plans_created'   => count($this->created),
            'plan_ids'        => $this->created,
            'warnings'        => $this->warnings,
            'total_rows'      => $totalRows,
            'processed_rows'  => $processedRows,
            'skipped_rows'    => $skippedRows,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calculate the next exercise order for a given day within a plan.
     */
    private function nextExerciseOrder(array $days, string $day): int
    {
        foreach ($days as $d) {
            if ($d['day'] === $day) {
                return count($d['exercises']);
            }
        }
        return 0;
    }

    /**
     * Parse a date value that may be a string, Excel serial number, or timestamp.
     * Returns 'Y-m-d' string or null on failure.
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        // Excel serial date (number of days since 1900-01-01)
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 25569 && $serial < 100000) {
                // Convert Excel serial to Unix timestamp
                $timestamp = (int) (($serial - 25569) * 86400);
                if ($timestamp >= strtotime('2020-01-01') && $timestamp <= strtotime('+2 years')) {
                    return date('Y-m-d', $timestamp);
                }
            }
            // Might be a raw Unix timestamp
            $ts = (int) $value;
            if ($ts >= strtotime('2020-01-01') && $ts <= strtotime('+2 years')) {
                return date('Y-m-d', $ts);
            }
        }

        // Standard date string
        $parsed = strtotime($value);
        if ($parsed === false) {
            return null;
        }
        if ($parsed < strtotime('2020-01-01') || $parsed > strtotime('+2 years')) {
            return null;
        }
        return date('Y-m-d', $parsed);
    }

    /**
     * Parse an integer from a value, returning a default on failure.
     */
    private function parseIntOrDefault($value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);
        return $int !== false ? $int : $default;
    }

    /**
     * Detect column mapping from header row.
     * Supports flexible aliases and normalised header names.
     */
    private function detectColumns(array $headers): array
    {
        $map = [];

        foreach ($headers as $idx => $header) {
            $normalized = strtolower(trim((string) $header));
            // Normalise spaces and hyphens to underscores for matching
            $cleaned = preg_replace('/[\s\-]+/', '_', $normalized);

            foreach (self::COLUMN_ALIASES as $field => $options) {
                if (in_array($normalized, $options, true) || in_array($cleaned, $options, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Map a data row to an associative array using the detected column map.
     * Handles PhpSpreadsheet DateTime objects and numeric cells.
     */
    private function mapRow(array $row): ?array
    {
        if (empty($this->columnMap)) {
            return null;
        }
        $data = [];
        foreach ($this->columnMap as $field => $idx) {
            $raw = $row[$idx] ?? null;
            // Convert DateTime objects to Y-m-d strings
            if ($raw instanceof \DateTimeInterface) {
                $data[$field] = $raw->format('Y-m-d');
            } elseif (is_float($raw) || is_int($raw)) {
                // Keep numeric values as strings for later parsing
                $data[$field] = (string) $raw;
            } elseif ($raw !== null) {
                $data[$field] = trim((string) $raw);
            } else {
                $data[$field] = null;
            }
        }
        return $data;
    }

    /**
     * Parse a CSV file into an array of rows.
     * Handles UTF-8 BOM.
     */
    private function parseCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open CSV file');
        }

        // Detect and skip UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Parse an XLSX file using PhpSpreadsheet.
     * Uses getCalculatedValue() to resolve formulas and handles DateTime objects.
     */
    private function parseXlsx(string $path): array
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new \RuntimeException('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $cells = [];
            foreach ($cellIterator as $cell) {
                $value = $cell->getCalculatedValue();
                // Convert DateTime objects to Y-m-d strings
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }
                $cells[] = $value;
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
