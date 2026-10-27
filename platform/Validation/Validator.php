<?php

declare(strict_types=1);

namespace Platform\Validation;

/**
 * A small rule-based validator.
 *
 * Deliberately not a framework: enough to keep every public endpoint's input
 * checked on the server, which is the point. The legacy build validated in the
 * browser only, so anything that bypassed the UI bypassed the rules.
 *
 * Rules are pipe-separated strings:
 *   'required|string|max:191'
 *   'nullable|integer|between:0,6'
 *   'required|in:2024,2025,2026'
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $valid = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules field => rule string
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
        $this->run();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules    = explode('|', $ruleString);
            $value    = $this->data[$field] ?? null;
            $nullable = in_array('nullable', $rules, true);

            // Treat '' as absent: HTML selects post empty strings for "no answer",
            // and a blank optional field must not fail a type rule.
            if ($value === '') {
                $value = null;
            }

            if ($value === null) {
                if (in_array('required', $rules, true)) {
                    $this->addError($field, $this->label($field) . ' is required.');
                } elseif ($nullable) {
                    $this->valid[$field] = null;
                }
                continue;
            }

            $failed = false;
            foreach ($rules as $rule) {
                if (in_array($rule, ['required', 'nullable'], true)) {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                $result = $this->apply($field, $name, $parameter, $value);

                if ($result === null) {
                    $failed = true;
                    break;
                }

                $value = $result;
            }

            if (!$failed) {
                $this->valid[$field] = $value;
            }
        }
    }

    /** Returns the coerced value, or null when the rule failed. */
    private function apply(string $field, string $rule, ?string $parameter, mixed $value): mixed
    {
        $label = $this->label($field);

        switch ($rule) {
            case 'string':
                if (!is_string($value)) {
                    return $this->reject($field, "{$label} must be text.");
                }
                return trim($value);

            case 'integer':
                if (!is_numeric($value) || (string) (int) $value !== (string) $value && !is_int($value)) {
                    if (!is_numeric($value) || floor((float) $value) != (float) $value) {
                        return $this->reject($field, "{$label} must be a whole number.");
                    }
                }
                return (int) $value;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->reject($field, "{$label} must be a number.");
                }
                return (float) $value;

            case 'boolean':
                return in_array($value, [true, 1, '1', 'true', 'on'], true);

            case 'array':
                if (!is_array($value)) {
                    return $this->reject($field, "{$label} must be a list.");
                }
                return $value;

            /*
             * Length for a string, quantity for a number -- and the two branches are
             * exclusive, because a great many strings are also is_numeric(). A phone
             * number is the case that proved it: "+919876274711" is thirteen
             * characters AND a number PHP is happy to read, so it passed max:40 as a
             * string and was then rejected by the same rule as "greater than 40".
             * The type rule ahead of this one has already cast integer/numeric
             * fields, so the type here is the declared one.
             */
            case 'max':
                if (is_string($value)) {
                    return mb_strlen($value) > (int) $parameter
                        ? $this->reject($field, "{$label} may not be longer than {$parameter} characters.")
                        : $value;
                }
                if (is_numeric($value) && $value > (float) $parameter) {
                    return $this->reject($field, "{$label} may not be greater than {$parameter}.");
                }
                return $value;

            /* Exclusive for the same reason as max, above. */
            case 'min':
                if (is_string($value)) {
                    return mb_strlen($value) < (int) $parameter
                        ? $this->reject($field, "{$label} must be at least {$parameter} characters.")
                        : $value;
                }
                if (is_numeric($value) && $value < (float) $parameter) {
                    return $this->reject($field, "{$label} must be at least {$parameter}.");
                }
                return $value;

            case 'between':
                [$low, $high] = array_pad(explode(',', (string) $parameter), 2, null);
                if (!is_numeric($value) || $value < (float) $low || $value > (float) $high) {
                    return $this->reject($field, "{$label} must be between {$low} and {$high}.");
                }
                return $value;

            case 'in':
                $allowed = explode(',', (string) $parameter);
                if (!in_array((string) $value, $allowed, true)) {
                    return $this->reject($field, "{$label} is not one of the allowed values.");
                }
                return $value;

            default:
                return $value;
        }
    }

    private function reject(string $field, string $message): mixed
    {
        $this->addError($field, $message);

        return null;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** 'board_seats' -> 'Board seats' */
    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The first error message, for a single-line response. */
    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return $messages[0];
        }

        return null;
    }

    /**
     * Only the fields that were declared AND passed.
     *
     * Anything the caller sent but did not declare a rule for is dropped, so a
     * client cannot smuggle an extra column into an insert.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->valid;
    }
}
