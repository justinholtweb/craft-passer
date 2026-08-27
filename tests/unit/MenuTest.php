<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\models\wp\WpMenu;
use justinholtweb\passer\models\wp\WpMenuItem;
use PHPUnit\Framework\TestCase;

/**
 * Rebuilding a menu's hierarchy from WordPress's flat list of items.
 */
class MenuTest extends TestCase
{
    private function item(int $id, int $parent = 0, int $order = 0): WpMenuItem
    {
        return new WpMenuItem(['id' => $id, 'parentItemId' => $parent, 'order' => $order, 'title' => "Item $id"]);
    }

    public function testBuildsATreeFromAFlatList(): void
    {
        $menu = new WpMenu();
        $menu->items = [
            $this->item(1),
            $this->item(2, 1),
            $this->item(3, 1),
            $this->item(4),
            $this->item(5, 2),
        ];

        $tree = $menu->tree();

        $this->assertCount(2, $tree);
        $this->assertSame(1, $tree[0]->id);
        $this->assertCount(2, $tree[0]->children);
        $this->assertCount(1, $tree[0]->children[0]->children);
        $this->assertSame(5, $tree[0]->children[0]->children[0]->id);
        $this->assertSame(4, $tree[1]->id);
    }

    /**
     * A child stored before its parent is the normal case in `wp_posts`, not the exception.
     */
    public function testHandlesChildrenStoredBeforeTheirParent(): void
    {
        $menu = new WpMenu();
        $menu->items = [
            $this->item(2, 1),
            $this->item(1),
        ];

        $tree = $menu->tree();

        $this->assertCount(1, $tree);
        $this->assertSame(1, $tree[0]->id);
        $this->assertCount(1, $tree[0]->children);
    }

    /**
     * An item whose parent was deleted must still appear, at the root, rather than vanishing.
     */
    public function testPromotesOrphansToTheRoot(): void
    {
        $menu = new WpMenu();
        $menu->items = [$this->item(2, 99)];

        $tree = $menu->tree();

        $this->assertCount(1, $tree);
        $this->assertSame(2, $tree[0]->id);
    }

    public function testKnowsWhichItemsLinkToContent(): void
    {
        $post = new WpMenuItem(['objectType' => 'post_type', 'object' => 'page', 'objectId' => 42]);
        $term = new WpMenuItem(['objectType' => 'taxonomy', 'object' => 'category', 'objectId' => 7]);
        $custom = new WpMenuItem(['objectType' => 'custom', 'url' => 'https://example.com']);
        $archive = new WpMenuItem(['objectType' => 'post_type_archive', 'object' => 'product']);

        $this->assertTrue($post->isElementLink());
        $this->assertSame('post', $post->mapKey());

        $this->assertTrue($term->isElementLink());
        $this->assertSame('term', $term->mapKey());

        $this->assertFalse($custom->isElementLink());
        $this->assertNull($custom->mapKey());

        $this->assertFalse($archive->isElementLink());
    }

    /**
     * A post_type item with no object ID points at nothing, so it must not be treated as a
     * relation — that would resolve to whatever element happens to have ID 0.
     */
    public function testRejectsAnElementLinkWithNoTarget(): void
    {
        $item = new WpMenuItem(['objectType' => 'post_type', 'object' => 'page', 'objectId' => 0]);

        $this->assertFalse($item->isElementLink());
    }

    public function testHandlesAnEmptyMenu(): void
    {
        $this->assertSame([], (new WpMenu())->tree());
    }
}
