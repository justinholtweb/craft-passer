<?php

namespace justinholtweb\passer\models;

use craft\base\Model;

/**
 * What provisioning did, or would do.
 *
 * A dry run returns the same object with `applied` false, which is what the wizard shows on the
 * confirmation screen — a migration that silently invents twelve sections and forty fields is
 * one nobody can review.
 */
class ProvisionResult extends Model
{
    public bool $applied = false;

    /** @var array<int, array{type: string, handle: string, name: string, detail: string}> */
    public array $created = [];

    /** @var array<int, array{type: string, handle: string, name: string, detail: string}> */
    public array $existing = [];

    /** @var string[] */
    public array $errors = [];

    public function add(string $type, string $handle, string $name, string $detail = ''): void
    {
        $this->created[] = compact('type', 'handle', 'name', 'detail');
    }

    public function addExisting(string $type, string $handle, string $name, string $detail = ''): void
    {
        $this->existing[] = compact('type', 'handle', 'name', 'detail');
    }

    public function countByType(string $type): int
    {
        return count(array_filter($this->created, static fn(array $r) => $r['type'] === $type));
    }

    public function isOk(): bool
    {
        return $this->errors === [];
    }
}
