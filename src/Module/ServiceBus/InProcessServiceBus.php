<?php

namespace NewSolari\Core\Module\ServiceBus;

use NewSolari\Core\Module\Contracts\ServiceBusInterface;
use NewSolari\Core\Module\ModuleRegistry;

class InProcessServiceBus implements ServiceBusInterface
{
    public function call(string $address, array $params = []): mixed
    {
        [$moduleId, $service, $method] = $this->parseAddress($address);

        if (!$this->isAvailable($moduleId)) {
            throw new ServiceBusException("Module '{$moduleId}' is not available");
        }

        $binding = "{$moduleId}.{$service}";
        $instance = app($binding);
        return $instance->$method(...$params);
    }

    public function emit(string $event, array $payload = []): void
    {
        event($event, $payload);
    }

    public function queue(string $address, array $params = []): void
    {
        [$moduleId, $service, $method] = $this->parseAddress($address);
        dispatch(new ServiceBusJob($moduleId, $service, $method, $params));
    }

    public function isAvailable(string $moduleId): bool
    {
        return app(ModuleRegistry::class)->isEnabled($moduleId);
    }

    protected function parseAddress(string $address): array
    {
        $parts = explode('.', $address, 3);
        if (count($parts) !== 3) {
            throw new ServiceBusException(
                "Invalid service address '{$address}'. Expected format: module.service.method"
            );
        }
        return $parts;
    }
}
