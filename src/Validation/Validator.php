<?php

declare(strict_types=1);

namespace PHPAML\Validation;

use DateTimeImmutable;

final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * Le vérificateur reçoit ($rule, $table, $column, $value) pour unique/exists.
     * @param null|callable(string,string,string,mixed):bool $databaseVerifier
     */
    public function __construct(private $databaseVerifier = null)
    {
    }

    /** @param array<string, mixed> $data @param array<string, list<string>|string> $rules */
    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $data[$field] ?? null;
            $nullable = in_array('nullable', $fieldRules, true);
            if ($nullable && !in_array('required', $fieldRules, true) && ($value === null || $value === '')) {
                continue;
            }
            foreach ($fieldRules as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }
                if (!$this->passes($rule, $field, $value, $data)) {
                    $this->errors[$field][] = "La règle '{$rule}' n'est pas respectée.";
                }
            }
        }
        return $this->errors === [];
    }

    /** @param array<string, mixed> $data */
    private function passes(string $rule, string $field, mixed $value, array $data): bool
    {
        [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, '');
        $arguments = $parameters === '' ? [] : explode(',', $parameters);
        if ($value === null && $name !== 'required') {
            return true;
        }
        return match ($name) {
            'required' => array_key_exists($field, $data) && $value !== null && $value !== '',
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1,
            'string' => is_string($value),
            'array' => is_array($value),
            'integer' => is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false),
            'numeric' => is_numeric($value),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
            'date' => $this->date($value, null),
            'date_format' => $this->date($value, $parameters),
            'min' => $this->size($value) >= (float) ($arguments[0] ?? 0),
            'max' => $this->size($value) <= (float) ($arguments[0] ?? 0),
            'between' => count($arguments) === 2 && $this->size($value) >= (float) $arguments[0] && $this->size($value) <= (float) $arguments[1],
            'in' => in_array((string) $value, $arguments, true),
            'not_in' => !in_array((string) $value, $arguments, true),
            'same' => isset($arguments[0]) && array_key_exists($arguments[0], $data) && $value === $data[$arguments[0]],
            'confirmed' => array_key_exists($field . '_confirmation', $data) && $value === $data[$field . '_confirmation'],
            'regex' => $parameters !== '' && @preg_match($parameters, (string) $value) === 1,
            'unique', 'exists' => $this->database($name, $arguments, $field, $value),
            default => false,
        };
    }

    private function size(mixed $value): float
    {
        if (is_array($value)) return count($value);
        if (is_numeric($value)) return (float) $value;
        return (float) mb_strlen((string) $value);
    }

    private function date(mixed $value, ?string $format): bool
    {
        if (!is_string($value) || $value === '') return false;
        if ($format === null) return strtotime($value) !== false;
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $date !== false && $date->format($format) === $value;
    }

    /** @param list<string> $arguments */
    private function database(string $rule, array $arguments, string $field, mixed $value): bool
    {
        if (!is_callable($this->databaseVerifier) || !isset($arguments[0])) return false;
        return (bool) ($this->databaseVerifier)($rule, $arguments[0], $arguments[1] ?? $field, $value);
    }

    /** @return array<string, list<string>> */
    public function errors(): array { return $this->errors; }
}
