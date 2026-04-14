<?php

namespace NewSolari\Core\Events;

// UserBlock model is provided by the identity package
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserBlocked
{
    use Dispatchable, SerializesModels;

    public $block;

    public function __construct($block)
    {
        $this->block = $block;
    }
}
