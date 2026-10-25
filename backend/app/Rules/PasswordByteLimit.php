<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordByteLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Bcrypt's limit is bytes, not Unicode characters. Never silently truncate a password.
        if (is_string($value) && strlen($value) > 72) {
            $fail('The password must be no more than 72 UTF-8 bytes. Use fewer characters if using accented letters or symbols.');
        }
    }
}
