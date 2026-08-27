<?php
/**
 * End-to-end verification of Passer against the fixture WordPress database.
 *
 * Connect → analyse → plan → provision → run → assert. Project config is flushed explicitly at
 * the end, because a bare script has no request boundary to flush it for us and the next
 * project-config/apply would otherwise delete everything this created.
 */

define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
require_once CRAFT_VENDOR_PATH . '/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
}

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\passer\Plugin;
use justinholtweb\passer\progress\NullProgress;

$checks = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $checks, $failures;

    $checks++;

    if ($ok) {
        echo "  ok    $label\n";
    } else {
        $failures[] = $label . ($detail !== '' ? " — $detail" : '');
        echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $name): void
{
    echo "\n$name\n" . str_repeat('-', strlen($name)) . "\n";
}

$plugin = Plugin::getInstance();

// -------------------------------------------------------------------------------------------
section('Resetting');

// Every verification run must be a genuine first run, or a bug fixed since the last one is
// invisible: the ID map would skip the records that would have exercised it.
$reset = 0;

foreach (\justinholtweb\passer\records\MapRecord::find()->all() as $row) {
    $type = (string)$row->destType;

    if ($row->destId !== null && class_exists($type) && is_subclass_of($type, craft\base\ElementInterface::class)) {
        $element = $type::find()->id((int)$row->destId)->status(null)->siteId('*')->one();

        if ($element !== null) {
            Craft::$app->getElements()->deleteElement($element, true);
            $reset++;
        }
    }

    $row->delete();
}

echo "  reset  removed $reset previously imported elements\n";

$redirectFile = Craft::$app->getPath()->getConfigPath() . '/redirects.php';
if (is_file($redirectFile)) {
    @unlink($redirectFile);
}

// -------------------------------------------------------------------------------------------
section('Connecting');

$config = [
    'host' => 'db',
    'port' => 3306,
    'database' => 'wordpress_fixture',
    'username' => 'wpreader',
    'password' => 'wpreader',
];

$source = $plugin->sources->create('database', $config);

try {
    $summary = $source->testConnection();
    check('connects to the WordPress database', true);
    echo "        $summary\n";
} catch (\Throwable $e) {
    check('connects to the WordPress database', false, $e->getMessage());
    echo "\nCannot continue.\n";
    exit(1);
}

check('detects the table prefix', $source->tablePrefix() === 'wp_', $source->tablePrefix());
check('reads the site URL', $source->siteUrl() === 'https://old.example.com', (string)$source->siteUrl());

// The read-only guard must actually refuse a writable account.
$writable = $plugin->sources->create('database', ['host' => 'db', 'database' => 'wordpress_fixture', 'username' => 'root', 'password' => 'root']);

try {
    $writable->connect();
    check('refuses credentials that can write', false, 'a writable account was accepted');
} catch (\justinholtweb\passer\sources\SourceException $e) {
    check('refuses credentials that can write', str_contains($e->getMessage(), 'can write'));
}

// -------------------------------------------------------------------------------------------
section('Reading');

$postTypes = $source->postTypes();
check('finds every post type', ($postTypes['post'] ?? 0) === 3 && ($postTypes['page'] ?? 0) === 3, json_encode($postTypes));
check('finds product variations', ($postTypes['product_variation'] ?? 0) === 2);

$taxonomies = $source->taxonomies();
check('finds taxonomies', isset($taxonomies['category'], $taxonomies['post_tag'], $taxonomies['nav_menu']));

$post = $source->post(10);
check('reads a post', $post !== null && $post->title === 'Migrating without losing things');
check('reads its featured image', $post?->featuredImageId === 41);
check('reads a single meta value', $post?->metaValue('reading_time') === '7');
check('keeps repeated meta rows separate', $post?->metaList('related_link') === ['https://example.org/a', 'https://example.org/b'], json_encode($post?->metaList('related_link')));
check('reads its terms', isset($post->terms['category'], $post->terms['post_tag']));

// The broken-serialization repair, on a real option a search-and-replace corrupted.
$mods = $source->option('theme_mods_twentytwentyfour');
check(
    'repairs a serialized option broken by a domain replacement',
    is_array($mods) && ($mods['nav_menu_locations']['primary'] ?? null) === 5,
    is_array($mods) ? json_encode($mods) : 'not an array'
);

$menus = $source->menus();
check('reads menus', count($menus) === 1 && $menus[0]->name === 'Primary');
check('reads menu items', count($menus[0]->items ?? []) === 3);
check('reads the menu theme location', ($menus[0]->locations ?? []) === ['primary'], json_encode($menus[0]->locations ?? []));

$tree = $menus[0]->tree();
check('nests menu items', count($tree) === 2 && count($tree[0]->children) === 1, 'roots=' . count($tree));
check('reads menu item classes as a list', ($tree[0]->children[0]->classes ?? []) === ['highlight', 'wide'], json_encode($tree[0]->children[0]->classes ?? []));

$widgets = $source->widgets();
check('reads widgets', count($widgets) === 4, (string)count($widgets));
check('reads a widget\'s settings', ($widgets[0]->title() ?? '') === 'About this site', $widgets[0]->title() ?? '');

// -------------------------------------------------------------------------------------------
section('Analysing');

$inventory = $plugin->analyzer->analyze($source);

check('names the site', $inventory->siteName === 'The Old Site', (string)$inventory->siteName);
check('counts users', $inventory->users === 3, (string)$inventory->users);
check('counts comments', $inventory->comments === 5, (string)$inventory->comments);
check('counts menus', $inventory->menus === 1);
check('counts widgets', $inventory->widgets === 4);
check('counts products', $inventory->products === 1);
check('counts orders', $inventory->orders === 1);
check('counts redirects', $inventory->redirects === 3, (string)$inventory->redirects);
check('hides revisions and menu items from the content list', !isset($inventory->contentPostTypes()['nav_menu_item']));

$detected = array_map(static fn($p) => $p->slug, $inventory->plugins);
check('detects WooCommerce', in_array('woocommerce', $detected, true), implode(',', $detected));
check('detects Yoast', in_array('wordpress-seo', $detected, true));
check('detects Redirection', in_array('redirection', $detected, true));
check('detects ACF', in_array('advanced-custom-fields', $detected, true));

// -------------------------------------------------------------------------------------------
section('Planning');

$plan = $plugin->planner->propose($inventory, $source->capabilities());
$plan->sourceType = 'database';
$plan->sourceConfig = $config;
$plan->name = 'Fixture migration';

check('proposes a mapping for posts', isset($plan->postTypes['post']));
check('proposes a channel for posts', ($plan->postTypes['post']->sectionType ?? '') === 'channel');
check('proposes a structure for pages', ($plan->postTypes['page']->sectionType ?? '') === 'structure');
check('leaves WooCommerce post types out of the content mapping', !isset($plan->postTypes['product'], $plan->postTypes['shop_order']));
check('maps categories to a category group', ($plan->taxonomies['category']->destination ?? '') === 'categories');
check('maps tags to a tag group', ($plan->taxonomies['post_tag']->destination ?? '') === 'tags');
check('leaves nav_menu out of the taxonomy mapping', !isset($plan->taxonomies['nav_menu']));

$phases = $plan->phases();
check('schedules users before content', array_search('users', $phases, true) < array_search('content', $phases, true));
check('schedules media before content', array_search('media', $phases, true) < array_search('content', $phases, true));
check('schedules menus after content', array_search('content', $phases, true) < array_search('menus', $phases, true));
check('schedules redirects last', $phases[count($phases) - 1] === 'redirects', implode(',', $phases));

$record = $plugin->plans->save($plan, $inventory);
check('saves the plan', $record->id !== null);

// Credentials must never be readable in the stored row.
$stored = \justinholtweb\passer\records\PlanRecord::findOne($record->id);
$storedConfig = json_decode((string)$stored->sourceConfig, true) ?: [];
check(
    'encrypts the stored password',
    isset($storedConfig['password']) && str_starts_with($storedConfig['password'], 'enc:'),
    substr((string)($storedConfig['password'] ?? 'missing'), 0, 12)
);
check('leaves non-secret config readable', ($storedConfig['username'] ?? '') === 'wpreader');

$reloaded = $plugin->plans->find($record->id);
check('decrypts it on load', ($reloaded->sourceConfig['password'] ?? '') === 'wpreader');

// -------------------------------------------------------------------------------------------
section('Provisioning');

// Media needs a volume; the fixture Craft may or may not have one.
$volumes = Craft::$app->getVolumes()->getAllVolumes();
$plan->volume = $volumes[0]->handle ?? '';
$plan->importMedia = false; // The fixture's uploads host does not exist, so no bytes to fetch.

$dry = $plugin->provisioner->dryRun($plan);
check('dry run reports without creating', $dry->applied === false && $dry->isOk(), implode('; ', $dry->errors));
// On a first run the dry run plans sections; on a later one they already exist. Either is a
// correct report — an empty one is not.
check(
    'dry run accounts for the sections',
    $dry->countByType('section') > 0 || count($dry->existing) > 0,
    'nothing planned and nothing existing'
);

$applied = $plugin->provisioner->apply($plan);
check('provisioning succeeds', $applied->isOk(), implode('; ', $applied->errors));

$postSection = Craft::$app->getEntries()->getSectionByHandle($plan->postTypes['post']->section);
check('creates the posts section', $postSection !== null);
check('creates the pages section as a structure', (Craft::$app->getEntries()->getSectionByHandle($plan->postTypes['page']->section)?->type) === 'structure');
check('creates the category group', Craft::$app->getCategories()->getGroupByHandle($plan->taxonomies['category']->handle) !== null);
check('creates the tag group', Craft::$app->getTags()->getTagGroupByHandle($plan->taxonomies['post_tag']->handle) !== null);

// Idempotence: a second apply must create nothing.
$again = $plugin->provisioner->apply($plan);
check('provisioning twice creates nothing the second time', $again->created === [], (string)count($again->created));

$plugin->plans->save($plan, $inventory);

// -------------------------------------------------------------------------------------------
section('Running');

$run = $plugin->runner->createRun($plan, false, $record->id);
$report = $plugin->runner->run($run, $plan, new NullProgress());

echo "\n" . $report->toText() . "\n";

check('the run completed', $run->status === 'completed', (string)$run->status);

// -------------------------------------------------------------------------------------------
section('Checking what arrived');

$map = $plugin->map;
$map->setSourceHash($source->sourceHash());

// Users
$authorId = $map->lookup('user', 2);
check('imported the author', $authorId !== null);
$author = $authorId !== null ? craft\elements\User::find()->id($authorId)->status(null)->one() : null;
check('kept the username', $author?->username === 'jwriter', (string)$author?->username);
check('kept the email', $author?->email === 'jo@old.example.com');
check('skipped the user who never authored anything', $map->lookup('user', 3) === null);

// Terms
$categoryId = $map->lookup('term', 1);
check('imported a category', $categoryId !== null);
$deepDives = $map->lookup('term', 3);
$deepDivesElement = $deepDives !== null ? craft\elements\Category::find()->id($deepDives)->status(null)->one() : null;
check('nested a child category under its parent', $deepDivesElement?->getParentId() === $map->lookup('term', 2), (string)$deepDivesElement?->getParentId());

// Posts
$postId = $map->lookup('post', 10);
check('imported a post', $postId !== null);
$entry = $postId !== null ? craft\elements\Entry::find()->id($postId)->status(null)->one() : null;
check('kept the title', $entry?->title === 'Migrating without losing things');
check('kept the slug', $entry?->slug === 'migrating-without-losing-things', (string)$entry?->slug);
check('attributed it to the right author', in_array($authorId, $entry?->getAuthorIds() ?? [], true));
check('kept the post date', $entry?->postDate?->format('Y-m-d') === '2024-03-01', (string)$entry?->postDate?->format('Y-m-d'));

$body = (string)$entry?->getFieldValue('body');
check('rendered Gutenberg paragraphs', str_contains($body, 'Migrating a site is mostly about not losing things'));
check('rendered a heading', str_contains($body, '<h2>What usually breaks</h2>'));
check('rendered a list', str_contains($body, '<li>Links to the old domain</li>'));
check('rendered a quote', str_contains($body, 'Nothing survives contact with a real database'));
check('rendered an embed as a link with its provider', str_contains($body, 'data-embed-provider="youtube"'), substr($body, 0, 0));
check('kept an unknown block\'s markup rather than dropping it', str_contains($body, 'Third-party block content'));

// Drafts
check('imported the draft as disabled', (function () use ($map) {
    $id = $map->lookup('post', 12);
    $draft = $id !== null ? craft\elements\Entry::find()->id($id)->status(null)->one() : null;

    return $draft !== null && !$draft->enabled;
})());

// Classic content
$helloId = $map->lookup('post', 11);
$hello = $helloId !== null ? craft\elements\Entry::find()->id($helloId)->status(null)->one() : null;
$helloBody = (string)$hello?->getFieldValue('body');
check('wrapped classic content in paragraphs', substr_count($helloBody, '<p>') >= 2, substr($helloBody, 0, 120));
check('expanded a caption shortcode', str_contains($helloBody, '<figcaption'), substr($helloBody, 0, 200));
check('left an unknown shortcode in place', str_contains($helloBody, '[unknown_shortcode'), 'shortcode was silently deleted');
check('rewrote a link to the old domain', !str_contains($helloBody, 'https://old.example.com/hello-world/'), 'old URL survived');

// Page hierarchy — stored child-first in the fixture
$aboutId = $map->lookup('post', 20);
$teamId = $map->lookup('post', 21);
$team = $teamId !== null ? craft\elements\Entry::find()->id($teamId)->status(null)->one() : null;
check('nested a page under its parent even though the child was stored first', $team?->getParentId() === $aboutId, (string)$team?->getParentId());

// Taxonomy relations
$categoryFieldHandle = $plan->postTypes['post']->taxonomyFields['category'] ?? null;
check('mapped the category taxonomy to a field', $categoryFieldHandle !== null, 'no taxonomy field proposed');
$related = $categoryFieldHandle !== null ? $entry?->getFieldValue($categoryFieldHandle) : null;
check('related the post to its category', $related !== null && in_array($categoryId, $related->ids(), true));

// SEO
check('expanded a Yoast template variable rather than importing it raw', (function () use ($entry) {
    $title = (string)$entry?->getFieldValue('metaTitle');

    return $title !== '' && !str_contains($title, '%%') && str_contains($title, 'Migrating without losing things');
})(), (string)$entry?->getFieldValue('metaTitle'));
check('imported the meta description', str_contains((string)$entry?->getFieldValue('metaDescription'), 'without losing the parts that matter'));

// Menus
check('imported the menu', $map->lookup('menu', 5) !== null);
check('imported every menu item', count(array_filter([
    $map->lookup('menuItem', 51),
    $map->lookup('menuItem', 52),
    $map->lookup('menuItem', 53),
])) === 3);

// Redirects. Which destination is used depends on what this Craft install has, which is the
// whole point of the destination registry — so both the preferred and the fallback are checked.
$redirectDestination = $plan->domain('redirects')->destination;
check('chose the highest-priority installed redirect destination', $redirectDestination === 'retour', $redirectDestination);

if ($redirectDestination === 'retour') {
    $retourRows = (new craft\db\Query())->from('{{%retour_static_redirects}}')->all();
    $sources = array_column($retourRows, 'redirectSrcUrl');

    check('imported redirects into Retour', count($retourRows) >= 3, (string)count($retourRows));
    check('kept the exact redirect', in_array('/old-hello-world', $sources, true), implode(',', $sources));
    check(
        'kept the regular-expression redirect as a regex match',
        (function () use ($retourRows) {
            foreach ($retourRows as $row) {
                if ($row['redirectSrcUrl'] === '/blog/(.*)') {
                    return $row['redirectMatchType'] === 'regexmatch';
                }
            }

            return false;
        })(),
        'regex redirect missing or not marked as a regex'
    );
}

// Now the dependency-free fallback, which must work on a site with nothing installed at all.
$plan->domain('redirects')->destination = 'config-file';
$fallbackRun = $plugin->runner->createRun($plan, false, $record->id);
$plugin->runner->run($fallbackRun, $plan, new NullProgress(), ['redirects']);

// PHP caches stat results, and this path was unlinked earlier in the same process.
clearstatcache(true, $redirectFile);
check('the plugin-free fallback wrote a redirects config file', is_file($redirectFile));

if (is_file($redirectFile)) {
    $redirects = require $redirectFile;
    check('wrote the exact redirect as a URL rule', isset($redirects['old-hello-world']), implode(',', array_keys($redirects)));
    check('left the regex redirect out of the URL rules', !isset($redirects['blog/(.*)']));
    check('marked a 301 as permanent', ($redirects['old-hello-world']['permanent'] ?? null) === true);
}

$plan->domain('redirects')->destination = $redirectDestination;

// -------------------------------------------------------------------------------------------
section('Re-running');

$secondRun = $plugin->runner->createRun($plan, false, $record->id);
$secondReport = $plugin->runner->run($secondRun, $plan, new NullProgress());

$idempotent = 0;
foreach (['users', 'media', 'taxonomies', 'content', 'comments', 'commerce', 'menus'] as $phase) {
    $idempotent += $secondReport->phases[$phase]['created'] ?? 0;
}
check('a second run creates no new content', $idempotent === 0, (string)$idempotent . ' — ' . json_encode($secondReport->phases));
$postCount = (int)craft\elements\Entry::find()->section($plan->postTypes['post']->section)->status(null)->count();
check('a second run does not duplicate the posts', $postCount === 3, (string)$postCount);
check('a second run skips unchanged records', $secondReport->total('skipped') > 0, (string)$secondReport->total('skipped'));

// -------------------------------------------------------------------------------------------
$source->close();

// Flush project config, or the next apply deletes everything provisioned above.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

echo "\n" . str_repeat('=', 70) . "\n";
echo sprintf("%d checks, %d failures\n", $checks, count($failures));

foreach ($failures as $failure) {
    echo "  FAILED: $failure\n";
}

exit($failures === [] ? 0 : 1);
