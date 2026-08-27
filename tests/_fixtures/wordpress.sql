-- A small but realistic WordPress 6.x database, enough to exercise every phase of a migration.
--
-- It deliberately contains the things that break importers: a non-standard table prefix, a
-- serialized option whose string lengths were broken by a domain search-and-replace, Gutenberg
-- and classic content side by side, a shortcode, a hierarchical page tree stored child-first,
-- a repeated meta key next to a serialized-array one, Yoast metadata with template variables,
-- a WooCommerce variable product, and a menu whose items point at posts by ID.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS wp_posts, wp_postmeta, wp_terms, wp_term_taxonomy, wp_term_relationships,
    wp_termmeta, wp_users, wp_usermeta, wp_comments, wp_commentmeta, wp_options,
    wp_woocommerce_order_items, wp_woocommerce_order_itemmeta, wp_redirection_items;

CREATE TABLE wp_posts (
    ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_author bigint(20) unsigned NOT NULL DEFAULT 0,
    post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_content longtext NOT NULL,
    post_title text NOT NULL,
    post_excerpt text NOT NULL,
    post_status varchar(20) NOT NULL DEFAULT 'publish',
    comment_status varchar(20) NOT NULL DEFAULT 'open',
    ping_status varchar(20) NOT NULL DEFAULT 'open',
    post_password varchar(255) NOT NULL DEFAULT '',
    post_name varchar(200) NOT NULL DEFAULT '',
    to_ping text NOT NULL,
    pinged text NOT NULL,
    post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_modified_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_content_filtered longtext NOT NULL,
    post_parent bigint(20) unsigned NOT NULL DEFAULT 0,
    guid varchar(255) NOT NULL DEFAULT '',
    menu_order int(11) NOT NULL DEFAULT 0,
    post_type varchar(20) NOT NULL DEFAULT 'post',
    post_mime_type varchar(100) NOT NULL DEFAULT '',
    comment_count bigint(20) NOT NULL DEFAULT 0,
    PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_postmeta (
    meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    post_id bigint(20) unsigned NOT NULL DEFAULT 0,
    meta_key varchar(255) DEFAULT NULL,
    meta_value longtext,
    PRIMARY KEY (meta_id),
    KEY post_id (post_id),
    KEY meta_key (meta_key(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_terms (
    term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    name varchar(200) NOT NULL DEFAULT '',
    slug varchar(200) NOT NULL DEFAULT '',
    term_group bigint(10) NOT NULL DEFAULT 0,
    PRIMARY KEY (term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_term_taxonomy (
    term_taxonomy_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    term_id bigint(20) unsigned NOT NULL DEFAULT 0,
    taxonomy varchar(32) NOT NULL DEFAULT '',
    description longtext NOT NULL,
    parent bigint(20) unsigned NOT NULL DEFAULT 0,
    count bigint(20) NOT NULL DEFAULT 0,
    PRIMARY KEY (term_taxonomy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_term_relationships (
    object_id bigint(20) unsigned NOT NULL DEFAULT 0,
    term_taxonomy_id bigint(20) unsigned NOT NULL DEFAULT 0,
    term_order int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (object_id, term_taxonomy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_termmeta (
    meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    term_id bigint(20) unsigned NOT NULL DEFAULT 0,
    meta_key varchar(255) DEFAULT NULL,
    meta_value longtext,
    PRIMARY KEY (meta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_users (
    ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_login varchar(60) NOT NULL DEFAULT '',
    user_pass varchar(255) NOT NULL DEFAULT '',
    user_nicename varchar(50) NOT NULL DEFAULT '',
    user_email varchar(100) NOT NULL DEFAULT '',
    user_url varchar(100) NOT NULL DEFAULT '',
    user_registered datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    user_activation_key varchar(255) NOT NULL DEFAULT '',
    user_status int(11) NOT NULL DEFAULT 0,
    display_name varchar(250) NOT NULL DEFAULT '',
    PRIMARY KEY (ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_usermeta (
    umeta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    meta_key varchar(255) DEFAULT NULL,
    meta_value longtext,
    PRIMARY KEY (umeta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_comments (
    comment_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    comment_post_ID bigint(20) unsigned NOT NULL DEFAULT 0,
    comment_author tinytext NOT NULL,
    comment_author_email varchar(100) NOT NULL DEFAULT '',
    comment_author_url varchar(200) NOT NULL DEFAULT '',
    comment_author_IP varchar(100) NOT NULL DEFAULT '',
    comment_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    comment_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
    comment_content text NOT NULL,
    comment_karma int(11) NOT NULL DEFAULT 0,
    comment_approved varchar(20) NOT NULL DEFAULT '1',
    comment_agent varchar(255) NOT NULL DEFAULT '',
    comment_type varchar(20) NOT NULL DEFAULT 'comment',
    comment_parent bigint(20) unsigned NOT NULL DEFAULT 0,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (comment_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_commentmeta (
    meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
    meta_key varchar(255) DEFAULT NULL,
    meta_value longtext,
    PRIMARY KEY (meta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_options (
    option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    option_name varchar(191) NOT NULL DEFAULT '',
    option_value longtext NOT NULL,
    autoload varchar(20) NOT NULL DEFAULT 'yes',
    PRIMARY KEY (option_id),
    UNIQUE KEY option_name (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_woocommerce_order_items (
    order_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_item_name text NOT NULL,
    order_item_type varchar(200) NOT NULL DEFAULT '',
    order_id bigint(20) unsigned NOT NULL,
    PRIMARY KEY (order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_woocommerce_order_itemmeta (
    meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    order_item_id bigint(20) unsigned NOT NULL,
    meta_key varchar(255) DEFAULT NULL,
    meta_value longtext,
    PRIMARY KEY (meta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wp_redirection_items (
    id int(11) unsigned NOT NULL AUTO_INCREMENT,
    url text NOT NULL,
    match_url varchar(2000) DEFAULT NULL,
    regex int(11) unsigned NOT NULL DEFAULT 0,
    position int(11) unsigned NOT NULL DEFAULT 0,
    last_count int(10) unsigned NOT NULL DEFAULT 0,
    status varchar(64) NOT NULL DEFAULT 'enabled',
    action_type varchar(64) NOT NULL,
    action_code int(11) unsigned NOT NULL,
    action_data text,
    group_id int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
-- Seed data -------------------------------------------------------------------------------

INSERT INTO wp_options (option_name, option_value) VALUES
  ('siteurl', 'https://old.example.com'),
  ('home', 'https://old.example.com'),
  ('blogname', 'The Old Site'),
  ('db_version', '57155'),
  ('stylesheet', 'twentytwentyfour'),
  ('template', 'twentytwentyfour'),
  ('upload_path', ''),
  ('sidebars_widgets', 'a:3:{s:19:"wp_inactive_widgets";a:0:{}s:9:"sidebar-1";a:3:{i:0;s:6:"text-2";i:1;s:10:"nav_menu-1";i:2;s:14:"recent-posts-2";}s:8:"footer-1";a:1:{i:0;s:13:"custom_html-1";}}'),
  ('widget_text', 'a:1:{i:2;a:3:{s:5:"title";s:15:"About this site";s:4:"text";s:71:"A short blurb with a <a href="https://old.example.com/about/">link</a>.";s:6:"filter";b:1;}}'),
  ('widget_custom_html', 'a:1:{i:1;a:2:{s:5:"title";s:7:"Contact";s:7:"content";s:27:"<p>Call us on 555-0100.</p>";}}'),
  ('widget_nav_menu', 'a:1:{i:1;a:2:{s:5:"title";s:6:"Browse";s:8:"nav_menu";i:5;}}'),
  ('widget_recent-posts', 'a:1:{i:2;a:2:{s:5:"title";s:6:"Latest";s:6:"number";i:5;}}'),
  ('theme_mods_twentytwentyfour', 'a:3:{s:18:"nav_menu_locations";a:1:{s:7:"primary";i:5;}s:11:"custom_logo";i:41;s:12:"header_image";s:61:"https://new-and-much-longer.example.org/wp-content/uploads/2023/05/header.jpg";}'),
  ('woocommerce_version', '9.1.2'),
  ('woocommerce_currency', 'GBP'),
  ('wpseo', 'a:1:{s:15:"ms_defaults_set";b:1;}'),
  ('acf_version', '6.2.7');

INSERT INTO wp_users (ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, display_name) VALUES
  (1, 'admin', '$P$Bsomethinghashed', 'admin', 'alex@old.example.com', '', '2019-01-04 09:12:00', 'Alex Admin'),
  (2, 'jwriter', '$P$Bsomethinghashed', 'jwriter', 'jo@old.example.com', '', '2020-06-11 14:02:00', 'Jo Writer'),
  (3, 'lurker', '$P$Bsomethinghashed', 'lurker', 'lurker@old.example.com', '', '2022-02-02 02:02:00', 'Never Posts');

INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES
  (1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
  (2, 'wp_capabilities', 'a:1:{s:6:"author";b:1;}'),
  (3, 'wp_capabilities', 'a:1:{s:10:"subscriber";b:1;}'),
  (1, 'first_name', 'Alex'),
  (1, 'last_name', 'Admin'),
  (2, 'first_name', 'Jo'),
  (2, 'last_name', 'Writer'),
  (2, 'description', 'Writes about migrations.');

INSERT INTO wp_terms (term_id, name, slug) VALUES
  (1, 'Announcements', 'announcements'),
  (2, 'Case Studies', 'case-studies'),
  (3, 'Deep Dives', 'deep-dives'),
  (4, 'migration', 'migration'),
  (5, 'Primary', 'primary-menu'),
  (6, 'Widgets', 'widgets-cat'),
  (7, 'Blue', 'blue'),
  (8, 'Red', 'red'),
  (9, 'variable', 'variable');

INSERT INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES
  (1, 1, 'category', '', 0, 0),
  (2, 2, 'category', '', 0, 0),
  (3, 3, 'category', '', 2, 0),
  (4, 4, 'post_tag', '', 0, 0),
  (5, 5, 'nav_menu', '', 0, 0),
  (6, 6, 'product_cat', '', 0, 0),
  (7, 7, 'pa_colour', '', 0, 0),
  (8, 8, 'pa_colour', '', 0, 0),
  (9, 9, 'product_type', '', 0, 0);

INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES
  (10, 2, '2024-03-01 10:00:00', '2024-03-01 10:00:00', '<!-- wp:paragraph -->
<p>Migrating a site is mostly about not losing things.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>What usually breaks</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul><!-- wp:list-item -->
<li>Links to the old domain</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Shortcodes nobody expanded</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:image {"id":41,"align":"center"} -->
<figure class="wp-block-image aligncenter"><img src="https://old.example.com/wp-content/uploads/2023/05/header.jpg" alt="A header" class="wp-image-41"/></figure>
<!-- /wp:image -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Nothing survives contact with a real database.</p>
<!-- /wp:paragraph --><cite>Everyone</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","providerNameSlug":"youtube"} -->
<figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=dQw4w9WgXcQ
</div></figure>
<!-- /wp:embed -->

<!-- wp:acme/unsupported {"x":1} -->
<div class="acme">Third-party block content</div>
<!-- /wp:acme/unsupported -->', 'Migrating without losing things', 'A short summary.', 'publish', 'open', 'open', '', 'migrating-without-losing-things', '', '', '2024-03-01 10:00:00', '2024-03-01 10:00:00', '', 0, 'https://old.example.com/?p=10', 0, 'post', '', 0),
  (11, 2, '2023-11-14 08:30:00', '2023-11-14 08:30:00', 'This post was written in the classic editor.

It has bare newlines and a link to https://old.example.com/hello-world/ that needs rewriting.

[caption id="attachment_41" align="alignright" width="300"]<img src="https://old.example.com/wp-content/uploads/2023/05/header.jpg" class="wp-image-41" /> The caption text[/caption]

[gallery ids="41"]

[unknown_shortcode foo="bar"] should be left alone.', 'Hello world', '', 'publish', 'open', 'open', '', 'hello-world', '', '', '2023-11-14 08:30:00', '2023-11-14 08:30:00', '', 0, 'https://old.example.com/?p=11', 0, 'post', '', 0),
  (12, 1, '2024-01-05 12:00:00', '2024-01-05 12:00:00', '<!-- wp:paragraph --><p>A draft nobody finished.</p><!-- /wp:paragraph -->', 'Unfinished thoughts', '', 'draft', 'open', 'open', '', 'unfinished-thoughts', '', '', '2024-01-05 12:00:00', '2024-01-05 12:00:00', '', 0, 'https://old.example.com/?p=12', 0, 'post', '', 0),
  (21, 1, '2022-05-01 09:00:00', '2022-05-01 09:00:00', '<p>Our team.</p>', 'The team', '', 'publish', 'open', 'open', '', 'team', '', '', '2022-05-01 09:00:00', '2022-05-01 09:00:00', '', 20, 'https://old.example.com/?page_id=21', 2, 'page', '', 0),
  (22, 1, '2022-05-01 09:05:00', '2022-05-01 09:05:00', '<p>Our history.</p>', 'History', '', 'publish', 'open', 'open', '', 'history', '', '', '2022-05-01 09:05:00', '2022-05-01 09:05:00', '', 20, 'https://old.example.com/?page_id=22', 1, 'page', '', 0),
  (20, 1, '2022-04-30 09:00:00', '2022-04-30 09:00:00', '<p>About us.</p>', 'About', '', 'publish', 'open', 'open', '', 'about', '', '', '2022-04-30 09:00:00', '2022-04-30 09:00:00', '', 0, 'https://old.example.com/?page_id=20', 0, 'page', '', 0),
  (41, 1, '2023-05-02 11:00:00', '2023-05-02 11:00:00', '', 'Header image', 'A caption WordPress calls the excerpt', 'inherit', 'open', 'open', '', 'header', '', '', '2023-05-02 11:00:00', '2023-05-02 11:00:00', '', 0, 'https://old.example.com/wp-content/uploads/2023/05/header.jpg', 0, 'attachment', 'image/jpeg', 0),
  (51, 1, '2022-06-01 10:00:00', '2022-06-01 10:00:00', '', '', '', 'publish', 'open', 'open', '', '51', '', '', '2022-06-01 10:00:00', '2022-06-01 10:00:00', '', 0, '', 1, 'nav_menu_item', '', 0),
  (52, 1, '2022-06-01 10:00:00', '2022-06-01 10:00:00', '', 'Our story', '', 'publish', 'open', 'open', '', '52', '', '', '2022-06-01 10:00:00', '2022-06-01 10:00:00', '', 0, '', 2, 'nav_menu_item', '', 0),
  (53, 1, '2022-06-01 10:00:00', '2022-06-01 10:00:00', '', 'External', '', 'publish', 'open', 'open', '', '53', '', '', '2022-06-01 10:00:00', '2022-06-01 10:00:00', '', 0, '', 3, 'nav_menu_item', '', 0),
  (60, 1, '2024-02-01 09:00:00', '2024-02-01 09:00:00', 'A widget you can hold.', 'Deluxe Widget', 'Short blurb.', 'publish', 'open', 'open', '', 'deluxe-widget', '', '', '2024-02-01 09:00:00', '2024-02-01 09:00:00', '', 0, 'https://old.example.com/?post_type=product&p=60', 0, 'product', '', 0),
  (61, 1, '2024-02-01 09:01:00', '2024-02-01 09:01:00', '', 'Deluxe Widget - Blue', '', 'publish', 'open', 'open', '', 'deluxe-widget-blue', '', '', '2024-02-01 09:01:00', '2024-02-01 09:01:00', '', 60, '', 1, 'product_variation', '', 0),
  (62, 1, '2024-02-01 09:02:00', '2024-02-01 09:02:00', '', 'Deluxe Widget - Red', '', 'publish', 'open', 'open', '', 'deluxe-widget-red', '', '', '2024-02-01 09:02:00', '2024-02-01 09:02:00', '', 60, '', 2, 'product_variation', '', 0),
  (70, 1, '2024-02-10 15:22:00', '2024-02-10 15:22:00', '', 'Order &ndash; February 10, 2024 @ 03:22 PM', 'Please leave by the porch.', 'wc-completed', 'open', 'open', '', 'order-70', '', '', '2024-02-10 15:22:00', '2024-02-10 15:22:00', '', 0, '', 0, 'shop_order', '', 0),
  (80, 1, '2024-01-01 00:00:00', '2024-01-01 00:00:00', '', 'SPRING10', 'Ten percent off.', 'publish', 'open', 'open', '', 'spring10', '', '', '2024-01-01 00:00:00', '2024-01-01 00:00:00', '', 0, '', 0, 'shop_coupon', '', 0);

INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
  (10, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%'),
  (10, '_yoast_wpseo_metadesc', 'How to migrate a WordPress site without losing the parts that matter.'),
  (10, '_yoast_wpseo_focuskw', 'wordpress migration'),
  (10, '_yoast_wpseo_meta-robots-noindex', '2'),
  (10, '_thumbnail_id', '41'),
  (10, 'reading_time', '7'),
  (10, '_reading_time', 'field_65a1b2c3d4e5f'),
  (10, 'related_link', 'https://example.org/a'),
  (10, 'related_link', 'https://example.org/b'),
  (11, '_yoast_wpseo_title', 'Hello world'),
  (11, '_thumbnail_id', '41'),
  (41, '_wp_attached_file', '2023/05/header.jpg'),
  (41, '_wp_attachment_image_alt', 'A wide header photograph'),
  (41, '_wp_attachment_metadata', 'a:3:{s:5:"width";i:1600;s:6:"height";i:900;s:4:"file";s:18:"2023/05/header.jpg";}'),
  (51, '_menu_item_type', 'post_type'),
  (51, '_menu_item_object', 'page'),
  (51, '_menu_item_object_id', '20'),
  (51, '_menu_item_menu_item_parent', '0'),
  (51, '_menu_item_classes', 'a:1:{i:0;s:0:"";}'),
  (52, '_menu_item_type', 'post_type'),
  (52, '_menu_item_object', 'post'),
  (52, '_menu_item_object_id', '10'),
  (52, '_menu_item_menu_item_parent', '51'),
  (52, '_menu_item_classes', 'a:2:{i:0;s:9:"highlight";i:1;s:4:"wide";}'),
  (53, '_menu_item_type', 'custom'),
  (53, '_menu_item_object', 'custom'),
  (53, '_menu_item_object_id', '53'),
  (53, '_menu_item_url', 'https://elsewhere.example.net/'),
  (53, '_menu_item_menu_item_parent', '0'),
  (53, '_menu_item_target', '_blank'),
  (60, '_sku', 'WIDGET-DLX'),
  (60, '_price', '24.00'),
  (60, '_regular_price', '24.00'),
  (60, '_manage_stock', 'no'),
  (60, '_stock_status', 'instock'),
  (60, '_weight', '0.4'),
  (60, '_virtual', 'no'),
  (60, '_downloadable', 'no'),
  (60, '_tax_status', 'taxable'),
  (60, '_product_attributes', 'a:1:{s:9:"pa_colour";a:6:{s:4:"name";s:9:"pa_colour";s:5:"value";s:0:"";s:8:"position";i:0;s:10:"is_visible";i:1;s:12:"is_variation";i:1;s:11:"is_taxonomy";i:1;}}'),
  (60, '_thumbnail_id', '41'),
  (61, '_sku', 'WIDGET-DLX-BL'),
  (61, '_price', '24.00'),
  (61, '_regular_price', '24.00'),
  (61, 'attribute_pa_colour', 'blue'),
  (61, '_stock_status', 'instock'),
  (61, '_manage_stock', 'yes'),
  (61, '_stock', '12'),
  (62, '_sku', 'WIDGET-DLX-RD'),
  (62, '_price', '26.00'),
  (62, '_regular_price', '26.00'),
  (62, 'attribute_pa_colour', 'red'),
  (62, '_stock_status', 'outofstock'),
  (62, '_manage_stock', 'yes'),
  (62, '_stock', '0'),
  (70, '_order_total', '50.00'),
  (70, '_order_currency', 'GBP'),
  (70, '_customer_user', '2'),
  (70, '_billing_email', 'jo@old.example.com'),
  (70, '_billing_first_name', 'Jo'),
  (70, '_billing_last_name', 'Writer'),
  (70, '_billing_address_1', '1 Example Street'),
  (70, '_billing_city', 'Norwich'),
  (70, '_billing_postcode', 'NR1 1AA'),
  (70, '_billing_country', 'GB'),
  (70, '_order_shipping', '2.00'),
  (70, '_order_tax', '0.00'),
  (70, '_payment_method_title', 'Card'),
  (70, '_date_paid', '1707579720'),
  (80, 'discount_type', 'percent'),
  (80, 'coupon_amount', '10'),
  (80, 'usage_count', '3');

INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
  (10, 1, 0),
  (10, 4, 0),
  (11, 3, 0),
  (51, 5, 0),
  (52, 5, 0),
  (53, 5, 0),
  (60, 6, 0),
  (60, 9, 0),
  (60, 7, 0),
  (60, 8, 0);

INSERT INTO wp_comments (comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_approved, comment_type, comment_parent, user_id) VALUES
  (1, 10, 'Reader One', 'one@example.net', '', '127.0.0.1', '2024-03-02 09:00:00', '2024-03-02 09:00:00', 'Useful, thank you.', '1', 'comment', 0, 0),
  (2, 10, 'Reader Two', 'two@example.net', '', '127.0.0.1', '2024-03-02 11:30:00', '2024-03-02 11:30:00', 'Agreed with the above.', '1', 'comment', 1, 0),
  (3, 10, 'Cheap Pills', 'spam@spam.invalid', '', '127.0.0.1', '2024-03-03 03:00:00', '2024-03-03 03:00:00', 'BUY NOW', 'spam', 'comment', 0, 0),
  (4, 11, 'Jo Writer', 'jo@old.example.com', '', '127.0.0.1', '2023-11-15 10:00:00', '2023-11-15 10:00:00', 'Fixed a typo.', '1', 'comment', 0, 2),
  (5, 11, 'Some Blog', '', '', '127.0.0.1', '2023-11-16 10:00:00', '2023-11-16 10:00:00', 'Linkback', '1', 'pingback', 0, 0);

INSERT INTO wp_woocommerce_order_items (order_item_id, order_item_name, order_item_type, order_id) VALUES
  (1, 'Deluxe Widget - Blue', 'line_item', 70),
  (2, 'Flat rate', 'shipping', 70);

INSERT INTO wp_woocommerce_order_itemmeta (order_item_id, meta_key, meta_value) VALUES
  (1, '_product_id', '60'),
  (1, '_variation_id', '61'),
  (1, '_qty', '2'),
  (1, '_line_subtotal', '48.00'),
  (1, '_line_total', '48.00'),
  (1, '_line_tax', '0.00'),
  (2, 'cost', '2.00'),
  (2, 'total_tax', '0.00'),
  (2, 'method_id', 'flat_rate');

INSERT INTO wp_redirection_items (url, regex, status, action_type, action_code, action_data) VALUES
  ('/old-hello-world', 0, 'enabled', 'url', 301, '/hello-world/'),
  ('/blog/(.*)', 1, 'enabled', 'url', 301, '/$1'),
  ('/gone-for-good', 0, 'enabled', 'url', 301, 'https://elsewhere.example.net/');

