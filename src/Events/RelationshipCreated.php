<?php

namespace NewSolari\Core\Events;

use NewSolari\Core\Entity\Models\EntityRelationship;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RelationshipCreated
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
