<?php

use NewSolari\Core\Identity\Controllers\BroadcastAuthController;
use NewSolari\Core\Identity\Controllers\GroupController;
use NewSolari\Core\Identity\Controllers\IdentityController;
use NewSolari\Core\Identity\Controllers\PartitionAppsController;
use NewSolari\Core\Identity\Controllers\PartitionController;
use NewSolari\Core\Identity\Controllers\PermissionController;
use NewSolari\Core\Identity\Controllers\RecordSharesController;
use NewSolari\Core\Identity\Controllers\RegistryController;
use NewSolari\Core\Identity\Controllers\PasskeyController;
use NewSolari\Core\Identity\Controllers\SystemController;
use NewSolari\Core\Identity\Controllers\ModuleController;
use NewSolari\Core\Identity\Controllers\WebSocketTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Core API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the CoreServiceProvider and provide core
| identity, auth, admin, and system functionality.
| Wrapped in api prefix + middleware to match Laravel's api route loading.
|
*/

Route::prefix('api')->middleware('api')->group(function () {

// System routes
Route::middleware(['module.enabled:system'])->prefix('system')->group(function () {
    // Public system routes (no auth required)
    Route::get('/status', [SystemController::class, 'status']);
    Route::get('/health', [SystemController::class, 'health']);

    // Authenticated system routes with permissions
    Route::middleware(['auth.api'])->group(function () {
        Route::middleware(['permission:system.info'])->get('/info', [SystemController::class, 'info']);
        Route::middleware(['permission:system.maintenance'])->group(function () {
            Route::post('/maintenance/enable', [SystemController::class, 'enableMaintenance']);
            Route::post('/maintenance/disable', [SystemController::class, 'disableMaintenance']);
        });
    });
});

// Public authentication routes (no auth required)
// Rate limited to prevent brute force and abuse
Route::middleware(['module.enabled:auth'])->prefix('auth')->group(function () {
    // Login: configurable via RATE_LIMIT_AUTH_LOGIN (default: 10 per minute per IP)
    Route::middleware(['throttle:auth-login'])->post('/login', [IdentityController::class, 'login']);
    // Token refresh: configurable via RATE_LIMIT_AUTH_REFRESH (default: 30 per minute)
    Route::middleware(['throttle:auth-refresh'])->post('/refresh', [IdentityController::class, 'refreshToken']);
    // Registration: configurable via RATE_LIMIT_AUTH_REGISTER (default: 20 per minute per IP)
    Route::middleware(['throttle:auth-register'])->post('/register', [IdentityController::class, 'register']);

    // Password reset routes (with stricter rate limiting to prevent email bombing)
    Route::middleware(['throttle.email'])->group(function () {
        Route::post('/password/forgot', [IdentityController::class, 'forgotPassword']);
        Route::post('/password/reset', [IdentityController::class, 'resetPassword']);

        // Email verification routes
        Route::post('/email/verify', [IdentityController::class, 'verifyEmail']);
        Route::post('/email/resend', [IdentityController::class, 'resendVerificationEmail']);

        // Account recovery (for passkey-only users who lost their passkey)
        Route::post('/recover', [IdentityController::class, 'requestRecovery']);
        Route::post('/recover/verify', [IdentityController::class, 'verifyRecovery']);
    });

    // Auth configuration endpoint (returns available auth methods)
    Route::get('/config', [IdentityController::class, 'getAuthConfig']);
});

// Passkey (WebAuthn) routes
Route::middleware(['module.enabled:passkeys'])->prefix('passkeys')->group(function () {
    // Public routes (no auth required)
    Route::get('/config', [PasskeyController::class, 'getConfig']);
    Route::middleware(['throttle:passkey-auth'])->post('/authenticate/options', [PasskeyController::class, 'authenticateOptions']);
    Route::middleware(['throttle:passkey-auth'])->post('/authenticate/verify', [PasskeyController::class, 'authenticateVerify']);

    // Authenticated routes (for managing user's passkeys)
    Route::middleware(['auth.api'])->group(function () {
        // Rate limit registration - configurable via RATE_LIMIT_PASSKEY_REGISTER (default: 15 per minute)
        Route::middleware(['throttle:passkey-register'])->post('/register/options', [PasskeyController::class, 'registerOptions']);
        Route::middleware(['throttle:passkey-register'])->post('/register/verify', [PasskeyController::class, 'registerVerify']);
        Route::get('/', [PasskeyController::class, 'list']);
        Route::delete('/{id}', [PasskeyController::class, 'delete']);
        Route::patch('/{id}', [PasskeyController::class, 'update']);
    });
});

// Public partition list (for login dropdown - no auth required)
// Rate limited via RATE_LIMIT_PUBLIC_READ (default: 60/min)
Route::middleware(['throttle:public-read'])->get('/partitions/public', [PartitionController::class, 'publicList']);

// Public registration status check (no auth required)
// Rate limited via RATE_LIMIT_PUBLIC_READ (default: 60/min)
Route::middleware(['throttle:public-read'])->get('/partitions/{id}/registration-status', [PartitionController::class, 'registrationStatus']);

// Broadcasting auth (uses JWT authentication or short-lived WS token)
Route::middleware(['auth.api'])->post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);

// WebSocket token generation (FE-CRIT-002: short-lived tokens for WebSocket auth)
// Rate limited via RATE_LIMIT_PUBLIC_READ (default: 60/min)
Route::middleware(['auth.api', 'throttle:public-read'])->post('/ws/token', [WebSocketTokenController::class, 'generateToken']);

// Authenticated authentication routes
Route::middleware(['auth.api', 'module.enabled:auth'])->prefix('auth')->group(function () {
    // /me and /logout are user's own actions - require users.read permission
    Route::middleware(['permission:users.read'])->get('/me', [IdentityController::class, 'getCurrentUser']);
    Route::post('/logout', [IdentityController::class, 'logout']); // No permission needed - own logout

    // Send verification email for authenticated user (used with allow_unverified_login setting)
    Route::post('/email/send-verification', [IdentityController::class, 'sendMyVerificationEmail']);

    // Debug endpoint only available in non-production environments
    if (config('app.env') !== 'production') {
        Route::get('/debug-permissions', [IdentityController::class, 'debugPermissions']);
    }
});

// Module manifest (authenticated, no specific permission required)
Route::middleware(['auth.api'])->get('/modules/frontend-manifest', [ModuleController::class, 'frontendManifest']);

// Identity system routes with authentication middleware
Route::middleware(['auth.api'])->group(function () {
    // Users routes with permissions
    Route::prefix('users')->group(function () {
        Route::middleware(['permission:users.read'])->group(function () {
            Route::get('/', [IdentityController::class, 'index']);
            // Simple list for recipient pickers - must be before /{id} to avoid conflict
            Route::get('/simple', [IdentityController::class, 'listSimple']);
            Route::get('/{id}', [IdentityController::class, 'show']);
        });
        Route::middleware(['permission:users.create'])->post('/', [IdentityController::class, 'store']);
        Route::middleware(['permission:users.update'])->put('/{id}', [IdentityController::class, 'update']);
        Route::middleware(['permission:users.delete'])->delete('/{id}', [IdentityController::class, 'destroy']);
        Route::middleware(['permission:users.password'])->post('/{id}/password', [IdentityController::class, 'updatePassword']);
    });

    // Partitions routes with permissions
    Route::prefix('partitions')->group(function () {
        Route::middleware(['permission:partitions.read'])->group(function () {
            Route::get('/', [PartitionController::class, 'index']);
            Route::get('/{id}', [PartitionController::class, 'show']);
            Route::get('/{id}/users', [PartitionController::class, 'getUsers']);
        });
        Route::middleware(['permission:partitions.create'])->post('/', [PartitionController::class, 'store']);
        Route::middleware(['permission:partitions.update'])->put('/{id}', [PartitionController::class, 'update']);
        Route::middleware(['permission:partitions.delete'])->delete('/{id}', [PartitionController::class, 'destroy']);
        // User management within partitions - uses users.manage permission
        Route::middleware(['permission:users.manage'])->group(function () {
            Route::post('/{id}/users/{userId}', [PartitionController::class, 'addUser']);
            Route::delete('/{id}/users/{userId}', [PartitionController::class, 'removeUser']);
        });

        // Partition Apps routes (discovery and management) with permissions
        Route::middleware(['permission:partition_apps.read'])->group(function () {
            Route::get('/{partitionId}/apps', [PartitionController::class, 'apps']); // Discovery endpoint for UI
            Route::get('/{partitionId}/apps/{pluginId}', [PartitionAppsController::class, 'show']); // Get single app details
        });
        Route::middleware(['permission:partition_apps.update'])->group(function () {
            Route::post('/{partitionId}/apps/{pluginId}/enable', [PartitionAppsController::class, 'enable']); // Enable app
            Route::post('/{partitionId}/apps/{pluginId}/disable', [PartitionAppsController::class, 'disable']); // Disable app
            // Visibility toggles with rate limiting - configurable via RATE_LIMIT_WRITE_STANDARD
            Route::middleware(['throttle:write-standard'])->group(function () {
                Route::post('/{partitionId}/apps/{pluginId}/visibility', [PartitionAppsController::class, 'toggleVisibility']); // Toggle UI visibility
                Route::post('/{partitionId}/apps/{pluginId}/dashboard-visibility', [PartitionAppsController::class, 'toggleDashboardVisibility']); // Toggle dashboard visibility
                Route::post('/{partitionId}/apps/{pluginId}/exclude-meta-app', [PartitionAppsController::class, 'toggleExcludeMetaApp']); // Toggle exclude meta-app data
                Route::post('/{partitionId}/apps/{pluginId}/admin-only', [PartitionAppsController::class, 'toggleAdminOnly']); // Toggle admin-only access
            });
            // API-MED-NEW-007: Bulk endpoints use idempotency middleware for retry safety
            Route::put('/{partitionId}/apps', [PartitionAppsController::class, 'bulkUpdate'])->middleware('idempotent'); // Bulk update
        });
    });

    // Groups routes with permissions
    Route::prefix('groups')->group(function () {
        Route::middleware(['permission:groups.read'])->group(function () {
            Route::get('/', [GroupController::class, 'index']);
            Route::get('/{id}', [GroupController::class, 'show']);
            Route::get('/{id}/users', [GroupController::class, 'getUsers']);
            Route::get('/{id}/permissions', [GroupController::class, 'getPermissions']);
        });
        Route::middleware(['permission:groups.create'])->post('/', [GroupController::class, 'store']);
        Route::middleware(['permission:groups.update'])->put('/{id}', [GroupController::class, 'update']);
        Route::middleware(['permission:groups.delete'])->delete('/{id}', [GroupController::class, 'destroy']);
        Route::middleware(['permission:groups.manage'])->group(function () {
            Route::post('/{id}/users/{userId}', [GroupController::class, 'addUser']);
            Route::delete('/{id}/users/{userId}', [GroupController::class, 'removeUser']);
            Route::post('/{id}/permissions/{permissionId}', [GroupController::class, 'assignPermission']);
            Route::delete('/{id}/permissions/{permissionId}', [GroupController::class, 'revokePermission']);
        });
    });

    // Permissions routes with permissions
    Route::prefix('permissions')->group(function () {
        Route::middleware(['permission:permissions.read'])->group(function () {
            Route::get('/', [PermissionController::class, 'index']);
            Route::get('/types', [PermissionController::class, 'getPermissionTypes']);
            Route::get('/{id}', [PermissionController::class, 'show']);
        });
        Route::middleware(['permission:permissions.create'])->post('/', [PermissionController::class, 'store']);
        Route::middleware(['permission:permissions.update'])->put('/{id}', [PermissionController::class, 'update']);
        Route::middleware(['permission:permissions.delete'])->delete('/{id}', [PermissionController::class, 'destroy']);
    });

    // User permission routes with permissions
    Route::prefix('users')->middleware(['permission:permissions.assign'])->group(function () {
        Route::post('/{userId}/permissions/{permissionId}', [PermissionController::class, 'assignUserPermission']);
        Route::delete('/{userId}/permissions/{permissionId}', [PermissionController::class, 'revokeUserPermission']);
    });

    // Registry/Settings routes with permissions
    Route::prefix('registry/settings')->group(function () {
        Route::middleware(['permission:settings.read'])->group(function () {
            Route::get('/', [RegistryController::class, 'index']);
            Route::get('/user', [RegistryController::class, 'getUserSettings']);
            Route::get('/partition', [RegistryController::class, 'getPartitionSettings']);
            Route::get('/system', [RegistryController::class, 'getSystemSettings']);
            Route::get('/{id}', [RegistryController::class, 'show']);
        });
        Route::middleware(['permission:settings.create'])->post('/', [RegistryController::class, 'store']);
        Route::middleware(['permission:settings.update'])->put('/{id}', [RegistryController::class, 'update']);
        Route::middleware(['permission:settings.delete'])->delete('/{id}', [RegistryController::class, 'destroy']);
    });

    // Admin-only routes (system admins only, no partition scoping)
    Route::prefix('admin')->middleware(['permission:system.admin'])->group(function () {
        // Admin partition management - view/edit/delete ALL partitions
        Route::prefix('partitions')->group(function () {
            Route::get('/', [PartitionController::class, 'adminIndex']);
            Route::get('/{id}', [PartitionController::class, 'adminShow']);
            Route::put('/{id}', [PartitionController::class, 'adminUpdate']);
            Route::delete('/{id}', [PartitionController::class, 'adminDestroy']);
        });

        // System operations (artisan command wrappers)
        Route::prefix('system')->group(function () {
            Route::post('/cleanup-idempotency', [SystemController::class, 'cleanupIdempotency']);
            Route::post('/archive-records', [SystemController::class, 'archiveRecords']);
            Route::post('/purge-archived', [SystemController::class, 'purgeArchived']);
            Route::post('/cleanup-orphans', [SystemController::class, 'cleanupOrphans']);
            Route::post('/cleanup-push-tokens', [SystemController::class, 'cleanupPushTokens']);
            Route::post('/gdpr-purge', [SystemController::class, 'gdprPurge']);
            Route::post('/test-push', [SystemController::class, 'testPush']);
        });
    });

    // Record Sharing routes
    // Global "shared with me" endpoint - get all records shared with the current user
    Route::middleware(['throttle:public-read', 'permission:record_shares.read'])->get('/shared-with-me', [RecordSharesController::class, 'sharedWithMe']);

    // Per-entity share endpoints (supports all shareable entity types)
    // Allowed entity types: notes, files, tasks, events, folders, people, entities, places, hypotheses, motives, reference_materials, inventory_objects, investigations, tags, invoices, budgets
    $shareableTypes = 'notes|files|tasks|events|folders|people|entities|places|hypotheses|motives|reference_materials|inventory_objects|investigations|tags|invoices|budgets';

    Route::middleware(['throttle:shares-read', 'permission:record_shares.read'])
        ->get('/{entityType}/{entityId}/shares', [RecordSharesController::class, 'index'])
        ->where('entityType', $shareableTypes);

    Route::middleware(['throttle:write-standard', 'permission:record_shares.create'])
        ->post('/{entityType}/{entityId}/shares', [RecordSharesController::class, 'store'])
        ->where('entityType', $shareableTypes);

    Route::middleware(['throttle:write-standard', 'permission:record_shares.update'])
        ->put('/{entityType}/{entityId}/shares/{userId}', [RecordSharesController::class, 'update'])
        ->where('entityType', $shareableTypes);

    Route::middleware(['throttle:write-standard', 'permission:record_shares.delete'])
        ->delete('/{entityType}/{entityId}/shares/{userId}', [RecordSharesController::class, 'destroy'])
        ->where('entityType', $shareableTypes);
});

// User block routes
Route::middleware(['auth.api'])->prefix('user-blocks')->group(function () {
    Route::get('/', [\NewSolari\Core\Identity\Controllers\UserBlockController::class, 'index']);
    Route::post('/{userId}', [\NewSolari\Core\Identity\Controllers\UserBlockController::class, 'block']);
    Route::delete('/{userId}', [\NewSolari\Core\Identity\Controllers\UserBlockController::class, 'unblock']);
    Route::get('/{userId}/status', [\NewSolari\Core\Identity\Controllers\UserBlockController::class, 'status']);
});

}); // End Route::prefix('api')
