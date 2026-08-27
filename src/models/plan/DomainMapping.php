<?php

namespace justinholtweb\passer\models\plan;

use craft\base\Model;

/**
 * How one optional domain — commerce, SEO, redirects, menus, widgets, forms, comments — is
 * migrated, and where to.
 *
 * `destination` is a handle from the DestinationRegistry, not a plugin name, because several
 * plugins can serve the same domain and the fallback is always something Passer can do alone.
 */
class DomainMapping extends Model
{
    /** @var string commerce|seo|redirects|menus|widgets|forms|comments */
    public string $domain = '';

    public bool $enabled = false;

    /** @var string A destination handle, e.g. `seomatic`, `native-fields`, `freenav`. */
    public string $destination = '';

    /** @var array<string, mixed> Destination-specific options set in the wizard. */
    public array $options = [];

    public function rules(): array
    {
        return [
            [['domain'], 'required'],
        ];
    }
}
