<?php

namespace justinholtweb\passer\bridge;

use Craft;
use craft\base\Component;
use justinholtweb\passer\acf\AcfAdapterInterface;
use justinholtweb\passer\content\Block;
use justinholtweb\passer\content\BlockTransformerInterface;
use justinholtweb\passer\content\ContentTransformer;
use justinholtweb\passer\importers\RunContext;

/**
 * Reuses `craftcms/wp-import`'s ACF adapters and Gutenberg block transformers when that plugin
 * is installed.
 *
 * The two plugins solve the same sub-problem in the same shape — given a raw value and a field
 * definition, produce something Craft can store — so there is no reason to make a site that has
 * both maintain two sets of adapters, or to make a developer who has written a custom wp-import
 * adapter write it again.
 *
 * The coupling is deliberately loose. wp-import is distributed as `dev-main` with no tagged
 * releases, so every touchpoint is guarded: classes are checked for existence, calls are wrapped,
 * and a bridge that fails to load leaves Passer's own adapters in place rather than failing the
 * migration. Passer's own always take precedence; the bridge only fills gaps.
 */
class WpImportBridge extends Component
{
    private const PLUGIN_HANDLE = 'wp-import';

    /** @var array<string, AcfAdapterInterface>|null */
    private ?array $acfCache = null;

    /** @var array<string, BlockTransformerInterface>|null */
    private ?array $blockCache = null;

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled(self::PLUGIN_HANDLE)
            && class_exists('craft\wpimport\BaseAcfAdapter');
    }

    /**
     * A short description of what the bridge found, for the run report and the wizard.
     */
    public function status(): string
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled(self::PLUGIN_HANDLE)) {
            return 'wp-import is not installed; Passer is using its own adapters throughout.';
        }

        if (!$this->isAvailable()) {
            return 'wp-import is installed but its adapter classes could not be found, so Passer '
                . 'is using its own adapters throughout.';
        }

        return sprintf(
            'wp-import is installed; Passer is reusing %d of its ACF adapters and %d of its block '
            . 'transformers for types it does not handle itself.',
            count($this->acfAdapters()),
            count($this->blockTransformers())
        );
    }

    /**
     * wp-import ACF adapters, wrapped so they satisfy Passer's interface.
     *
     * @return array<string, AcfAdapterInterface> ACF type => adapter.
     */
    public function acfAdapters(): array
    {
        if ($this->acfCache !== null) {
            return $this->acfCache;
        }

        $this->acfCache = [];

        if (!$this->isAvailable()) {
            return $this->acfCache;
        }

        foreach ($this->discover('acfadapters', 'craft\\wpimport\\acfadapters\\') as $class) {
            try {
                $instance = new $class();
                $type = $this->acfTypeFor($instance, $class);

                if ($type !== null) {
                    $this->acfCache[$type] = new WrappedAcfAdapter($instance, $type);
                }
            } catch (\Throwable) {
                // A wp-import adapter whose constructor needs something we cannot supply is
                // skipped, not fatal.
                continue;
            }
        }

        return $this->acfCache;
    }

    /**
     * @return array<string, BlockTransformerInterface> Block name => transformer.
     */
    public function blockTransformers(): array
    {
        if ($this->blockCache !== null) {
            return $this->blockCache;
        }

        $this->blockCache = [];

        if (!$this->isAvailable()) {
            return $this->blockCache;
        }

        foreach ($this->discover('blocktransformers', 'craft\\wpimport\\blocktransformers\\') as $class) {
            try {
                $instance = new $class();
                $name = $this->blockNameFor($instance, $class);

                if ($name !== null) {
                    $this->blockCache[$name] = new WrappedBlockTransformer($instance, $name);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->blockCache;
    }

    /**
     * Find wp-import's component classes.
     *
     * wp-import loads its own from `src/<kind>/` and lets a project add more in
     * `config/wp-import/<kind>/`. Both are read, so a developer's custom adapters come across
     * too — which is most of the value of the bridge for a site that has already started a
     * migration with wp-import.
     *
     * @return class-string[]
     */
    private function discover(string $kind, string $namespace): array
    {
        $classes = [];

        $plugin = Craft::$app->getPlugins()->getPlugin(self::PLUGIN_HANDLE);

        if ($plugin === null) {
            return [];
        }

        $directories = [
            $plugin->getBasePath() . DIRECTORY_SEPARATOR . $kind => $namespace,
            Craft::$app->getPath()->getConfigPath() . '/wp-import/' . $kind => null,
        ];

        foreach ($directories as $directory => $classNamespace) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $shortName = basename($file, '.php');

                if ($classNamespace !== null) {
                    $class = $classNamespace . $shortName;
                } else {
                    // Project-level components are not namespaced by wp-import's convention, so
                    // the file has to be read to find out what it declares.
                    $class = $this->classInFile($file);
                }

                if ($class !== null && class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    private function classInFile(string $file): ?string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        $namespace = '';

        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $m)) {
            $namespace = trim($m[1]) . '\\';
        }

        if (preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $contents, $m)) {
            // Including the file is what makes an unautoloadable project class available; the
            // guard prevents a double declaration if it has already been loaded.
            $class = $namespace . $m[1];

            if (!class_exists($class, false)) {
                require_once $file;
            }

            return $class;
        }

        return null;
    }

    /**
     * wp-import identifies an adapter's ACF type with a public `$type` property on some versions
     * and a `type()` method on others.
     */
    private function acfTypeFor(object $instance, string $class): ?string
    {
        foreach (['type', 'acfType'] as $property) {
            if (property_exists($instance, $property)) {
                $value = $instance->$property;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        if (method_exists($instance, 'type')) {
            try {
                $value = $instance->type();

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // Fall through to the class-name heuristic.
            }
        }

        // Last resort: wp-import names its adapter classes after the ACF type, so
        // `PostObject` is `post_object`.
        $short = substr(strrchr($class, '\\') ?: $class, 1);
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short);

        return $snake !== '' ? $snake : null;
    }

    private function blockNameFor(object $instance, string $class): ?string
    {
        foreach (['type', 'blockName', 'name'] as $property) {
            if (property_exists($instance, $property)) {
                $value = $instance->$property;

                if (is_string($value) && $value !== '') {
                    return str_contains($value, '/') ? $value : 'core/' . $value;
                }
            }
        }

        $short = substr(strrchr($class, '\\') ?: $class, 1);
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $short) ?? $short);

        return $kebab !== '' ? 'core/' . $kebab : null;
    }
}
