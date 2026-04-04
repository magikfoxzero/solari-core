<?php

namespace NewSolari\Core\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserUnblocked
{
    use Dispatchable, SerializesModels;

    public string $blockerUserId;
    public string $blockedUserId;

    public function __construct(string $blockerUserId, string $blockedUserId)
    {
        $this->blockerUserId = $blockerUserId;
        $this->blockedUserId = $blockedUserId;
    }
}
