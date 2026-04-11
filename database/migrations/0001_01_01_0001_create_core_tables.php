<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for CORE module tables.
 * Auto-generated from schema dump.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        if (!Schema::hasTable('email_verification_tokens')) {
            Schema::create('email_verification_tokens', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->char('user_id', 36);
                $table->string('token', 64);
                $table->timestamp('created_at')->nullable();
                $table->boolean('deleted')->default(false);
                $table->index('token', 'email_verification_tokens_token_index');
                $table->index('user_id', 'email_verification_tokens_user_id_index');
                $table->index('deleted', 'email_verification_tokens_deleted_index');
                $table->foreign('user_id')->references('record_id')->on('identity_users');
            });
        }

        if (!Schema::hasTable('entity_relationships')) {
            Schema::create('entity_relationships', function (Blueprint $table) {
                $table->string('record_id', 36)->primary();
                $table->string('source_type', 64);
                $table->string('source_id', 36);
                $table->string('target_type', 64);
                $table->string('target_id', 36);
                $table->string('relationship_type', 64);
                $table->string('relationship_subtype', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->integer('priority')->default(0);
                $table->boolean('is_primary')->default(false);
                $table->string('partition_id', 36);
                $table->string('created_by', 36)->nullable();
                $table->string('updated_by', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['source_type', 'source_id', 'target_type', 'target_id', 'relationship_type', 'partition_id'], 'idx_unique_relationship');
                $table->index(['source_type', 'source_id', 'relationship_type'], 'idx_source_lookup');
                $table->index(['target_type', 'target_id', 'relationship_type'], 'idx_target_lookup');
                $table->index(['partition_id', 'relationship_type'], 'idx_partition_type');
                $table->index(['source_type', 'source_id', 'relationship_type', 'priority'], 'idx_priority_sort');
                $table->index(['source_type', 'source_id', 'relationship_type', 'is_primary'], 'idx_primary_lookup');
                $table->index('source_type', 'entity_relationships_source_type_index');
                $table->index('source_id', 'entity_relationships_source_id_index');
                $table->index('target_type', 'entity_relationships_target_type_index');
                $table->index('target_id', 'entity_relationships_target_id_index');
                $table->index('relationship_type', 'entity_relationships_relationship_type_index');
                $table->index('relationship_subtype', 'entity_relationships_relationship_subtype_index');
                $table->index('priority', 'entity_relationships_priority_index');
                $table->index('is_primary', 'entity_relationships_is_primary_index');
                $table->index('partition_id', 'entity_relationships_partition_id_index');
                $table->index('deleted', 'entity_relationships_deleted_index');
                $table->index('created_by', 'entity_relationships_created_by_foreign');
                $table->index('updated_by', 'entity_relationships_updated_by_foreign');
                $table->foreign('created_by')->references('record_id')->on('identity_users')->onDelete('set null');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions')->onDelete('cascade');
                $table->foreign('updated_by')->references('record_id')->on('identity_users')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('entity_type_registry')) {
            Schema::create('entity_type_registry', function (Blueprint $table) {
                $table->string('type_key', 64)->primary();
                $table->string('model_class');
                $table->string('table_name', 64);
                $table->string('display_name', 128);
                $table->string('display_name_plural', 128)->nullable();
                $table->string('icon', 32)->nullable();
                $table->string('category', 64)->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_system')->default(false);
                $table->string('plugin_id', 64)->nullable();
                $table->json('config')->nullable();
                $table->string('created_by', 36)->nullable();
                $table->string('updated_by', 36)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->string('deleted_by', 36)->nullable();
                $table->timestamps();
                $table->index('model_class', 'entity_type_registry_model_class_index');
                $table->index('table_name', 'entity_type_registry_table_name_index');
                $table->index('is_active', 'entity_type_registry_is_active_index');
                $table->index('plugin_id', 'entity_type_registry_plugin_id_index');
                $table->index(['category', 'is_active'], 'entity_type_registry_category_is_active_index');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid');
                $table->string('connection');
                $table->string('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
                $table->unique('uuid', 'failed_jobs_uuid_unique');
            });
        }

        if (!Schema::hasTable('group_permissions')) {
            Schema::create('group_permissions', function (Blueprint $table) {
                $table->char('group_id', 36);
                $table->char('permission_id', 36);
                $table->timestamps();
                $table->primary(['group_id', 'permission_id']);
                $table->index('permission_id', 'idx_group_permissions_permission');
                $table->foreign('group_id')->references('record_id')->on('groups');
                $table->foreign('permission_id')->references('record_id')->on('permissions');
            });
        }

        if (!Schema::hasTable('groups')) {
            Schema::create('groups', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->char('partition_id', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['name', 'partition_id'], 'groups_name_partition_unique');
                $table->index('deleted', 'groups_deleted_index');
                $table->index('partition_id', 'groups_partition_id_foreign');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions');
            });
        }

        if (!Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->string('idempotency_key');
                $table->char('user_id', 36);
                $table->string('request_path', 500);
                $table->string('request_method', 10);
                $table->string('request_path_hash', 64)->comment('SHA-256 hash of request_path for indexing');
                $table->string('request_hash', 64)->comment('SHA-256 hash of request body');
                $table->integer('response_status');
                $table->longText('response_body');
                $table->timestamp('expires_at');
                $table->timestamps();
                $table->unique(['idempotency_key', 'user_id', 'request_path_hash', 'request_method'], 'idempotency_unique');
                $table->index('expires_at', 'idempotency_keys_expires_at_index');
                $table->index('user_id', 'idempotency_keys_user_id_foreign');
                $table->foreign('user_id')->references('record_id')->on('identity_users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('identity_partitions')) {
            Schema::create('identity_partitions', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique('name', 'identity_partitions_name_unique');
                $table->index('deleted', 'identity_partitions_deleted_index');
            });
        }

        if (!Schema::hasTable('identity_user_groups')) {
            Schema::create('identity_user_groups', function (Blueprint $table) {
                $table->char('user_id', 36);
                $table->char('group_id', 36);
                $table->timestamps();
                $table->primary(['user_id', 'group_id']);
                $table->index('group_id', 'idx_user_groups_group');
                $table->foreign('group_id')->references('record_id')->on('groups');
                $table->foreign('user_id')->references('record_id')->on('identity_users');
            });
        }

        if (!Schema::hasTable('identity_user_partitions')) {
            Schema::create('identity_user_partitions', function (Blueprint $table) {
                $table->char('user_id', 36);
                $table->char('partition_id', 36);
                $table->timestamps();
                $table->primary(['user_id', 'partition_id']);
                $table->index('partition_id', 'idx_user_partitions_partition');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions');
                $table->foreign('user_id')->references('record_id')->on('identity_users');
            });
        }

        if (!Schema::hasTable('identity_user_permissions')) {
            Schema::create('identity_user_permissions', function (Blueprint $table) {
                $table->char('user_id', 36);
                $table->char('permission_id', 36);
                $table->timestamps();
                $table->primary(['user_id', 'permission_id']);
                $table->index('permission_id', 'idx_user_permissions_permission');
                $table->foreign('permission_id')->references('record_id')->on('permissions');
                $table->foreign('user_id')->references('record_id')->on('identity_users');
            });
        }

        if (!Schema::hasTable('identity_users')) {
            Schema::create('identity_users', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->char('partition_id', 36);
                $table->string('username');
                $table->string('email')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->boolean('requires_email_verification')->default(false);
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('password_hash');
                $table->boolean('password_required')->default(true);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('failed_login_attempts')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamp('last_failed_login_at')->nullable();
                $table->string('remember_token', 100)->nullable();
                $table->string('account_recovery_token')->nullable();
                $table->timestamp('account_recovery_expires')->nullable();
                $table->boolean('is_system_user')->default(false);
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['username', 'partition_id'], 'identity_users_username_partition_unique');
                $table->unique(['email', 'partition_id'], 'identity_users_email_partition_unique');
                $table->index('deleted', 'identity_users_deleted_index');
                $table->index('partition_id', 'identity_users_partition_id_foreign');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions');
            });
        }

        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue');
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
                $table->index('queue', 'jobs_queue_index');
            });
        }

        if (!Schema::hasTable('migration_baseline')) {
            Schema::create('migration_baseline', function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64);
                $table->bigInteger('row_count_total')->default(0);
                $table->bigInteger('row_count_active')->default(0);
                $table->bigInteger('row_count_deleted')->default(0);
                $table->string('content_checksum', 64)->nullable();
                $table->bigInteger('unique_sources')->default(0);
                $table->bigInteger('unique_targets')->default(0);
                $table->bigInteger('orphan_count')->default(0);
                $table->enum('migration_status', ['baseline_captured', 'migration_in_progress', 'migration_completed', 'validation_passed', 'validation_failed', 'rolled_back'])->default('baseline_captured');
                $table->timestamp('migration_started_at')->nullable();
                $table->timestamp('migration_completed_at')->nullable();
                $table->bigInteger('rows_migrated')->default(0);
                $table->bigInteger('rows_failed')->default(0);
                $table->text('validation_errors')->nullable();
                $table->json('validation_details')->nullable();
                $table->timestamp('captured_at')->useCurrent();
                $table->string('captured_by', 36)->nullable();
                $table->unique(['table_name', 'captured_at'], 'idx_baseline_snapshot');
                $table->index('migration_status', 'migration_baseline_migration_status_index');
                $table->index('migration_started_at', 'migration_baseline_migration_started_at_index');
                $table->index('migration_completed_at', 'migration_baseline_migration_completed_at_index');
                $table->index('table_name', 'migration_baseline_table_name_index');
            });
        }

        if (!Schema::hasTable('migration_errors')) {
            Schema::create('migration_errors', function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64);
                $table->string('record_id', 36)->nullable();
                $table->text('error_message');
                $table->json('error_context')->nullable();
                $table->enum('error_type', ['missing_source', 'missing_target', 'invalid_data', 'constraint_violation', 'transformation_error', 'other'])->default('other');
                $table->timestamp('occurred_at')->useCurrent();
                $table->index(['table_name', 'error_type'], 'migration_errors_table_name_error_type_index');
                $table->index('occurred_at', 'migration_errors_occurred_at_index');
            });
        }

        if (!Schema::hasTable('partition_apps')) {
            Schema::create('partition_apps', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->string('partition_id', 36);
                $table->string('plugin_id', 100);
                $table->boolean('is_enabled')->default(true);
                $table->boolean('show_in_ui')->default(true);
                $table->boolean('show_in_dashboard')->default(true);
                $table->boolean('exclude_meta_app')->default(false);
                $table->boolean('admin_only')->default(false);
                $table->string('enabled_by', 36)->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->json('configuration')->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['partition_id', 'plugin_id'], 'partition_plugin_unique');
                $table->index('enabled_by', 'partition_apps_enabled_by_foreign');
                $table->index('partition_id', 'partition_apps_partition_id_index');
                $table->index('plugin_id', 'partition_apps_plugin_id_index');
                $table->index('is_enabled', 'partition_apps_is_enabled_index');
                $table->index(['partition_id', 'is_enabled'], 'partition_enabled_idx');
                $table->index(['partition_id', 'show_in_ui'], 'partition_ui_visibility_idx');
                $table->index(['partition_id', 'show_in_dashboard'], 'partition_dashboard_visibility_idx');
                $table->index('deleted', 'partition_apps_deleted_index');
                $table->foreign('enabled_by')->references('record_id')->on('identity_users')->onDelete('set null');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions');
            });
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email');
                $table->char('partition_id', 36)->nullable();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
                $table->primary(['email', 'partition_id']);
            });
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('permission_type');
                $table->string('entity_type');
                $table->string('plugin_id')->nullable();
                $table->char('partition_id', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['name', 'partition_id'], 'permissions_name_partition_unique');
                $table->index('partition_id', 'permissions_partition_id_foreign');
                $table->index('deleted', 'permissions_deleted_index');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->string('record_id', 36)->primary();
                $table->string('user_id', 36);
                $table->string('partition_id', 36);
                $table->text('endpoint');
                if (DB::getDriverName() === 'sqlite') {
                    $table->string('endpoint_hash', 64)->nullable();
                } else {
                    $table->string('endpoint_hash', 64)->storedAs('SHA2(endpoint, 256)');
                }
                $table->string('p256dh_key', 512);
                $table->string('auth_key', 256);
                $table->string('user_agent')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->unique('endpoint_hash', 'push_subscriptions_endpoint_unique');
                $table->index(['user_id', 'partition_id'], 'push_subscriptions_user_id_partition_id_index');
                $table->index('partition_id', 'push_subscriptions_partition_id_foreign');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions');
                $table->foreign('user_id')->references('record_id')->on('identity_users');
            });
        }

        if (!Schema::hasTable('record_shares')) {
            Schema::create('record_shares', function (Blueprint $table) {
                $table->string('record_id', 36)->primary();
                $table->string('shareable_type', 64);
                $table->string('shareable_id', 36);
                $table->string('shared_with_user_id', 36);
                $table->string('permission', 10)->default('read');
                $table->string('shared_by', 36);
                $table->timestamp('expires_at')->nullable();
                $table->string('share_message', 500)->nullable();
                $table->string('partition_id', 36);
                $table->string('created_by', 36);
                $table->string('updated_by', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->string('deleted_by', 36)->nullable();
                $table->timestamps();
                $table->unique(['shareable_type', 'shareable_id', 'shared_with_user_id', 'partition_id'], 'record_shares_unique');
                $table->index('created_by', 'record_shares_created_by_foreign');
                $table->index('updated_by', 'record_shares_updated_by_foreign');
                $table->index('deleted_by', 'record_shares_deleted_by_foreign');
                $table->index(['shareable_type', 'shareable_id'], 'record_shares_shareable_lookup');
                $table->index(['shared_with_user_id', 'shareable_type', 'deleted'], 'record_shares_user_type');
                $table->index(['partition_id', 'shareable_type', 'deleted'], 'record_shares_partition_type');
                $table->index(['expires_at', 'deleted'], 'record_shares_expiration');
                $table->index('shared_by', 'record_shares_shared_by_foreign');
                $table->foreign('created_by')->references('record_id')->on('identity_users');
                $table->foreign('deleted_by')->references('record_id')->on('identity_users')->onDelete('set null');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions');
                $table->foreign('shared_by')->references('record_id')->on('identity_users');
                $table->foreign('shared_with_user_id')->references('record_id')->on('identity_users');
                $table->foreign('updated_by')->references('record_id')->on('identity_users')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('registry_settings')) {
            Schema::create('registry_settings', function (Blueprint $table) {
                $table->char('record_id', 36)->primary();
                $table->string('key');
                $table->text('value');
                $table->enum('scope', ['user', 'partition', 'system']);
                $table->char('scope_id', 36)->nullable();
                $table->char('partition_id', 36)->nullable();
                $table->char('created_by', 36)->nullable();
                $table->char('updated_by', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['key', 'scope', 'scope_id'], 'registry_settings_key_scope_scope_id_unique');
                $table->index(['scope', 'scope_id'], 'registry_settings_scope_scope_id_index');
                $table->index('partition_id', 'registry_settings_partition_id_index');
                $table->index('created_by', 'registry_settings_created_by_index');
                $table->index('updated_by', 'registry_settings_updated_by_index');
                $table->index('deleted', 'registry_settings_deleted_index');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('relationship_type_registry')) {
            Schema::create('relationship_type_registry', function (Blueprint $table) {
                $table->string('type_key', 64)->primary();
                $table->string('category', 32);
                $table->string('display_name', 128);
                $table->text('description')->nullable();
                $table->string('inverse_type', 64)->nullable();
                $table->boolean('allows_duplicates')->default(false);
                $table->boolean('requires_metadata')->default(false);
                $table->json('metadata_schema')->nullable();
                $table->boolean('supports_priority')->default(false);
                $table->boolean('supports_primary')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_system')->default(false);
                $table->string('plugin_id', 64)->nullable();
                $table->json('config')->nullable();
                $table->string('created_by', 36)->nullable();
                $table->string('updated_by', 36)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->string('deleted_by', 36)->nullable();
                $table->timestamps();
                $table->index('category', 'relationship_type_registry_category_index');
                $table->index('is_active', 'relationship_type_registry_is_active_index');
                $table->index('plugin_id', 'relationship_type_registry_plugin_id_index');
                $table->index(['category', 'is_active'], 'relationship_type_registry_category_is_active_index');
                $table->index('inverse_type', 'relationship_type_registry_inverse_type_foreign');
                $table->foreign('inverse_type')->references('type_key')->on('relationship_type_registry')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity');
                $table->index('user_id', 'sessions_user_id_index');
                $table->index('last_activity', 'sessions_last_activity_index');
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('remember_token', 100)->nullable();
                $table->timestamps();
                $table->unique('email', 'users_email_unique');
            });
        }

        if (!Schema::hasTable('user_passkeys')) {
            Schema::create('user_passkeys', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('user_id', 36);
                $table->binary('credential_id');
                $table->string('credential_id_hash', 64)->nullable();
                $table->binary('public_key');
                $table->unsignedInteger('sign_count')->default(0);
                $table->json('transports')->nullable();
                $table->string('device_name')->nullable();
                $table->string('aaguid', 36)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->index('user_id', 'idx_user_passkeys_user');
                $table->index('credential_id_hash', 'idx_user_passkeys_credential_hash');
                $table->foreign('user_id')->references('record_id')->on('identity_users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('native_push_tokens')) {
            Schema::create('native_push_tokens', function (Blueprint $table) {
                $table->id();
                $table->char('record_id', 36);
                $table->char('user_id', 36);
                $table->char('partition_id', 36);
                $table->string('token', 512);
                $table->string('platform', 16);
                $table->string('device_info')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->unique('record_id', 'native_push_tokens_record_id_unique');
                $table->unique('token', 'native_push_tokens_token_unique');
                $table->index(['user_id', 'partition_id'], 'native_push_tokens_user_id_partition_id_index');
                $table->index('platform', 'native_push_tokens_platform_index');
                $table->index('partition_id', 'native_push_tokens_partition_id_foreign');
                $table->foreign('partition_id')->references('record_id')->on('identity_partitions')->onDelete('cascade');
                $table->foreign('user_id')->references('record_id')->on('identity_users')->onDelete('cascade');
            });
        }

        // Archive table
        if (!Schema::hasTable('entity_relationships_archive')) {
            Schema::create('entity_relationships_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('source_type', 64);
                $table->string('source_id', 36);
                $table->string('target_type', 64);
                $table->string('target_id', 36);
                $table->string('relationship_type', 64);
                $table->string('relationship_subtype', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->integer('priority')->default(0);
                $table->boolean('is_primary')->default(false);
                $table->string('partition_id', 36);
                $table->string('created_by', 36);
                $table->string('updated_by', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_entity_relationships_archive_partition_record');
                $table->index('archived_at', 'idx_entity_relationships_archive_archived_at');
                $table->index('original_record_id', 'entity_relationships_archive_original_record_id_index');
            });
        }

        // Archive table
        if (!Schema::hasTable('groups_archive')) {
            Schema::create('groups_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->char('partition_id', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_groups_archive_partition_record');
                $table->index('archived_at', 'idx_groups_archive_archived_at');
                $table->index('original_record_id', 'groups_archive_original_record_id_index');
            });
        }

        // Archive table
        if (!Schema::hasTable('identity_partitions_archive')) {
            Schema::create('identity_partitions_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index('archived_at', 'idx_identity_partitions_archive_archived_at');
                $table->index('original_record_id', 'identity_partitions_archive_original_record_id_index');
            });
        }

        // Archive table
        if (!Schema::hasTable('identity_users_archive')) {
            Schema::create('identity_users_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->char('partition_id', 36);
                $table->string('username');
                $table->string('email')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->boolean('requires_email_verification')->default(false);
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('password_hash');
                $table->string('totp_secret')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('failed_login_attempts')->default(0);
                $table->timestamp('locked_until')->nullable();
                $table->timestamp('last_failed_login_at')->nullable();
                $table->string('remember_token', 100)->nullable();
                $table->boolean('is_system_user')->default(false);
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_identity_users_archive_partition_record');
                $table->index('archived_at', 'idx_identity_users_archive_archived_at');
                $table->index('original_record_id', 'identity_users_archive_original_record_id_index');
            });
        }

        // Archive table
        if (!Schema::hasTable('permissions_archive')) {
            Schema::create('permissions_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('permission_type');
                $table->string('entity_type');
                $table->string('plugin_id')->nullable();
                $table->char('partition_id', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_permissions_archive_partition_record');
                $table->index('archived_at', 'idx_permissions_archive_archived_at');
                $table->index('original_record_id', 'permissions_archive_original_record_id_index');
            });
        }

        // Archive table
        if (!Schema::hasTable('record_shares_archive')) {
            Schema::create('record_shares_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('shareable_type', 64);
                $table->string('shareable_id', 36);
                $table->string('shared_with_user_id', 36);
                $table->string('permission', 10);
                $table->string('shared_by', 36);
                $table->timestamp('expires_at')->nullable();
                $table->string('share_message', 500)->nullable();
                $table->string('partition_id', 36);
                $table->string('created_by', 36);
                $table->string('updated_by', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->string('deleted_by', 36)->nullable();
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_record_shares_archive_partition_record');
                $table->index('archived_at', 'idx_record_shares_archive_archived_at');
                $table->index('original_record_id', 'record_shares_archive_original_record_id_index');
            });
        }

        // Archive table
        if (!Schema::hasTable('registry_settings_archive')) {
            Schema::create('registry_settings_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('key');
                $table->text('value');
                $table->string('scope');
                $table->char('scope_id', 36)->nullable();
                $table->char('partition_id', 36)->nullable();
                $table->char('created_by', 36)->nullable();
                $table->char('updated_by', 36)->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_registry_settings_archive_partition_record');
                $table->index('archived_at', 'idx_registry_settings_archive_archived_at');
                $table->index('original_record_id', 'registry_settings_archive_original_record_id_index');
            });
        }

        // User blocks (shared across modules)
        if (!Schema::hasTable('user_blocks')) {
            Schema::create('user_blocks', function (Blueprint $table) {
                $table->string('record_id', 36)->primary();
                $table->string('blocker_user_id', 36);
                $table->string('blocked_user_id', 36);
                $table->text('reason')->nullable();
                $table->string('partition_id', 36);
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->unique(['blocker_user_id', 'blocked_user_id'], 'unique_user_block');
                $table->index('blocker_user_id', 'idx_user_blocks_blocker');
                $table->index('blocked_user_id', 'idx_user_blocks_blocked');
                $table->index('partition_id', 'idx_user_blocks_partition');
                $table->index('deleted', 'idx_user_blocks_deleted');
            });
        }

        // User soft bans (admin-only shadow bans)
        if (!Schema::hasTable('user_soft_bans')) {
            Schema::create('user_soft_bans', function (Blueprint $table) {
                $table->string('record_id', 36)->primary();
                $table->string('user_id', 36);
                $table->string('banned_by', 36);
                $table->text('reason')->nullable();
                $table->timestamp('banned_until')->nullable();
                $table->string('partition_id', 36);
                $table->boolean('deleted')->default(false);
                $table->string('deleted_by', 36)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
                $table->index(['partition_id', 'user_id', 'deleted'], 'idx_soft_bans_lookup');
                $table->index('banned_until', 'idx_soft_bans_expiry');
                $table->index('user_id', 'idx_soft_bans_user');
            });
        }

        // Core modules registry
        if (!Schema::hasTable('core_modules')) {
            Schema::create('core_modules', function (Blueprint $table) {
                $table->string('id', 50)->primary();
                $table->string('name', 255);
                $table->string('version', 20);
                $table->string('type', 20);
                $table->string('status', 20)->default('enabled');
                $table->string('table_prefix', 30);
                $table->json('config')->nullable();
                $table->timestamp('installed_at');
                $table->timestamp('updated_at');
            });
        }
    }

    /**
        Schema::enableForeignKeyConstraints();
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('user_soft_bans');
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('core_modules');
        Schema::dropIfExists('registry_settings_archive');
        Schema::dropIfExists('record_shares_archive');
        Schema::dropIfExists('permissions_archive');
        Schema::dropIfExists('identity_users_archive');
        Schema::dropIfExists('identity_partitions_archive');
        Schema::dropIfExists('groups_archive');
        Schema::dropIfExists('entity_relationships_archive');
        Schema::dropIfExists('native_push_tokens');
        Schema::dropIfExists('user_passkeys');
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('relationship_type_registry');
        Schema::dropIfExists('registry_settings');
        Schema::dropIfExists('record_shares');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('partition_apps');
        Schema::dropIfExists('migration_errors');
        Schema::dropIfExists('migration_baseline');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('identity_users');
        Schema::dropIfExists('identity_user_permissions');
        Schema::dropIfExists('identity_user_partitions');
        Schema::dropIfExists('identity_user_groups');
        Schema::dropIfExists('identity_partitions');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('group_permissions');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('entity_type_registry');
        Schema::dropIfExists('entity_relationships');
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::enableForeignKeyConstraints();
    }
};
