<?php

namespace justinholtweb\passer\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\passer\sources\DatabaseSource;
use justinholtweb\passer\sources\RestSource;
use justinholtweb\passer\sources\SourceException;
use justinholtweb\passer\sources\SourceInterface;
use justinholtweb\passer\sources\WpCliSource;
use justinholtweb\passer\sources\WxrSource;

/**
 * Builds source objects from stored configuration, and keeps credentials out of the database.
 */
class Sources extends Component
{
    /**
     * Which keys, per source type, hold a secret. These are encrypted at rest with Craft's
     * security key and never rendered back into the wizard.
     *
     * @var array<string, string[]>
     */
    private const SECRET_KEYS = [
        'database' => ['password'],
        'rest' => ['applicationPassword'],
        'wpcli' => [],
        'wxr' => [],
    ];

    /**
     * @return array<string, class-string<SourceInterface>>
     */
    public function types(): array
    {
        return [
            DatabaseSource::type() => DatabaseSource::class,
            WxrSource::type() => WxrSource::class,
            RestSource::type() => RestSource::class,
            WpCliSource::type() => WpCliSource::class,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(string $type, array $config = []): SourceInterface
    {
        $class = $this->types()[$type] ?? null;

        if ($class === null) {
            throw new SourceException("Unknown source type '$type'.");
        }

        $config = $this->decryptSecrets($type, $config);

        /** @var SourceInterface $source */
        $source = Craft::createObject(['class' => $class] + $config);

        return $source;
    }

    /**
     * Encrypt the secret keys in a source config so it can be stored.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function encryptSecrets(string $type, array $config): array
    {
        $security = Craft::$app->getSecurity();

        foreach (self::SECRET_KEYS[$type] ?? [] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                continue;
            }

            $config[$key] = 'enc:' . base64_encode($security->encryptByKey($config[$key]));
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function decryptSecrets(string $type, array $config): array
    {
        $security = Craft::$app->getSecurity();

        foreach (self::SECRET_KEYS[$type] ?? [] as $key) {
            $value = $config[$key] ?? null;

            if (!is_string($value) || !str_starts_with($value, 'enc:')) {
                continue;
            }

            $raw = base64_decode(substr($value, 4), true);

            try {
                $config[$key] = $raw === false ? '' : $security->decryptByKey($raw);
            } catch (\Throwable) {
                // A rotated security key makes stored credentials unreadable. Blank it rather
                // than fail, so the user is asked to re-enter it instead of hitting a stack trace.
                $config[$key] = '';
            }
        }

        return $config;
    }

    /**
     * A copy of a source config safe to render back into a form: secrets become a placeholder.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function redact(string $type, array $config): array
    {
        foreach (self::SECRET_KEYS[$type] ?? [] as $key) {
            if (!empty($config[$key])) {
                $config[$key] = '••••••••';
            }
        }

        return $config;
    }

    /**
     * Merge a submitted config over a stored one, leaving untouched secrets alone.
     *
     * Without this, editing a saved connection to change its host would silently blank its
     * password, because the form only ever showed a placeholder.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    public function mergeConfig(string $type, array $stored, array $submitted): array
    {
        foreach (self::SECRET_KEYS[$type] ?? [] as $key) {
            $value = $submitted[$key] ?? '';

            if ($value === '' || $value === '••••••••') {
                unset($submitted[$key]);
            }
        }

        return array_merge($stored, $submitted);
    }

    /**
     * @param array<string, mixed>|string|null $value
     * @return array<string, mixed>
     */
    public function decodeConfig(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = Json::decodeIfJson($value);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
