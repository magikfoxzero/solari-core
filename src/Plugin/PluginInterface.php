<?php

namespace NewSolari\Core\Plugin;

interface PluginInterface
{
    /**
     * Get the plugin ID
     */
    public function getId(): string;

    /**
     * Get the plugin name
     */
    public function getName(): string;

    /**
     * Get the plugin type (mini-app, meta-app, standalone)
     */
    public function getType(): string;

    /**
     * Get the plugin version
     */
    public function getVersion(): string;

    /**
     * Get plugin dependencies
     */
    public function getDependencies(): array;

    /**
     * Get required permissions
     */
    public function getPermissions(): array;

    /**
     * Get plugin routes
     */
    public function getRoutes(): array;

    /**
     * Initialize the plugin
     */
    public function initialize(): bool;

    /**
     * Check if plugin is enabled
     */
    public function isEnabled(): bool;

    /**
     * Get plugin configuration
     */
    public function getConfig(): array;
}
