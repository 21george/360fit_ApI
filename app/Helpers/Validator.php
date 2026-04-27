<?php
namespace App\Helpers;

class Validator {
    private array $errors = [];
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function required(string $field, string $label = ''): self {
        $label = $label ?: $field;
        if (empty($this->data[$field]) && $this->data[$field] !== '0' && $this->data[$field] !== 0) {
            $this->errors[$field] = "$label is required";
        }
        return $this;
    }

    public function email(string $field): self {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "Invalid email address";
        }
        return $this;
    }

    public function min(string $field, int $min): self {
        if (!empty($this->data[$field]) && strlen((string)$this->data[$field]) < $min) {
            $this->errors[$field] = "Must be at least $min characters";
        }
        return $this;
    }

    public function in(string $field, array $values): self {
        if (!empty($this->data[$field]) && !in_array($this->data[$field], $values)) {
            $this->errors[$field] = "Invalid value for $field";
        }
        return $this;
    }

    public function fails(): bool {
        return !empty($this->errors);
    }

    public function getErrors(): array {
        return $this->errors;
    }
}
