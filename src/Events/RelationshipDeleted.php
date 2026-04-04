<?php

namespace NewSolari\Core\Events;

use NewSolari\Core\Identity\Models\EntityRelationship;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RelationshipDeleted
{
    use Dispatchable, SerializesModels;

    public $relationship;

    /**
     * Create a new event instance.
     */
    public function __construct(EntityRelationship $relationship)
    {
        $this->relationship = $relationship;
    }
}
