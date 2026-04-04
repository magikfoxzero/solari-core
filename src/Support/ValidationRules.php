<?php

namespace NewSolari\Core\Support;

/**
 * Shared validation rule constants for consistent enum validation across modules.
 *
 * This class provides standardized validation rules for common fields like
 * priority and status to ensure consistency across all modules.
 *
 * @see HIGH-013: Inconsistent Validation Rules Across Plugins
 */
class ValidationRules
{
    // =========================================================================
    // Priority Levels
    // =========================================================================

    /**
     * Standard 3-level priority: low, medium, high
     * Used by: Notes, simple content types
     */
    public const PRIORITY_3_LEVELS = ['low', 'medium', 'high'];

    /**
     * Standard 4-level priority: low, medium, high, critical
     * Used by: Tasks, Events, BlockNotes
     */
    public const PRIORITY_4_LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * Broadcast priority: low, normal, high, urgent
     * Uses "normal" instead of "medium" and "urgent" instead of "critical"
     * for user-facing broadcast message semantics
     */
    public const PRIORITY_BROADCAST = ['low', 'normal', 'high', 'urgent'];

    // =========================================================================
    // Status Values by Context
    // =========================================================================

    /**
     * Document status: draft, published, archived
     * Used by: Notes, Hypotheses, Motives, Reference Materials
     */
    public const STATUS_DOCUMENT = ['draft', 'published', 'archived'];

    /**
     * Task status: todo, in-progress, completed, cancelled, on-hold
     * Used by: Tasks
     */
    public const STATUS_TASK = ['todo', 'in-progress', 'completed', 'cancelled', 'on-hold'];

    /**
     * Event status: planned, confirmed, cancelled, completed
     * Used by: Events
     */
    public const STATUS_EVENT = ['planned', 'confirmed', 'cancelled', 'completed'];

    /**
     * BlockNote status: active, archived, completed, draft
     * Used by: BlockNotes (blockchain-style notes)
     */
    public const STATUS_BLOCKNOTE = ['active', 'archived', 'completed', 'draft'];

    // =========================================================================
    // Validation Rule Builders
    // =========================================================================

    /**
     * Build a priority validation rule.
     *
     * @param  string  $type  One of: '3-level', '4-level', 'broadcast'
     * @param  bool  $required  Whether the field is required
     * @return string Laravel validation rule string
     */
    public static function priority(string $type = '4-level', bool $required = false): string
    {
        $values = match ($type) {
            '3-level' => self::PRIORITY_3_LEVELS,
            'broadcast' => self::PRIORITY_BROADCAST,
            default => self::PRIORITY_4_LEVELS,
        };

        $prefix = $required ? 'required' : 'nullable';

        return $prefix.'|string|in:'.implode(',', $values);
    }

    /**
     * Build a status validation rule.
     *
     * @param  string  $type  One of: 'document', 'task', 'event', 'blocknote'
     * @param  bool  $required  Whether the field is required
     * @return string Laravel validation rule string
     */
    public static function status(string $type = 'document', bool $required = false): string
    {
        $values = match ($type) {
            'task' => self::STATUS_TASK,
            'event' => self::STATUS_EVENT,
            'blocknote' => self::STATUS_BLOCKNOTE,
            default => self::STATUS_DOCUMENT,
        };

        $prefix = $required ? 'required' : 'nullable';

        return $prefix.'|string|in:'.implode(',', $values);
    }

    /**
     * Get priority values as a comma-separated string for inline use.
     */
    public static function priorityValues(string $type = '4-level'): string
    {
        $values = match ($type) {
            '3-level' => self::PRIORITY_3_LEVELS,
            'broadcast' => self::PRIORITY_BROADCAST,
            default => self::PRIORITY_4_LEVELS,
        };

        return implode(',', $values);
    }

    /**
     * Get status values as a comma-separated string for inline use.
     */
    public static function statusValues(string $type = 'document'): string
    {
        $values = match ($type) {
            'task' => self::STATUS_TASK,
            'event' => self::STATUS_EVENT,
            'blocknote' => self::STATUS_BLOCKNOTE,
            default => self::STATUS_DOCUMENT,
        };

        return implode(',', $values);
    }
}
