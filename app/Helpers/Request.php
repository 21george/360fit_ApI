<?php
declare(strict_types=1);

namespace App\Helpers;

class Request
{
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return [];
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException) {
            return [];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $ruleList = explode('|', $rule);
            $value    = $data[$field] ?? null;

            foreach ($ruleList as $r) {
                if ($r === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "$field is required";
                } elseif ($r === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "$field must be a valid email";
                } elseif (str_starts_with($r, 'min:') && $value !== null) {
                    $min = (int) substr($r, 4);
                    if (strlen((string)$value) < $min) $errors[$field][] = "$field must be at least $min characters";
                } elseif (str_starts_with($r, 'max:') && $value !== null) {
                    $max = (int) substr($r, 4);
                    if (strlen((string)$value) > $max) $errors[$field][] = "$field must not exceed $max characters";
                } elseif ($r === 'numeric' && $value !== null && !is_numeric($value)) {
                    $errors[$field][] = "$field must be numeric";
                }
            }
        }
        return $errors;
    }
}
