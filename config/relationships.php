<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Unified Relationships Feature Flags
    |--------------------------------------------------------------------------
    |
    | These feature flags control the rollout of the unified relationship
    | system. They allow for gradual migration and easy rollback if needed.
    |
    */

    'features' => [
        // Master switch for unified relationships system
        'enabled' => env('UNIFIED_RELATIONSHIPS_ENABLED', false),

        // Enable dual-write to both old and new tables during migration
        'dual_write_enabled' => env('DUAL_WRITE_ENABLED', false),

        // Use compatibility views instead of direct table access
        'use_compatibility_views' => env('USE_COMPATIBILITY_VIEWS', false),

        // Enable relationship API endpoints
        'api_enabled' => env('RELATIONSHIP_API_ENABLED', false),

        // Log all relationship operations for debugging
        'debug_logging' => env('RELATIONSHIP_DEBUG_LOGGING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Table Mappings
    |--------------------------------------------------------------------------
    |
    | Maps legacy pivot tables to their unified relationship equivalents.
    | Used during migration and for backward compatibility.
    |
    */

    'legacy_mappings' => [
        'investigation_people' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'person',
            'target_column' => 'person_id',
            'relationship_type' => 'participant',
            'metadata_fields' => ['role', 'relationship', 'statement', 'is_key_person'],
        ],

        'investigation_entities' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'entity',
            'target_column' => 'entity_id',
            'relationship_type' => 'involves',
            'metadata_fields' => ['role', 'notes'],
        ],

        'investigation_tags' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'tag',
            'target_column' => 'tag_id',
            'relationship_type' => 'tagged_with',
            'metadata_fields' => [],
        ],

        'investigation_notes' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'note',
            'target_column' => 'note_id',
            'relationship_type' => 'contains',
            'metadata_fields' => [],
        ],

        'investigation_tasks' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'task',
            'target_column' => 'task_id',
            'relationship_type' => 'contains',
            'metadata_fields' => [],
        ],

        'investigation_folders' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'folder',
            'target_column' => 'folder_id',
            'relationship_type' => 'contains',
            'metadata_fields' => [],
        ],

        'investigation_files' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'file',
            'target_column' => 'file_id',
            'relationship_type' => 'contains',
            'metadata_fields' => [],
        ],

        'investigation_events' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'event',
            'target_column' => 'event_id',
            'relationship_type' => 'contains',
            'metadata_fields' => [],
        ],

        'investigation_evidence' => [
            'source_type' => 'investigation',
            'source_column' => 'investigation_id',
            'target_type' => 'inventory_object',
            'target_column' => 'evidence_id',
            'relationship_type' => 'contains',
            'metadata_fields' => ['description', 'collected_date'],
        ],

        'event_participants' => [
            'source_type' => 'event',
            'source_column' => 'event_id',
            'target_type' => 'person',
            'target_column' => 'person_id',
            'relationship_type' => 'participant',
            'metadata_fields' => ['role', 'status', 'response_status'],
        ],

        'note_tags' => [
            'source_type' => 'note',
            'source_column' => 'note_id',
            'target_type' => 'tag',
            'target_column' => 'tag_id',
            'relationship_type' => 'tagged_with',
            'metadata_fields' => [],
        ],

        'blocknote_tags' => [
            'source_type' => 'blocknote',
            'source_column' => 'blocknote_id',
            'target_type' => 'tag',
            'target_column' => 'tag_id',
            'relationship_type' => 'tagged_with',
            'metadata_fields' => [],
        ],

        'reference_material_tags' => [
            'source_type' => 'reference_material',
            'source_column' => 'reference_material_id',
            'target_type' => 'tag',
            'target_column' => 'tag_id',
            'relationship_type' => 'tagged_with',
            'metadata_fields' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Categories
    |--------------------------------------------------------------------------
    |
    | Defines the categories used for classifying relationship types.
    |
    */

    'categories' => [
        'classification' => 'Classification (Tags, Categories)',
        'participation' => 'Participation (People, Groups)',
        'membership' => 'Membership (Groups, Organizations)',
        'reference' => 'Reference (Links, Citations)',
        'containment' => 'Containment (Folders, Storage)',
        'evidence' => 'Evidence (Chain of Custody)',
        'dependency' => 'Dependency (Prerequisites)',
        'assignment' => 'Assignment (Ownership)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for performance optimization.
    |
    */

    'performance' => [
        // Cache relationship lookups
        'cache_enabled' => env('RELATIONSHIP_CACHE_ENABLED', true),

        // Cache TTL in seconds
        'cache_ttl' => env('RELATIONSHIP_CACHE_TTL', 3600),

        // Batch size for bulk operations
        'batch_size' => env('RELATIONSHIP_BATCH_SIZE', 1000),

        // Enable query result caching
        'query_cache' => env('RELATIONSHIP_QUERY_CACHE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for relationship validation.
    |
    */

    'validation' => [
        // Validate entity existence before creating relationships
        'validate_entity_existence' => env('RELATIONSHIP_VALIDATE_EXISTENCE', true),

        // Validate metadata against schema
        'validate_metadata_schema' => env('RELATIONSHIP_VALIDATE_METADATA', true),

        // Prevent circular dependencies
        'prevent_circular_dependencies' => env('RELATIONSHIP_PREVENT_CIRCULAR', true),

        // Maximum depth for relationship traversal
        'max_traversal_depth' => env('RELATIONSHIP_MAX_DEPTH', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for data migration from legacy tables.
    |
    */

    'migration' => [
        // Chunk size for processing records during migration
        'chunk_size' => env('MIGRATION_CHUNK_SIZE', 500),

        // Delay between chunks (milliseconds) to reduce database load
        'chunk_delay' => env('MIGRATION_CHUNK_DELAY', 100),

        // Capture baseline before migration
        'capture_baseline' => env('MIGRATION_CAPTURE_BASELINE', true),

        // Validate after migration
        'validate_after_migration' => env('MIGRATION_VALIDATE', true),

        // Stop on first error or continue
        'stop_on_error' => env('MIGRATION_STOP_ON_ERROR', false),

        // Log level for migration operations
        'log_level' => env('MIGRATION_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for relationship API endpoints.
    |
    */

    'api' => [
        // Route prefix for relationship endpoints
        'route_prefix' => env('RELATIONSHIP_API_PREFIX', 'api/v1'),

        // Rate limiting
        'rate_limit' => env('RELATIONSHIP_RATE_LIMIT', 60),

        // Pagination settings
        'default_per_page' => env('RELATIONSHIP_PER_PAGE', 50),
        'max_per_page' => env('RELATIONSHIP_MAX_PER_PAGE', 200),

        // Include soft-deleted relationships in API responses
        'include_deleted' => env('RELATIONSHIP_INCLUDE_DELETED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Broadcasting
    |--------------------------------------------------------------------------
    |
    | Configuration for relationship event broadcasting.
    |
    */

    'events' => [
        // Enable event dispatching
        'enabled' => env('RELATIONSHIP_EVENTS_ENABLED', true),

        // Queue events instead of dispatching synchronously
        'queue_events' => env('RELATIONSHIP_QUEUE_EVENTS', false),

        // Events to dispatch
        'dispatch' => [
            'creating' => true,
            'created' => true,
            'updating' => true,
            'updated' => true,
            'deleting' => true,
            'deleted' => true,
        ],
    ],

];
