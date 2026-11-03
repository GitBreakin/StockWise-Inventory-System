<?php
/**
 * validator.php — server-side validation for incoming request data.
 *
 * Usage:
 *   $v = new Validator($input);
 *   $name = $v->string('name', 2, 150);
 *   $qty  = $v->int('quantity', 0, 1_000_000);
 *   $v->abortIfInvalid();   // 422 JSON with { field: message } errors
 *
 * Client-side checks are only for convenience; THIS is the real gate.
 */

declare(strict_types=1);

final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data) && $this->data[$field] !== null && $this->data[$field] !== '';
    }

    public function error(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function abortIfInvalid(string $message = 'Please correct the highlighted fields.'): void
    {
        if ($this->fails()) {
            json_error($message, 422, $this->errors);
        }
    }

    /** Trimmed string; null when optional and empty. */
    public function string(string $field, int $min = 1, int $max = 255, bool $required = true, string $label = ''): ?string
    {
        $label = $label ?: $this->label($field);
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, "$label is required.");
            }
            return null;
        }
        $value = $this->data[$field];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $this->error($field, "$label must be text.");
            return null;
        }
        $value = trim((string) $value);
        $len = mb_strlen($value);
        if ($value === '' && !$required) {
            return null;
        }
        if ($len < $min) {
            $this->error($field, "$label must be at least $min characters.");
        } elseif ($len > $max) {
            $this->error($field, "$label must be at most $max characters.");
        }
        return $value;
    }

    /** Whole number within [min, max]. Accepts 12 or "12", rejects 1.5 / "abc". */
    public function int(string $field, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, bool $required = true, string $label = ''): ?int
    {
        $label = $label ?: $this->label($field);
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, "$label is required.");
            }
            return null;
        }
        $raw = $this->data[$field];
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^-?\d{1,12}$/', trim($raw))) {
            $value = (int) trim($raw);
        } else {
            $this->error($field, "$label must be a whole number.");
            return null;
        }
        if ($value < $min || $value > $max) {
            $this->error($field, $max === PHP_INT_MAX ? "$label must be at least $min." : "$label must be between $min and $max.");
        }
        return $value;
    }

    /** Positive database ID. */
    public function id(string $field, bool $required = true, string $label = ''): ?int
    {
        return $this->int($field, 1, PHP_INT_MAX, $required, $label ?: $this->label($field));
    }

    /** Money as a 2-decimal string (exact for NUMERIC columns). */
    public function money(string $field, float $min = 0, float $max = 9_999_999_999.99, bool $required = true, string $label = ''): ?string
    {
        $label = $label ?: $this->label($field);
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, "$label is required.");
            }
            return null;
        }
        $raw = $this->data[$field];
        $str = is_string($raw) ? trim($raw) : ((is_int($raw) || is_float($raw)) ? (string) $raw : null);
        if ($str === null || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $str)) {
            $this->error($field, "$label must be a valid amount with up to 2 decimal places.");
            return null;
        }
        $value = (float) $str;
        if ($value < $min || $value > $max) {
            $this->error($field, "$label must be between " . number_format($min, 2) . ' and ' . number_format($max, 2) . '.');
        }
        return number_format($value, 2, '.', '');
    }

    public function enum(string $field, array $allowed, bool $required = true, ?string $default = null, string $label = ''): ?string
    {
        $label = $label ?: $this->label($field);
        if (!$this->has($field)) {
            if ($required && $default === null) {
                $this->error($field, "$label is required.");
            }
            return $default;
        }
        $value = $this->data[$field];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->error($field, "$label must be one of: " . implode(', ', $allowed) . '.');
            return null;
        }
        return $value;
    }

    /** Date in YYYY-MM-DD format that really exists on the calendar. */
    public function date(string $field, bool $required = true, string $label = ''): ?string
    {
        $label = $label ?: $this->label($field);
        if (!$this->has($field)) {
            if ($required) {
                $this->error($field, "$label is required.");
            }
            return null;
        }
        $value = $this->data[$field];
        $dt = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            $this->error($field, "$label must be a valid date (YYYY-MM-DD).");
            return null;
        }
        return $value;
    }

    public function bool(string $field, bool $default = false): bool
    {
        if (!array_key_exists($field, $this->data)) {
            return $default;
        }
        return filter_var($this->data[$field], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}

/**
 * Validate an optional from/to date range (used by reports and lists).
 * Returns [from, to] as Y-m-d strings or nulls.
 */
function validate_date_range(Validator $v, string $fromField = 'from', string $toField = 'to', int $maxDays = 1100): array
{
    $from = $v->date($fromField, false, 'Start date');
    $to = $v->date($toField, false, 'End date');
    if ($from !== null && $to !== null) {
        if ($from > $to) {
            $v->error($toField, 'End date must be on or after the start date.');
        } elseif ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > $maxDays) {
            $v->error($toField, "Date range cannot be longer than $maxDays days.");
        }
    }
    return [$from, $to];
}
