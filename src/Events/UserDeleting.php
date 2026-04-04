<?php

namespace NewSolari\Core\Events;

use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a user is being deleted.
 *
 * Plugins can listen to this event to perform cleanup actions
 * (e.g., canceling subscriptions, cleaning up plugin-specific data).
 */
class UserDeleting
{
    use Dispatchable, SerializesModels;

    public IdentityUser $user;

    /**
     * Create a new event instance.
     */
    public function __construct(IdentityUser $user)
    {
        $this->user = $user;
    }
}
