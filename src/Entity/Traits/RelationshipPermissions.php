<?php

namespace NewSolari\Core\Entity\Traits;

use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\EntityRelationship;
use Illuminate\Database\Eloquent\Model;

trait RelationshipPermissions
{
    /**
     * Check if a user can attach an entity to this entity.
     */
    public function canAttachEntity(?IdentityUser $user, Model|string $entity, string $relationshipType): bool
    {
        // If no user provided, deny by default
        if (! $user) {
            return false;
        }

        // Check if user can update this entity
        if (! $this->canUserUpdate($user)) {
            return false;
        }

        // Check partition-level permissions
        if (! $this->isInUserPartition($user)) {
            return false;
        }

        // Additional custom permission checks can be added here
        // Override this method in the model for custom logic

        return true;
    }

    /**
     * Check if a user can detach an entity from this entity.
     */
    public function canDetachEntity(?IdentityUser $user, Model|string $entity, string $relationshipType): bool
    {
        // If no user provided, deny by default
        if (! $user) {
            return false;
        }

        // Check if user can update this entity
        if (! $this->canUserUpdate($user)) {
            return false;
        }

        // Check partition-level permissions
        if (! $this->isInUserPartition($user)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a user can view a specific relationship.
     */
    public function canViewRelationship(?IdentityUser $user, EntityRelationship $relationship): bool
    {
        // If no user provided, deny by default
        if (! $user) {
            return false;
        }

        // Check if relationship belongs to this entity
        if (! $this->ownsRelationship($relationship)) {
            return false;
        }

        // Check partition-level permissions
        if (! $this->isInUserPartition($user)) {
            return false;
        }

        // Check if user can view this entity
        if (! $this->canUserView($user)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a user can update a specific relationship.
     */
    public function canUpdateRelationship(?IdentityUser $user, EntityRelationship $relationship): bool
    {
        // If no user provided, deny by default
        if (! $user) {
            return false;
        }

        // Check if relationship belongs to this entity
        if (! $this->ownsRelationship($relationship)) {
            return false;
        }

        // Check if user can update this entity
        if (! $this->canUserUpdate($user)) {
            return false;
        }

        // Check partition-level permissions
        if (! $this->isInUserPartition($user)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a user can delete a specific relationship.
     */
    public function canDeleteRelationship(?IdentityUser $user, EntityRelationship $relationship): bool
    {
        // Same as update for now
        return $this->canUpdateRelationship($user, $relationship);
    }

    /**
     * Check if this entity owns a relationship (as source or target).
     */
    protected function ownsRelationship(EntityRelationship $relationship): bool
    {
        $entityType = $this->getEntityType();
        $entityId = $this->getKey();

        return ($relationship->source_type === $entityType && $relationship->source_id === $entityId)
            || ($relationship->target_type === $entityType && $relationship->target_id === $entityId);
    }

    /**
     * Check if user can view this entity.
     * Override this method in the model for custom logic.
     */
    protected function canUserView(IdentityUser $user): bool
    {
        // Default implementation - check if in same partition
        return $this->isInUserPartition($user);
    }

    /**
     * Check if user can update this entity.
     * Override this method in the model for custom logic.
     */
    protected function canUserUpdate(IdentityUser $user): bool
    {
        // Default implementation - check if in same partition
        return $this->isInUserPartition($user);
    }

    /**
     * Check if entity is in user's partition.
     */
    protected function isInUserPartition(IdentityUser $user): bool
    {
        // If entity doesn't have partition_id, allow access
        if (! isset($this->partition_id)) {
            return true;
        }

        // Check if user has access to this partition
        return $user->partitions()
            ->where('identity_partitions.record_id', $this->partition_id)
            ->exists();
    }

    /**
     * Get the entity type key for the model.
     * This method should be provided by HasUnifiedRelationships trait
     * or implemented in the model.
     */
    abstract protected function getEntityType(?\Illuminate\Database\Eloquent\Model $model = null): string;
}
