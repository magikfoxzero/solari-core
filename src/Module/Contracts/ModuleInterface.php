<?php

namespace NewSolari\Core\Module\Contracts;

interface ModuleInterface
{
    public function getId(): string;
    public function getName(): string;
    public function getVersion(): string;
    public function getType(): string;

    public function install(): void;
    public function uninstall(): void;
    public function enable(): void;
    public function disable(): void;

    public function getDependencies(): array;
    public function getOptionalDependencies(): array;
    public function getServiceContract(): ?string;
    public function getFrontendManifest(): ?array;
}
