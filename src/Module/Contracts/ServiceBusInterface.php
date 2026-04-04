<?php

namespace NewSolari\Core\Module\Contracts;

/**
 * Service Bus for cross-module communication.
 *
 * Use Service Bus when:
 * - The calling module does NOT have a Composer dependency on the target
 * - The target module may be extracted to a separate service
 * - You need availability checking (isAvailable)
 *
 * Use direct interface injection (app(SomeInterface::class)) when:
 * - The calling module declares the contract package as a Composer dependency
 * - Both modules are guaranteed to be in the same process
 */
interface ServiceBusInterface
{
    public function call(string $address, array $params = []): mixed;
    public function emit(string $event, array $payload = []): void;
    public function queue(string $address, array $params = []): void;
    public function isAvailable(string $moduleId): bool;
}
