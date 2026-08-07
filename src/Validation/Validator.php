<?php

declare(strict_types=1);

namespace PHPAML\Validation;

final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<string, mixed> $data @param array<string, list<string>|string> $rules */
    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];
        foreach ($rules as $field => $fieldRules) {
            foreach ((array) $fieldRules as $rule) {
                $value = $data[$field] ?? null;
                $valid = match (true) {
                    $rule === 'required' => $value !== null && $value !== '',
                    $rule === 'email' => $value === null || filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    $rule === 'string' => $value === null || is_string($value),
                    str_starts_with($rule, 'min:') => $value === null || mb_strlen((string) $value) >= (int) substr($rule, 4),
                    str_starts_with($rule, 'max:') => $value === null || mb_strlen((string) $value) <= (int) substr($rule, 4),
                    default => false,
                };
                if (!$valid) {
                    $this->errors[$field][] = "La règle '{$rule}' n'est pas respectée.";
                }
            }
        }
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
