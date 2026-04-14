<?php

namespace NewSolari\Core\Events;

use NewSolari\Core\Contracts\IdentityUserContract;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserLoggedIn
{
    use Dispatchable, SerializesModels;

    public IdentityUserContract $user;
    public string $partitionId;
    public ?string $timezone;
    public string $loginMethod;

    public function __construct(IdentityUserContract $user, string $partitionId, ?string $timezone = null, string $loginMethod = 'password')
    {
        $this->user = $user;
        $this->partitionId = $partitionId;
        $this->timezone = $timezone;
        $this->loginMethod = $loginMethod;
    }
}
