<?php

namespace NewSolari\Core\Tests\Unit\Services;

use NewSolari\Core\Services\ShareableTypeRegistry;
use PHPUnit\Framework\TestCase;

class ShareableTypeRegistryTest extends TestCase
{
    private ShareableTypeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ShareableTypeRegistry();
    }

    public function test_register_and_get_model_class(): void
    {
        $this->registry->register('notes', 'App\\Models\\Note', 'note');

        $this->assertSame('App\\Models\\Note', $this->registry->getModelClass('notes'));
    }

    public function test_register_and_get_morph_alias(): void
    {
        $this->registry->register('notes', 'App\\Models\\Note', 'note');

        $this->assertSame('note', $this->registry->getMorphAlias('notes'));
    }

    public function test_get_model_class_returns_null_for_unregistered(): void
    {
        $this->assertNull($this->registry->getModelClass('unknown'));
    }

    public function test_get_morph_alias_returns_null_for_unregistered(): void
    {
        $this->assertNull($this->registry->getMorphAlias('unknown'));
    }

    public function test_get_all_types(): void
    {
        $this->registry->register('notes', 'App\\Models\\Note', 'note');
        $this->registry->register('files', 'App\\Models\\File', 'file');

        $all = $this->registry->getAllTypes();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('notes', $all);
        $this->assertArrayHasKey('files', $all);
        $this->assertSame('App\\Models\\Note', $all['notes']['model']);
        $this->assertSame('file', $all['files']['morph_alias']);
    }

    public function test_get_registered_keys(): void
    {
        $this->registry->register('notes', 'App\\Models\\Note', 'note');
        $this->registry->register('tasks', 'App\\Models\\Task', 'task');

        $keys = $this->registry->getRegisteredKeys();

        $this->assertSame(['notes', 'tasks'], $keys);
    }

    public function test_register_overwrites_existing(): void
    {
        $this->registry->register('notes', 'App\\Models\\OldNote', 'old_note');
        $this->registry->register('notes', 'App\\Models\\NewNote', 'note');

        $this->assertSame('App\\Models\\NewNote', $this->registry->getModelClass('notes'));
        $this->assertSame('note', $this->registry->getMorphAlias('notes'));
    }

    public function test_has_returns_true_for_registered(): void
    {
        $this->registry->register('notes', 'App\\Models\\Note', 'note');

        $this->assertTrue($this->registry->has('notes'));
    }

    public function test_has_returns_false_for_unregistered(): void
    {
        $this->assertFalse($this->registry->has('unknown'));
    }
}
