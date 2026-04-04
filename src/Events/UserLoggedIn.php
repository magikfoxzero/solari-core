<?php

namespace NewSolari\Core\Events;

use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserLoggedIn
{
    use Dispatchable, SerializesModels;

    public IdentityUser $user;
    public string $partitionId;
    public ?string $timezone;
    public string $loginMethod;

    public function __construct(IdentityUser $user, string $partitionId, ?string $timezone = null, string $loginMethod = 'password')
    {
        $this->user = $user;
        $this->partitionId = $partitionId;
        $this->timezone = $timezone;
        $this->loginMethod = $loginMethod;
    }
}
