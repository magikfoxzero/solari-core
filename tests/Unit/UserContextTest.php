<?php

namespace NewSolari\Core\Tests\Unit;

use NewSolari\Core\Identity\Contracts\AuthenticatedUserInterface;
use NewSolari\Core\Identity\UserContext;
use PHPUnit\Framework\TestCase;

class UserContextTest extends TestCase
{
    private function makeJwtClaims(array $overrides = []): object
    {
        return (object) array_merge([
            'sub' => 'user-uuid-123',
            'partition_id' => 'partition-uuid-456',
            'username' => 'jdoe',
            'email' => 'jdoe@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'is_system_user' => false,
            'is_active' => true,
            'is_partition_admin' => false,
            'permissions' => ['read', 'write'],
            'groups' => ['editors'],
        ], $overrides);
    }

    private function makeApiResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'record_id' => 'user-uuid-789',
            'partition_id' => 'partition-uuid-012',
            'username' => 'asmith',
            'email' => 'asmith@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'is_system_user' => false,
            'is_active' => true,
            'is_partition_admin' => true,
            'permissions' => ['admin.read', 'admin.write'],
            'groups' => ['admin', 'moderators'],
        ], $overrides);
    }

    public function test_creates_from_jwt_claims(): void
    {
        $claims = $this->makeJwtClaims();
        $context = UserContext::fromJwtClaims($claims);

        $this->assertSame('user-uuid-123', $context->record_id);
        $this->assertSame('user-uuid-123', $context->getRecordId());
        $this->assertSame('partition-uuid-456', $context->getPartitionId());
        $this->assertSame('jdoe', $context->getUsername());
        $this->assertSame('jdoe@example.com', $context->getEmail());
        $this->assertSame('John', $context->getFirstName());
        $this->assertSame('Doe', $context->getLastName());
        $this->assertSame('John Doe', $context->getDisplayName());
        $this->assertFalse($context->isSystemUser());
        $this->assertTrue($context->isActive());
        $this->assertSame(['read', 'write'], $context->permissions);
        $this->assertSame(['editors'], $context->groups);
    }

    public function test_creates_from_api_response(): void
    {
        $data = $this->makeApiResponse();
        $context = UserContext::fromApiResponse($data);

        $this->assertSame('user-uuid-789', $context->record_id);
        $this->assertSame('user-uuid-789', $context->getRecordId());
        $this->assertSame('partition-uuid-012', $context->getPartitionId());
        $this->assertSame('asmith', $context->getUsername());
        $this->assertSame('asmith@example.com', $context->getEmail());
        $this->assertSame('Alice', $context->getFirstName());
        $this->assertSame('Smith', $context->getLastName());
        $this->assertSame('Alice Smith', $context->getDisplayName());
        $this->assertFalse($context->isSystemUser());
        $this->assertTrue($context->isActive());
        $this->assertTrue($context->is_partition_admin);
        $this->assertSame(['admin.read', 'admin.write'], $context->permissions);
        $this->assertSame(['admin', 'moderators'], $context->groups);
    }

    public function test_system_user_has_all_permissions(): void
    {
        $claims = $this->makeJwtClaims([
            'is_system_user' => true,
            'permissions' => [],
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertTrue($context->hasPermission('anything'));
        $this->assertTrue($context->hasPermission('nonexistent.permission'));
        $this->assertTrue($context->hasPermission(''));
    }

    public function test_system_user_is_admin_of_all_partitions(): void
    {
        $claims = $this->makeJwtClaims([
            'is_system_user' => true,
            'is_partition_admin' => false,
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertTrue($context->isPartitionAdmin('any-partition'));
        $this->assertTrue($context->isPartitionAdmin('different-partition'));
        $this->assertTrue($context->isPartitionAdmin($context->getPartitionId()));
    }

    public function test_non_system_user_permission_check(): void
    {
        $claims = $this->makeJwtClaims([
            'permissions' => ['read', 'write'],
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertTrue($context->hasPermission('read'));
        $this->assertTrue($context->hasPermission('write'));
        $this->assertFalse($context->hasPermission('delete'));
        $this->assertFalse($context->hasPermission('admin'));
    }

    public function test_non_system_user_partition_admin(): void
    {
        $claims = $this->makeJwtClaims([
            'is_partition_admin' => true,
            'partition_id' => 'my-partition',
        ]);
        $context = UserContext::fromJwtClaims((object) array_merge((array) $claims, [
            'sub' => 'user-uuid-123',
        ]));

        // Matches own partition
        $this->assertTrue($context->isPartitionAdmin('my-partition'));

        // Does not match other partitions
        $this->assertFalse($context->isPartitionAdmin('other-partition'));
    }

    public function test_non_admin_user_is_not_partition_admin(): void
    {
        $claims = $this->makeJwtClaims([
            'is_partition_admin' => false,
        ]);
        $context = UserContext::fromJwtClaims($claims);

        // Not admin even for own partition
        $this->assertFalse($context->isPartitionAdmin($context->getPartitionId()));
    }

    public function test_display_name_computed_from_first_and_last_name(): void
    {
        $claims = $this->makeJwtClaims([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertSame('Jane Doe', $context->getDisplayName());
    }

    public function test_display_name_falls_back_to_username_when_names_null(): void
    {
        $claims = $this->makeJwtClaims([
            'first_name' => null,
            'last_name' => null,
            'username' => 'fallback_user',
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertSame('fallback_user', $context->getDisplayName());
    }

    public function test_display_name_uses_first_name_only_when_last_name_null(): void
    {
        $claims = $this->makeJwtClaims([
            'first_name' => 'Jane',
            'last_name' => null,
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertSame('Jane', $context->getDisplayName());
    }

    public function test_implements_authenticated_user_interface(): void
    {
        $claims = $this->makeJwtClaims();
        $context = UserContext::fromJwtClaims($claims);

        $this->assertInstanceOf(AuthenticatedUserInterface::class, $context);
    }

    public function test_has_group_check(): void
    {
        $claims = $this->makeJwtClaims([
            'groups' => ['admin', 'moderators'],
        ]);
        $context = UserContext::fromJwtClaims($claims);

        $this->assertTrue($context->hasGroup('admin'));
        $this->assertTrue($context->hasGroup('moderators'));
        $this->assertFalse($context->hasGroup('editors'));
    }

    public function test_handles_missing_optional_jwt_claims(): void
    {
        $claims = (object) [
            'sub' => 'user-uuid-minimal',
            'partition_id' => 'part-1',
            'username' => 'minimal',
        ];
        $context = UserContext::fromJwtClaims($claims);

        $this->assertSame('user-uuid-minimal', $context->getRecordId());
        $this->assertNull($context->getEmail());
        $this->assertNull($context->getFirstName());
        $this->assertNull($context->getLastName());
        $this->assertSame('minimal', $context->getDisplayName());
        $this->assertFalse($context->isSystemUser());
        $this->assertTrue($context->isActive());
        $this->assertSame([], $context->permissions);
        $this->assertSame([], $context->groups);
    }
}
