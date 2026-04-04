<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NaughtyWordsFilter
{
    /**
     * Maximum recursion depth to prevent DoS attacks
     */
    protected const MAX_RECURSION_DEPTH = 50;

    /**
     * Maximum string length to check (skip very long strings for performance)
     */
    protected const MAX_STRING_LENGTH = 100000;

    /**
     * List of naughty words stored as associative array for O(1) lookup
     *
     * @var array<string, bool>
     */
    protected array $naughtyWordsMap;

    /**
     * Original word list for iteration
     *
     * @var array<string>
     */
    protected array $naughtyWords = [];

    /**
     * Constructor - initialize the word map for efficient lookups
     *
     * Words are loaded from NAUGHTY_WORDS_LIST environment variable.
     * Format: comma-separated values (e.g., "word1,word2,word3")
     * Falls back to empty list if not configured.
     */
    public function __construct()
    {
        $this->loadWordsFromEnvironment();
        $this->buildWordMap();
    }

    /**
     * Load naughty words from config (config/bottles.php).
     *
     * IMPORTANT: Uses config() instead of env() to work properly
     * when the config is cached (php artisan config:cache).
     *
     * Expected format: NAUGHTY_WORDS_LIST="word1,word2,word3"
     */
    protected function loadWordsFromEnvironment(): void
    {
        // Use config() instead of env() for compatibility with config caching
        $configValue = config('security.naughty_words_list') ?? '';

        if (empty($configValue)) {
            $this->naughtyWords = [];

            return;
        }

        // Parse CSV string
        $words = array_map('trim', explode(',', $configValue));

        // Filter out empty strings and validate word length
        $this->naughtyWords = array_values(array_filter($words, function ($word) {
            return ! empty($word) && strlen($word) <= 100;
        }));
    }

    /**
     * Build the associative array map for O(1) lookups
     */
    protected function buildWordMap(): void
    {
        $this->naughtyWordsMap = [];
        foreach ($this->naughtyWords as $word) {
            $this->naughtyWordsMap[strtolower($word)] = true;
        }
    }

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check all input data for naughty words (including nested arrays)
        $input = $request->all();

        if ($this->checkInputForNaughtyWords($input, 0)) {
            return response()->json([
                'value' => false,
                'result' => 'Your message contains inappropriate language. Please revise and try again.',
                'code' => 400,
            ], 400);
        }

        return $next($request);
    }

    /**
     * Recursively check input for naughty words with depth limit.
     *
     * @param  mixed  $input
     * @param  int  $depth  Current recursion depth
     */
    protected function checkInputForNaughtyWords($input, int $depth = 0): bool
    {
        // Prevent DoS through deeply nested arrays
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return false;
        }

        if (is_string($input)) {
            return $this->containsNaughtyWords($input);
        }

        if (is_array($input)) {
            foreach ($input as $value) {
                if ($this->checkInputForNaughtyWords($value, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Public method to check if text contains naughty words.
     *
     * @param  string  $text  The text to check
     * @return bool True if naughty words found
     */
    public function containsNaughtyWord(string $text): bool
    {
        return $this->containsNaughtyWords($text);
    }

    /**
     * Check if text contains naughty words.
     *
     * Uses word boundary matching to avoid false positives:
     * - "ass" matches "you ass" or "ass!"
     * - "ass" does NOT match "password" or "class" or "mass"
     */
    protected function containsNaughtyWords(string $text): bool
    {
        // Skip very long strings for performance/memory protection
        if (strlen($text) > self::MAX_STRING_LENGTH) {
            return false;
        }

        $lowerText = strtolower($text);

        // Use word boundary matching to avoid false positives
        // \b matches word boundaries (start/end of word)
        foreach ($this->naughtyWords as $word) {
            $lowerWord = strtolower($word);
            // Escape special regex characters in the word
            $escapedWord = preg_quote($lowerWord, '/');
            // Match as complete word (with word boundaries)
            if (preg_match('/\b' . $escapedWord . '\b/u', $lowerText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a word to the naughty words list.
     */
    public function addNaughtyWord(string $word): void
    {
        $word = strtolower(trim($word));

        // Validate input
        if (empty($word) || strlen($word) > 100) {
            return;
        }

        // Only add if not already present (using map for O(1) check)
        if (! isset($this->naughtyWordsMap[$word])) {
            $this->naughtyWords[] = $word;
            $this->naughtyWordsMap[$word] = true;
        }
    }

    /**
     * Remove a word from the naughty words list.
     */
    public function removeNaughtyWord(string $word): void
    {
        $word = strtolower(trim($word));

        if (isset($this->naughtyWordsMap[$word])) {
            unset($this->naughtyWordsMap[$word]);
            $this->naughtyWords = array_values(array_filter(
                $this->naughtyWords,
                fn ($w) => strtolower($w) !== $word
            ));
        }
    }

    /**
     * Get the current list of naughty words (for testing/debugging)
     */
    public function getNaughtyWords(): array
    {
        return $this->naughtyWords;
    }
}
