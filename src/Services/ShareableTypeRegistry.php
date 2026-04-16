<?php

namespace NewSolari\Core\Services;

class ShareableTypeRegistry
{
    protected array $types = [];

    public function register(string $pluralKey, string $modelClass, string $morphAlias): void
    {
        $this->types[$pluralKey] = [
            'model' => $modelClass,
            'morph_alias' => $morphAlias,
        ];
    }

    public function getModelClass(string $pluralKey): ?string
    {
        return $this->types[$pluralKey]['model'] ?? null;
    }

    public function getMorphAlias(string $pluralKey): ?string
    {
        return $this->types[$pluralKey]['morph_alias'] ?? null;
    }

    public function getAllTypes(): array
    {
        return $this->types;
    }

    public function has(string $pluralKey): bool
    {
        return isset($this->types[$pluralKey]);
    }

    public function getRegisteredKeys(): array
    {
        return array_keys($this->types);
    }
}
