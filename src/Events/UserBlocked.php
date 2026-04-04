<?php

namespace NewSolari\Core\Events;

use NewSolari\Core\Identity\Models\UserBlock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserBlocked
{
    use Dispatchable, SerializesModels;

    public UserBlock $block;

    public function __construct(UserBlock $block)
    {
        $this->block = $block;
    }
}
