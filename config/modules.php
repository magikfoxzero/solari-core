<?php

return [
    'system'              => ['enabled' => env('MODULE_SYSTEM_ENABLED', true)],
    'iap'                 => ['enabled' => env('MODULE_IAP_ENABLED', true)],
    'auth'                => ['enabled' => env('MODULE_AUTH_ENABLED', true)],
    'passkeys'            => ['enabled' => env('MODULE_PASSKEYS_ENABLED', true)],
    'people'              => ['enabled' => env('MODULE_PEOPLE_ENABLED', true)],
    'entities'            => ['enabled' => env('MODULE_ENTITIES_ENABLED', true)],
    'events'              => ['enabled' => env('MODULE_EVENTS_ENABLED', true)],
    'notes'               => ['enabled' => env('MODULE_NOTES_ENABLED', true)],
    'hypotheses'          => ['enabled' => env('MODULE_HYPOTHESES_ENABLED', true)],
    'motives'             => ['enabled' => env('MODULE_MOTIVES_ENABLED', true)],
    'tags'                => ['enabled' => env('MODULE_TAGS_ENABLED', true)],
    'tasks'               => ['enabled' => env('MODULE_TASKS_ENABLED', true)],
    'files'               => ['enabled' => env('MODULE_FILES_ENABLED', true)],
    'folders'             => ['enabled' => env('MODULE_FOLDERS_ENABLED', true)],
    'places'              => ['enabled' => env('MODULE_PLACES_ENABLED', true)],
    'inventory-objects'   => ['enabled' => env('MODULE_INVENTORY_OBJECTS_ENABLED', true)],
    'blocknotes'          => ['enabled' => env('MODULE_BLOCKNOTES_ENABLED', true)],
    'broadcast-messages'  => ['enabled' => env('MODULE_BROADCAST_MESSAGES_ENABLED', true)],
    'login-banners'       => ['enabled' => env('MODULE_LOGIN_BANNERS_ENABLED', true)],
    'news'                => ['enabled' => env('MODULE_NEWS_ENABLED', true)],
    'private-messages'    => ['enabled' => env('MODULE_PRIVATE_MESSAGES_ENABLED', true)],
    'reference-materials' => ['enabled' => env('MODULE_REFERENCE_MATERIALS_ENABLED', true)],
    'push'                => ['enabled' => env('MODULE_PUSH_ENABLED', true)],
    'investigations'      => ['enabled' => env('MODULE_INVESTIGATIONS_ENABLED', true)],
    'bottles'             => ['enabled' => env('MODULE_BOTTLES_ENABLED', true)],
    'budgets'             => ['enabled' => env('MODULE_BUDGETS_ENABLED', true)],
    'invoices'            => ['enabled' => env('MODULE_INVOICES_ENABLED', true)],
    'event-plans'         => ['enabled' => env('MODULE_EVENT_PLANS_ENABLED', true)],
    'prompts'             => ['enabled' => env('MODULE_PROMPTS_ENABLED', true)],
    'youtube-transcripts' => ['enabled' => env('MODULE_YOUTUBE_TRANSCRIPTS_ENABLED', true)],
    'support'             => ['enabled' => env('MODULE_SUPPORT_ENABLED', true)],
    'notifications'       => ['enabled' => env('MODULE_NOTIFICATIONS_ENABLED', true)],
    'chat'                => ['enabled' => env('MODULE_CHAT_ENABLED', true)],

    /*
    |--------------------------------------------------------------------------
    | Remote/Extracted Services
    |--------------------------------------------------------------------------
    |
    | Modules that have been extracted to standalone services. These are NOT
    | loaded as Composer packages — they run as separate processes behind
    | Apache ProxyPass. Listed here so the frontend manifest endpoint
    | includes them for dynamic route/navigation loading.
    |
    | Each entry needs: id, name, type, and frontend manifest data.
    |
    */
    'remote_services' => [
        'login-banners' => [
            'enabled' => env('MODULE_LOGIN_BANNERS_ENABLED', true),
            'id' => 'login-banners',
            'name' => 'Login Banners',
            'type' => 'mini-app',
            'description' => 'Configurable banners displayed on the login page for announcements, alerts, and notifications',
            'frontend' => [
                'navigation' => [
                    'label' => 'Login Banners',
                    'icon' => 'Bell',
                    'section' => 'admin',
                    'path' => '/apps/login-banners',
                    'order' => 90,
                ],
            ],
        ],
        'broadcast-messages' => [
            'enabled' => env('MODULE_BROADCAST_MESSAGES_ENABLED', true),
            'id' => 'broadcast-messages',
            'name' => 'Broadcast Messages',
            'type' => 'mini-app',
            'description' => 'System-wide broadcast messages for announcements, alerts, and scheduled communications',
            'frontend' => [
                'navigation' => [
                    'label' => 'Broadcasts',
                    'icon' => 'Radio',
                    'section' => 'admin',
                    'path' => '/apps/broadcasts',
                    'order' => 80,
                ],
            ],
        ],
        'news' => [
            'enabled' => env('MODULE_NEWS_ENABLED', true),
            'id' => 'news',
            'name' => 'News',
            'type' => 'mini-app',
            'description' => 'News articles, announcements, and content management with read tracking',
            'frontend' => [
                'navigation' => [
                    'label' => 'News',
                    'icon' => 'Newspaper',
                    'section' => 'apps',
                    'path' => '/apps/news',
                    'order' => 110,
                ],
            ],
        ],
        'tags' => [
            'enabled' => env('MODULE_TAGS_ENABLED', true),
            'id' => 'tags',
            'name' => 'Tags',
            'type' => 'mini-app',
            'description' => 'Tagging system for organizing and categorizing records across modules',
            'frontend' => [
                'navigation' => [
                    'label' => 'Tags',
                    'icon' => 'Hash',
                    'section' => 'apps',
                    'path' => '/apps/tags',
                    'order' => 55,
                ],
            ],
        ],
        'notes' => [
            'enabled' => env('MODULE_NOTES_ENABLED', true),
            'id' => 'notes',
            'name' => 'Notes',
            'type' => 'mini-app',
            'description' => 'Encrypted note-taking with rich text support and organization features',
            'frontend' => [
                'navigation' => [
                    'label' => 'Notes',
                    'icon' => 'FileText',
                    'section' => 'apps',
                    'path' => '/apps/notes',
                    'order' => 20,
                ],
            ],
        ],
        'tasks' => [
            'enabled' => env('MODULE_TASKS_ENABLED', true),
            'id' => 'tasks',
            'name' => 'Tasks',
            'type' => 'mini-app',
            'description' => 'Task management with checklists, dependencies, and priority tracking',
            'frontend' => [
                'navigation' => [
                    'label' => 'Tasks',
                    'icon' => 'CheckSquare',
                    'section' => 'apps',
                    'path' => '/apps/tasks',
                    'order' => 25,
                ],
            ],
        ],
        'people' => [
            'enabled' => env('MODULE_PEOPLE_ENABLED', true),
            'id' => 'people',
            'name' => 'People',
            'type' => 'mini-app',
            'description' => 'Contact management with addresses, phone numbers, and email tracking',
            'frontend' => [
                'navigation' => [
                    'label' => 'People',
                    'icon' => 'Users',
                    'section' => 'apps',
                    'path' => '/apps/people',
                    'order' => 10,
                ],
            ],
        ],
        'events' => [
            'enabled' => env('MODULE_EVENTS_ENABLED', true),
            'id' => 'events',
            'name' => 'Events',
            'type' => 'mini-app',
            'description' => 'Event management with scheduling, participants, and calendar integration',
            'frontend' => [
                'navigation' => [
                    'label' => 'Events',
                    'icon' => 'Calendar',
                    'section' => 'apps',
                    'path' => '/apps/events',
                    'order' => 30,
                ],
            ],
        ],
        'places' => [
            'enabled' => env('MODULE_PLACES_ENABLED', true),
            'id' => 'places',
            'name' => 'Places',
            'type' => 'mini-app',
            'description' => 'Location management with addresses, coordinates, and nearby search',
            'frontend' => [
                'navigation' => [
                    'label' => 'Places',
                    'icon' => 'MapPin',
                    'section' => 'apps',
                    'path' => '/apps/places',
                    'order' => 35,
                ],
            ],
        ],
        'entities' => [
            'enabled' => env('MODULE_ENTITIES_ENABLED', true),
            'id' => 'entities',
            'name' => 'Entities',
            'type' => 'mini-app',
            'description' => 'Organization and business entity management with addresses and contacts',
            'frontend' => [
                'navigation' => [
                    'label' => 'Entities',
                    'icon' => 'Building2',
                    'section' => 'apps',
                    'path' => '/apps/entities',
                    'order' => 40,
                ],
            ],
        ],
        'folders' => [
            'enabled' => env('MODULE_FOLDERS_ENABLED', true),
            'id' => 'folders',
            'name' => 'Folders',
            'type' => 'mini-app',
            'description' => 'File and document organization with hierarchical folder structure',
            'frontend' => [
                'navigation' => [
                    'label' => 'Folders',
                    'icon' => 'Folder',
                    'section' => 'apps',
                    'path' => '/apps/folders',
                    'order' => 50,
                ],
            ],
        ],
        'blocknotes' => [
            'enabled' => env('MODULE_BLOCKNOTES_ENABLED', true),
            'id' => 'blocknotes',
            'name' => 'BlockNotes',
            'type' => 'mini-app',
            'description' => 'Block-based note editor with rich content support',
            'frontend' => [
                'navigation' => [
                    'label' => 'BlockNotes',
                    'icon' => 'Disc',
                    'section' => 'apps',
                    'path' => '/apps/blocknotes',
                    'order' => 80,
                ],
            ],
        ],
        'hypotheses' => [
            'enabled' => env('MODULE_HYPOTHESES_ENABLED', true),
            'id' => 'hypotheses',
            'name' => 'Hypotheses',
            'type' => 'mini-app',
            'description' => 'Hypothesis tracking and validation for investigations',
            'frontend' => [
                'navigation' => [
                    'label' => 'Hypotheses',
                    'icon' => 'Lightbulb',
                    'section' => 'apps',
                    'path' => '/apps/hypotheses',
                    'order' => 60,
                ],
            ],
        ],
        'motives' => [
            'enabled' => env('MODULE_MOTIVES_ENABLED', true),
            'id' => 'motives',
            'name' => 'Motives',
            'type' => 'mini-app',
            'description' => 'Motive analysis and tracking for investigations',
            'frontend' => [
                'navigation' => [
                    'label' => 'Motives',
                    'icon' => 'Target',
                    'section' => 'apps',
                    'path' => '/apps/motives',
                    'order' => 65,
                ],
            ],
        ],
        'inventory-objects' => [
            'enabled' => env('MODULE_INVENTORY_OBJECTS_ENABLED', true),
            'id' => 'inventory-objects',
            'name' => 'Inventory Objects',
            'type' => 'mini-app',
            'description' => 'Physical inventory and evidence tracking with QR codes',
            'frontend' => [
                'navigation' => [
                    'label' => 'Inventory Objects',
                    'icon' => 'Package',
                    'section' => 'apps',
                    'path' => '/apps/inventory-objects',
                    'order' => 70,
                ],
            ],
        ],
        'reference-materials' => [
            'enabled' => env('MODULE_REFERENCE_MATERIALS_ENABLED', true),
            'id' => 'reference-materials',
            'name' => 'Reference Materials',
            'type' => 'mini-app',
            'description' => 'Reference document management with tagging and verification',
            'frontend' => [
                'navigation' => [
                    'label' => 'Reference Materials',
                    'icon' => 'BookOpen',
                    'section' => 'apps',
                    'path' => '/apps/reference-materials',
                    'order' => 75,
                ],
            ],
        ],
        'support' => [
            'enabled' => env('MODULE_SUPPORT_ENABLED', true),
            'id' => 'support',
            'name' => 'Support',
            'type' => 'mini-app',
            'description' => 'Help desk with tickets, FAQ, feedback, and policy management',
            'frontend' => [
                'navigation' => [
                    'label' => 'Support',
                    'icon' => 'Headphones',
                    'section' => 'apps',
                    'path' => '/apps/support',
                    'order' => 115,
                ],
            ],
        ],
        'budgets' => [
            'enabled' => env('MODULE_BUDGETS_ENABLED', true),
            'id' => 'budgets',
            'name' => 'Budgets',
            'type' => 'mini-app',
            'description' => 'Budget planning with categories and line items',
            'frontend' => [
                'navigation' => [
                    'label' => 'Budgets',
                    'icon' => 'Wallet',
                    'section' => 'apps',
                    'path' => '/apps/budgets',
                    'order' => 85,
                ],
            ],
        ],
        'invoices' => [
            'enabled' => env('MODULE_INVOICES_ENABLED', true),
            'id' => 'invoices',
            'name' => 'Invoices',
            'type' => 'mini-app',
            'description' => 'Invoice management with line items, payments, and auto-numbering',
            'frontend' => [
                'navigation' => [
                    'label' => 'Invoices',
                    'icon' => 'Receipt',
                    'section' => 'apps',
                    'path' => '/apps/invoices',
                    'order' => 90,
                ],
            ],
        ],
        'prompts' => [
            'enabled' => env('MODULE_PROMPTS_ENABLED', true),
            'id' => 'prompts',
            'name' => 'Prompts',
            'type' => 'mini-app',
            'description' => 'AI prompt management with chains, versioning, and testing',
            'frontend' => [
                'navigation' => [
                    'label' => 'Prompts',
                    'icon' => 'Sparkles',
                    'section' => 'apps',
                    'path' => '/apps/prompts',
                    'order' => 95,
                ],
            ],
        ],
        'private-messages' => [
            'enabled' => env('MODULE_PRIVATE_MESSAGES_ENABLED', true),
            'id' => 'private-messages',
            'name' => 'Private Messages',
            'type' => 'mini-app',
            'description' => 'Private messaging between users',
            'frontend' => [
                'navigation' => [
                    'label' => 'Messages',
                    'icon' => 'MessageCircle',
                    'section' => 'apps',
                    'path' => '/apps/messages',
                    'order' => 105,
                ],
            ],
        ],
        'files' => [
            'enabled' => env('MODULE_FILES_ENABLED', true),
            'id' => 'files',
            'name' => 'Files',
            'type' => 'mini-app',
            'description' => 'File management with encryption, versioning, and AI-powered content analysis',
            'frontend' => [
                'navigation' => [
                    'label' => 'Files',
                    'icon' => 'FolderOpen',
                    'section' => 'apps',
                    'path' => '/apps/files',
                    'order' => 45,
                ],
            ],
        ],
        'youtube-transcripts' => [
            'enabled' => env('MODULE_YOUTUBE_TRANSCRIPTS_ENABLED', true),
            'id' => 'youtube-transcripts',
            'name' => 'YouTube Transcripts',
            'type' => 'mini-app',
            'description' => 'YouTube channel and video transcript management with import and export',
            'frontend' => [
                'navigation' => [
                    'label' => 'YouTube',
                    'icon' => 'Video',
                    'section' => 'apps',
                    'path' => '/apps/youtube-transcripts',
                    'order' => 100,
                ],
            ],
        ],
        'push' => [
            'enabled' => env('MODULE_PUSH_ENABLED', true),
            'id' => 'push',
            'name' => 'Push Notifications',
            'type' => 'infrastructure',
            'description' => 'Web and native push notification service with VAPID support',
            'frontend' => null,
        ],
        'notifications' => [
            'enabled' => env('MODULE_NOTIFICATIONS_ENABLED', true),
            'id' => 'notifications',
            'name' => 'Notification Center',
            'type' => 'infrastructure',
            'description' => 'Unified notification system with badge counts, history, and push integration',
            'frontend' => null,
        ],
        'chat' => [
            'enabled' => env('MODULE_CHAT_ENABLED', true),
            'id' => 'chat',
            'name' => 'Chat',
            'type' => 'mini-app',
            'description' => 'Real-time chat rooms with slash commands, games, and economy system',
            'frontend' => [
                'navigation' => [
                    'label' => 'Chat',
                    'icon' => 'MessageCircle',
                    'section' => 'apps',
                    'path' => '/apps/chat',
                    'order' => 100,
                ],
            ],
        ],
        'ai' => [
            'enabled' => env('MODULE_AI_ENABLED', true),
            'id' => 'ai',
            'name' => 'AI Service',
            'type' => 'infrastructure',
            'description' => 'Claude AI integration for content generation, moderation, and analysis',
            'frontend' => null,
        ],
        'websocket' => [
            'enabled' => env('MODULE_WEBSOCKET_ENABLED', true),
            'id' => 'websocket',
            'name' => 'WebSocket Service',
            'type' => 'infrastructure',
            'description' => 'Real-time WebSocket infrastructure — Reverb, channel auth, broadcasting',
            'frontend' => null,
        ],
        'bottles' => [
            'enabled' => env('MODULE_BOTTLES_ENABLED', true),
            'id' => 'bottles',
            'name' => 'Message in a Bottle',
            'type' => 'meta-app',
            'description' => 'Ocean-themed messaging with drift mechanics, pen pals, and community economy',
            'frontend' => [
                'navigation' => [
                    'label' => 'Bottles',
                    'icon' => 'Waves',
                    'section' => 'apps',
                    'path' => '/apps/bottles',
                    'order' => 5,
                ],
            ],
        ],
    ],
];
