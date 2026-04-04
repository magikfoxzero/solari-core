<?php

namespace NewSolari\Core\Rules;

use NewSolari\Core\Services\UsernameValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Custom validation rule for usernames.
 *
 * Checks that usernames are not:
 * - Reserved system usernames (configurable via RESERVED_USERNAMES env)
 * - Containing naughty/profane words (configurable via NAUGHTY_WORDS_LIST env)
 */
class ValidUsername implements ValidationRule
{
    protected UsernameValidator $validator;

    public function __construct()
    {
        $this->validator = new UsernameValidator();
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $result = $this->validator->validate($value);

        if (! $result['valid']) {
            $fail($result['reason'] ?? 'The :attribute is not valid.');
        }
    }
}
