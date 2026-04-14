<?php

namespace NewSolari\Core\Entity\Models;

use NewSolari\Core\Entity\BaseEntity;

class Entity extends BaseEntity
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'record_id',
        'partition_id',
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'partition_id' => 'required|uuid|exists:identity_partitions,record_id',
        'is_active' => 'boolean',
    ];

    /**
     * Get the partition that owns the entity.
     */
    public function partition()
    {
        return $this->belongsTo(
            app('identity.partition_model'),
            'partition_id',
            'record_id'
        );
    }

    /**
     * Check if the entity belongs to a specific partition.
     *
     * @param  string  $partitionId
     * @return bool
     */
    public function belongsToPartition($partitionId)
    {
        return $this->partition_id === $partitionId;
    }
}
