<?php

namespace NewSolari\Core\Module\ServiceBus;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServiceBusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $moduleId,
        public string $service,
        public string $method,
        public array $params
    ) {}

    public function handle(): void
    {
        $binding = "{$this->moduleId}.{$this->service}";
        $instance = app($binding);
        $instance->{$this->method}(...$this->params);
    }
}
