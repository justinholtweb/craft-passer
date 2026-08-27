<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A row from `wp_users`, with `wp_usermeta` attached.
 */
class WpUser extends Model
{
    public int $id = 0;
    public string $login = '';
    public string $email = '';
    public string $niceName = '';
    public string $displayName = '';
    public ?string $url = null;
    public ?string $registered = null;

    /**
     * @var string|null The `user_pass` hash. WordPress uses phpass ($P$) or, since 6.8,
     * bcrypt ($wp$2y$). Craft cannot verify either, so this is carried only so the run report
     * can say honestly that passwords did not come across.
     */
    public ?string $passwordHash = null;

    /** @var string[] Role slugs, read from `wp_capabilities`. */
    public array $roles = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public function firstName(): string
    {
        return (string)($this->meta['first_name'] ?? '');
    }

    public function lastName(): string
    {
        return (string)($this->meta['last_name'] ?? '');
    }

    public function bio(): string
    {
        return (string)($this->meta['description'] ?? '');
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
