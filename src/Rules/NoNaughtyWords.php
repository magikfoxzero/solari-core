<?php

namespace NewSolari\Core\Rules;

use NewSolari\Core\Security\NaughtyWordsFilter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Custom validation rule to check content for naughty/profane words.
 *
 * Uses the NaughtyWordsFilter to check content against the configured
 * word list (config/security.php -> naughty_words_list).
 */
class NoNaughtyWords implements ValidationRule
{
    protected NaughtyWordsFilter $filter;

    public function __construct()
    {
        $this->filter = new NaughtyWordsFilter();
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($this->filter->containsNaughtyWord($value)) {
            $fail('Your message contains inappropriate language. Please revise and try again.');
        }
    }
}
