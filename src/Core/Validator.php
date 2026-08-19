<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Eingabe-Validierung an Systemgrenzen.
 *
 * $v = new Validator($_POST);
 * $v->required('email', 'E-Mail')->email('email', 'E-Mail');
 * if ($v->fails()) { $errors = $v->errors(); }
 */
final class Validator
{
    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function value(string $field): string
    {
        $raw = $this->data[$field] ?? '';
        return is_string($raw) ? trim($raw) : '';
    }

    public function required(string $field, string $label): self
    {
        if ($this->value($field) === '') {
            $this->addError($field, $label . ' ist erforderlich.');
        }
        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, $label . ' ist keine gültige E-Mail-Adresse.');
        }
        return $this;
    }

    public function minLength(string $field, string $label, int $min): self
    {
        $value = $this->value($field);
        if ($value !== '' && mb_strlen($value) < $min) {
            $this->addError($field, $label . " muss mindestens {$min} Zeichen lang sein.");
        }
        return $this;
    }

    public function maxLength(string $field, string $label, int $max): self
    {
        if (mb_strlen($this->value($field)) > $max) {
            $this->addError($field, $label . " darf höchstens {$max} Zeichen lang sein.");
        }
        return $this;
    }

    public function numeric(string $field, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && !is_numeric(str_replace(["'", ' '], '', $value))) {
            $this->addError($field, $label . ' muss eine Zahl sein.');
        }
        return $this;
    }

    public function integer(string $field, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->addError($field, $label . ' muss eine ganze Zahl sein.');
        }
        return $this;
    }

    public function range(string $field, string $label, float $min, float $max): self
    {
        $value = $this->value($field);
        if ($value !== '' && is_numeric($value)) {
            $number = (float) $value;
            if ($number < $min || $number > $max) {
                $this->addError($field, $label . " muss zwischen {$min} und {$max} liegen.");
            }
        }
        return $this;
    }

    /** @param array<int, string> $allowed */
    public function in(string $field, string $label, array $allowed): self
    {
        $value = $this->value($field);
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->addError($field, $label . ' enthält einen ungültigen Wert.');
        }
        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        if ($this->value($field) !== $this->value($otherField)) {
            $this->addError($field, $label . ' stimmt nicht überein.');
        }
        return $this;
    }

    public function checked(string $field, string $message): self
    {
        if (empty($this->data[$field])) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function url(string $field, string $label): self
    {
        $value = $this->value($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->addError($field, $label . ' ist keine gültige URL.');
        }
        return $this;
    }

    public function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }
        return null;
    }
}
