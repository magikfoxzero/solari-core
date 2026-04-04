<?php

namespace NewSolari\Core\Entity\Traits;

/**
 * Trait for entities that can be created from meta-apps like Investigations.
 *
 * Provides scopes for filtering entities by source and helper methods
 * for checking entity origin.
 *
 * Required columns in the entity table:
 * - source_plugin: string|null - The plugin that created this entity (e.g., 'investigations')
 * - source_record_id: string|null - The specific record ID from the source plugin
 */
trait HasSourcePlugin
{
    /**
     * Initialize the trait - add source fields to fillable if not already present.
     */
    public function initializeHasSourcePlugin(): void
    {
        if (!in_array('source_plugin', $this->fillable)) {
            $this->fillable[] = 'source_plugin';
        }
        if (!in_array('source_record_id', $this->fillable)) {
            $this->fillable[] = 'source_record_id';
        }
    }

    /**
     * Scope query to only include entities created natively (not from meta-apps).
     * Use this in native mini-app controllers to hide investigation-created entities.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNative($query)
    {
        return $query->whereNull('source_plugin');
    }

    /**
     * Scope query to only include entities created from a specific plugin.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $plugin  The plugin identifier (e.g., 'investigations')
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromPlugin($query, string $plugin)
    {
        return $query->where('source_plugin', $plugin);
    }

    /**
     * Scope query to only include entities created from a specific record.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $plugin  The plugin identifier
     * @param  string  $recordId  The source record ID
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromRecord($query, string $plugin, string $recordId)
    {
        return $query->where('source_plugin', $plugin)
                     ->where('source_record_id', $recordId);
    }

    /**
     * Check if this entity was created from an investigation.
     */
    public function isFromInvestigation(): bool
    {
        return $this->source_plugin === 'investigations';
    }

    /**
     * Check if this entity was created natively (not from a meta-app).
     */
    public function isNative(): bool
    {
        return $this->source_plugin === null;
    }

    /**
     * Get the source plugin identifier.
     */
    public function getSourcePlugin(): ?string
    {
        return $this->source_plugin;
    }

    /**
     * Get the source record ID.
     */
    public function getSourceRecordId(): ?string
    {
        return $this->source_record_id;
    }
}
