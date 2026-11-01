<?php

/** Offline theme contracts. No WordPress installation or plugin is required. */
$theme = dirname(__DIR__, 2) . '/theme/homeward';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/css.php';


function theme_luminance($hex) {
	$rgb = array_map(static function ($value) {
		$value /= 255;
		return $value <= 0.04045 ? $value / 12.92 :(($value + 0.055) / 1.055) ** 2.4;
	}, sscanf($hex, '#%02x%02x%02x'));
	return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
}

function theme_contrast($a, $b) {
	$a = theme_luminance($a);
	$b = theme_luminance($b);
	return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

function theme_red_hex($hex) {
	if (strlen($hex) <= 4) {
		$hex = implode('', array_map(static fn($c) => $c . $c, str_split(substr($hex, 0, 3))));
	}
	list($r, $g, $b) = array_values(unpack('C3', hex2bin(substr($hex, 0, 6))));
	$max = max($r, $g, $b);
	$min = min($r, $g, $b);
	if (!$max || ($max - $min) / $max < 0.2 || $r !== $max) {
		return false;
	}
	$hue = 60 * ($g - $b) / ($max - $min);
	return $hue >= -25 && $hue <= 25;
}

try {
	$config = json_decode(file_get_contents("$theme/theme.json"), true, 512, JSON_THROW_ON_ERROR);
	check('valid theme.json version 3', 3 === $config['version']);
} catch (JsonException $e) {
	echo 'FAIL theme.json: ' . $e->getMessage() . "\n";
	exit(1);
}
$palette = array_column($config['settings']['color']['palette'], 'color', 'slug');
check(
	'exact palette slugs',
	array_keys($palette) === array('primary', 'accent', 'ink', 'background', 'surface'),
);
foreach (
	array(
		'primary' => '#1F4E79',
		'ink' => '#2B2A28',
		'background' => '#F7F1E3',
		'surface' => '#FFFDF8',
	) as $slug => $hex
) {
	check("default $slug", $hex === $palette[$slug]);
}
foreach (array('background', 'surface') as $bg) {
	foreach (array('ink', 'primary') as $fg) {
		check("AA $fg on $bg", theme_contrast($palette[$fg], $palette[$bg]) >= 4.5);
	}
}
// Accent is a fill colour: ink text sits on it, it is never body text on cream.
check('AA ink on accent', theme_contrast($palette['ink'], $palette['accent']) >= 4.5);
$fonts = array_column($config['settings']['typography']['fontFamilies'], null, 'slug');
$font_slugs = array(
	'fraunces',
	'lora',
	'source-serif-4',
	'playfair-display',
	'dm-serif-display',
	'merriweather',
	'inter',
	'atkinson-hyperlegible',
	'nunito-sans',
	'figtree',
	'work-sans',
	'nunito',
);
// The shared plugin list must name exactly the fonts the theme registers.
$fonts_class = file_get_contents(dirname($theme, 2) . '/plugin/homeward-rescue/includes/class-fonts.php');
preg_match_all("~'([a-z0-9-]+)'\s*=>\s*array\(\s*'name'\s*=>\s*'[^']+',\s*'category'\s*=>~", $fonts_class, $listed);
check('twelve fonts in the shared list', 12 === count($font_slugs) && 12 === count($listed[1]));
check(
	'shared list matches the theme fonts',
	array() === array_diff($font_slugs, $listed[1]) && array() === array_diff($listed[1], $font_slugs),
);
foreach ($font_slugs as $slug) {
	$plugin_fonts = dirname($theme, 2) . '/plugin/homeward-rescue/assets/fonts';
	check(
		"plugin preview OFL for $slug",
		is_file("$plugin_fonts/$slug-OFL.txt") && md5_file("$plugin_fonts/$slug-OFL.txt") === md5_file(
			"$theme/assets/fonts/$slug-OFL.txt",
		),
	);
	$previews = glob("$plugin_fonts/$slug-latin-*.woff2");
	check("plugin preview font files for $slug", count($previews) >= 1);
	foreach ($previews as $preview) {
		check(
			"plugin preview matches theme file " . basename($preview),
			'wOF2' === file_get_contents($preview, false, null, 0, 4) && md5_file($preview) === md5_file(
				"$theme/assets/fonts/" . basename($preview),
			),
		);
	}
}
foreach (array_merge(array('heading', 'body'), $font_slugs) as $slug) {
	check("font slug $slug", isset($fonts[$slug]));
}
check('default heading role', str_contains($fonts['heading']['fontFamily'], 'fraunces'));
check('default body role', str_contains($fonts['body']['fontFamily'], 'inter'));
$manifest = file_get_contents("$theme/assets/fonts/FONTS.md");
$missing_fonts = 0;
foreach ($font_slugs as $slug) {
	check("font faces for $slug", count($fonts[$slug]['fontFace'] ?? array()) >= 2);
	$licence = "$theme/assets/fonts/$slug-OFL.txt";
	check(
		"OFL for $slug",
		is_file($licence) && str_contains(
			file_get_contents($licence),
			'SIL OPEN FONT LICENSE Version 1.1',
		) && str_contains(file_get_contents($licence), 'Copyright'),
	);
	foreach ($fonts[$slug]['fontFace'] as $face) {
		check("$slug uses swap", 'swap' === $face['fontDisplay']);
		foreach ($face['src'] as $source) {
			check(
				"local Latin WOFF2 $source",
				str_starts_with(
					$source,
					'file:./assets/fonts/',
				) && str_ends_with($source, '.woff2') && str_contains($source, '-latin-'),
			);
			$file = "$theme/" . substr($source, 7);
			$name = basename($file);
			if (is_file($file)) {
				check("real WOFF2 $name", 'wOF2' === file_get_contents($file, false, null, 0, 4));
			} else {
				++$missing_fonts;
				check(
					"exact download URL for $name",
					(bool) preg_match(
						'~\| `'
							. preg_quote($name, '~')
							. '` \| https://cdn\.jsdelivr\.net/npm/\@fontsource(?:-variable)?/'
							. preg_quote($slug, '~')
							. '/files/'
							. preg_quote($name, '~')
							. ' \|~',
						$manifest,
					),
				);
			}
		}
	}
}
$style = file_get_contents("$theme/style.css");
foreach (
	array(
		'Theme Name: Homeward',
		'Theme URI: https://github.com/michaelbystryk/animal-rescue-site-kit',
		'Requires at least: 6.7',
		'Requires PHP: 8.1',
		'Text Domain: homeward',
		'SPDX-License-Identifier: GPL-2.0-or-later',
	) as $header
) {
	check("header $header", str_contains($style, $header));
}
foreach (
	new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($theme, FilesystemIterator::SKIP_DOTS),
	) as $file
) {
	if (in_array($file->getExtension(), array('png', 'woff2'), true)) {
		continue;
	}
	$text = file_get_contents($file->getPathname());
	$path = substr($file->getPathname(), strlen($theme) + 1);
	check(
		"no em dash in $path",
		!str_contains($text, "\u{2014}") && !preg_match('/&(?:mdash|#8212|#x2014);/i', $text),
	);
	check("portable paths in $path", !str_contains($text, '/' . 'Users' . '/'));
	check(
		"no reference brand in $path",
		!str_contains(strtolower($text), implode('', array('la', 'ldr'))),
	);
	preg_match_all('/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{4}|[0-9a-f]{3})\b/i', $text, $hexes);
	foreach ($hexes[1] as $hex) {
		check("no red hex $hex in $path", !theme_red_hex($hex));
	}
	if (in_array($file->getExtension(), array('php', 'css', 'js', 'json', 'html'), true)) {
		check(
			"no runtime font CDN in $path",
			!preg_match('~fonts\.(?:googleapis|gstatic)\.com|cdn\.jsdelivr\.net~', $text),
		);
	}
}
$expected_templates = array(
	'index',
	'home',
	'front-page',
	'page',
	'single',
	'archive',
	'search',
	'404',
	'archive-homeward_animal',
	'single-homeward_animal',
	'single-homeward_story',
);
foreach ($expected_templates as $name) {
	check("template $name exists", is_file("$theme/templates/$name.html"));
}
foreach (glob("$theme/templates/*.html") as $file) {
	$text = file_get_contents($file);
	check(
		basename($file) . ' has one main and skip target',
		substr_count($text, '<main ') === 1 && substr_count($text, 'id="main-content"') === 1,
	);
	check(
		basename($file) . ' has header and footer',
		str_contains($text, '"slug":"header"') && str_contains($text, '"slug":"footer"'),
	);
}
foreach (array('page', 'single', 'front-page', 'single-homeward_story') as $name) {
	check(
		"$name renders saved content",
		str_contains(file_get_contents("$theme/templates/$name.html"), 'wp:post-content'),
	);
}
$png = getimagesize("$theme/screenshot.png");
check('1200 x 900 PNG preview', 1200 === $png[0] && 900 === $png[1] && IMAGETYPE_PNG === $png[2]);

// Isolated WordPress surface: deliberately do not load the plugin or its doubles.
define('ABSPATH', __DIR__ . '/');
$GLOBALS['theme_hooks'] = array();
$GLOBALS['theme_meta'] = array();
$GLOBALS['theme_can'] = true;

function add_action($hook, $callback, ...$rest) {
	$GLOBALS['theme_hooks'][$hook][] = $callback;
}

function add_filter(...$args) {
	add_action(...$args);
}

function __($text, $domain = '') {
	return $text;
}

function esc_html($text) {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_html__($text, $domain = '') {
	return esc_html($text);
}

function esc_html_e($text, $domain = '') {
	echo esc_html($text);
}

function esc_attr_e($text, $domain = '') {
	echo esc_html($text);
}

function esc_url($text) {
	return esc_html($text);
}

function wp_json_encode($data) {
	return json_encode($data);
}

function home_url($path = '') {
	return 'https://example.invalid/rescue' . $path;
}

function admin_url($path = '') {
	return home_url('/wp-admin/' . $path);
}

function get_theme_file_uri($path) {
	return home_url('/wp-content/themes/homeward/' . $path);
}

function get_post_type_archive_link($type) {
	return false;
}

function get_privacy_policy_url() {
	return '';
}

function get_the_title() {
	return 'Example animal';
}

function current_user_can($cap) {
	return $GLOBALS['theme_can'];
}

function get_current_user_id() {
	return 1;
}

function get_user_meta($id, $key, $single) {
	return $GLOBALS['theme_meta'][$key] ?? '';
}

function wp_nonce_url($url, $action) {
	return $url . '&_wpnonce=test-nonce';
}

class WP_Block_Type_Registry {
	public array $registered = array();

	public static function get_instance() {
		static $instance;
		return $instance ??= new self();
	}

	public function is_registered($name) {
		return in_array($name, $this->registered, true);
	}
}

require "$theme/functions.php";
check('functions load without plugin', !homeward_theme_plugin_available());
ob_start();
homeward_theme_skip_link();
$skip = ob_get_clean();
check('server rendered skip link', str_contains($skip, 'href="#main-content"'));
ob_start();
homeward_theme_plugin_notice();
$notice = ob_get_clean();
check('missing plugin has installation reminder', str_contains($notice, 'Install Homeward Rescue'));
check(
	'notice has nonce dismissal link',
	str_contains($notice, 'homeward_theme_dismiss_notice') && str_contains($notice, '_wpnonce='),
);
$GLOBALS['theme_meta']['homeward_theme_notice_dismissed'] = 1;
ob_start();
homeward_theme_plugin_notice();
check('dismissed notice stays hidden', '' === ob_get_clean());
$GLOBALS['theme_meta'] = array();
$GLOBALS['theme_can'] = false;
ob_start();
homeward_theme_plugin_notice();
check('notice limited to administrators', '' === ob_get_clean());
$GLOBALS['theme_can'] = true;

$contracts = array();
foreach (glob(dirname(__DIR__, 2) . '/plugin/homeward-rescue/blocks/*/block.json') as $file) {
	$block = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	$contracts[$block['name']] = $block;
}
foreach (array_merge(array_keys($contracts), array('homeward/contact-form')) as $name) {
	check(
		"$name missing block has readable fallback",
		str_contains(homeward_theme_block_fallback('', array('blockName' => $name)), 'homeward-unavailable'),
	);
	WP_Block_Type_Registry::get_instance()->registered = array($name);
	check(
		"$name registered renderer preserved",
		'<p>Plugin output</p>' === homeward_theme_block_fallback(
			'<p>Plugin output</p>',
			array('blockName' => $name),
		),
	);
	WP_Block_Type_Registry::get_instance()->registered = array();
}
check(
	'ordinary blocks are unchanged',
	'<p>Saved page</p>' === homeward_theme_block_fallback(
		'<p>Saved page</p>',
		array('blockName' => 'core/paragraph'),
	),
);
$patterns = array();
foreach (glob("$theme/patterns/*.php") as $file) {
	$raw = file_get_contents($file);
	check(
		basename($file) . ' has pattern header',
		(bool) preg_match(
			'~/\*\*[\s\S]*?\* Title: .+\n \* Slug: (homeward/[a-z0-9-]+)\n \* Categories: .+[\s\S]*?\*/~',
			$raw,
			$header,
		),
	);
	if (empty($header[1])) {
		continue;
	}
	check('unique pattern ' . $header[1], !isset($patterns[$header[1]]));
	ob_start();
	include $file;
	$patterns[$header[1]] = ob_get_clean();
}
check(
	'contact falls back to core text',
	!str_contains(
		$patterns['homeward/contact'],
		'wp:homeward/contact-form',
	) && str_contains($patterns['homeward/contact'], 'Sample contact section'),
);
WP_Block_Type_Registry::get_instance()->registered = array('homeward/contact-form');
ob_start();
include "$theme/patterns/contact.php";
$contact = ob_get_clean();
check('contact block is used when registered', str_contains($contact, 'wp:homeward/contact-form /-->'));
WP_Block_Type_Registry::get_instance()->registered = array();

$sources = $patterns;
foreach (array_merge(glob("$theme/templates/*.html"), glob("$theme/parts/*.html")) as $file) {
	$sources[basename(dirname($file)) . '/' . basename($file)] = file_get_contents($file);
}
$seen_blocks = array();
foreach ($sources as $name => $text) {
	preg_match_all('~<!--\s*(/?)wp:([a-z0-9/-]+)(.*?)-->~s', $text, $matches, PREG_SET_ORDER);
	$stack = array();
	foreach ($matches as $match) {
		if ($match[1]) {
			check("$name balanced closing $match[2]", array_pop($stack) === $match[2]);
			continue;
		}
		$attrs_text = trim(preg_replace('~/\s*$~', '', trim($match[3])));
		$attrs = array();
		if ($attrs_text) {
			$attrs = json_decode($attrs_text, true);
			check("$name valid block JSON", JSON_ERROR_NONE === json_last_error() && is_array($attrs));
			$attrs = $attrs ?? array();
		}
		if ('pattern' === $match[2]) {
			check("$name resolves pattern", isset($patterns[$attrs['slug'] ?? '']));
		}
		if (str_starts_with($match[2], 'homeward/')) {
			$seen_blocks[$match[2]] = true;
			check("$name uses real plugin block $match[2]", isset($contracts[$match[2]]));
			foreach ($attrs as $key => $value) {
				$spec = $contracts[$match[2]]['attributes'][$key] ?? null;
				check("$name supported attribute $key", null !== $spec);
				$valid = match ($spec['type'] ?? '') {
					'boolean' => is_bool($value),
					'number' => is_numeric($value),
					'string' => is_string($value),
					'array' => is_array($value),
					default => false,
				};
				check("$name attribute type $key", $valid);
				if (isset($spec['enum'])) {
					check("$name enum $key", in_array($value, $spec['enum'], true));
				}
			}
		}
		if (!str_ends_with(trim($match[3]), '/')) {
			$stack[] = $match[2];
		}
	}
	check("$name has no unclosed blocks", empty($stack));
}
// Used conditionally by the contact pattern, checked above.
$seen_blocks['homeward/contact-form'] = str_contains($contact, 'wp:homeward/contact-form /-->');
foreach (array_keys($contracts) as $name) {
	check("theme uses $name", !empty($seen_blocks[$name]));
}
// Render pattern expansion to check repeatable landmarks and feedback controls.
$expand = function ($html) use (& $expand, $patterns) {
	return preg_replace_callback(
		'~<!-- wp:pattern \{"slug":"([^"]+)"\} /-->~',
		static fn($m) => $expand($patterns[$m[1]]),
		$html,
	);
};
check(
	'front template only renders editable content',
	!str_contains($sources['templates/front-page.html'], 'wp:pattern') && str_contains(
		$sources['templates/front-page.html'],
		'wp:post-content',
	),
);
$front = implode(
	"\n",
	array_intersect_key(
		$patterns,
		array_flip(
			array(
				'homeward/hero',
				'homeward/mission',
				'homeward/animal-highlights',
				'homeward/how-to-adopt',
				'homeward/cards',
				'homeward/stories',
				'homeward/give-cards',
				'homeward/testimonials-row',
				'homeward/events',
				'homeward/contact',
			),
		),
	),
);
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<html><meta charset="utf-8"><body>' . $front . '</body></html>');
libxml_clear_errors();
$xpath = new DOMXPath($doc);
$ids = array();
foreach ($xpath->query('//*[@id]') as $node) {
	$id = $node->getAttribute('id');
	check("unique front-page ID $id", !isset($ids[$id]));
	$ids[$id] = true;
}
foreach ($xpath->query('//img') as $node) {
	check('image alt provided', $node->hasAttribute('alt'));
}
foreach ($xpath->query('//button') as $node) {
	check(
		'button has name and type',
		'' !== trim($node->textContent) && 'button' === $node->getAttribute('type'),
	);
}
check('one hero h1', 1 === $xpath->query('//h1')->length);
check(
	'donations use settings block',
	str_contains(
		$patterns['homeward/give-cards'],
		'wp:homeward/donate-panel',
	) && !str_contains($patterns['homeward/give-cards'], 'example.test'),
);
check(
	'footer has no practice copy',
	!str_contains($patterns['homeward/footer'], 'Sample')
		&& str_contains($patterns['homeward/footer'], 'wp:site-title')
		&& str_contains($patterns['homeward/footer'], 'wp:site-tagline'),
);
check(
	'share panel inherits theme tokens',
	str_contains(
		read_css("$theme/assets/css/theme.css"),
		compact_css('.homeward-story-dialog, .homeward-animal-share {'),
	),
);
define('HOMEWARD_VERSION', 'test');
check('active plugin detected', homeward_theme_plugin_available());
ob_start();
homeward_theme_plugin_notice();
check('active plugin suppresses notice', '' === ob_get_clean());

function post_type_exists($type) {
	return false;
}

function get_page_by_path($slug) {
	return null;
}
// Theme menus resolve the wizard navigation at render time, even after pattern expansion.

function get_option($key, $default = false) {
	return $GLOBALS['theme_options'][$key] ?? $default;
}

function get_post($id) {
	return $GLOBALS['theme_posts'][$id] ?? null;
}

foreach (array('primary', 'footer') as $menu) {
	$block = array(
		'blockName' => 'core/navigation',
		'attrs' => array('className' => 'homeward-' . $menu . '-navigation'),
		'innerBlocks' => array(),
	);
	$fallback = homeward_theme_navigation($block);
	check(
		$menu . ' fallback excludes page list',
		!str_contains(json_encode($fallback), 'core/page-list') && !isset($fallback['attrs']['ref']),
	);
	$GLOBALS['theme_options']['homeward_navigation_id'] = 71;
	$GLOBALS['theme_posts'][71] = (object) array('post_type' => 'wp_navigation', 'post_status' => 'publish');
	$linked = homeward_theme_navigation($block);
	check(
		$menu . ' references wizard navigation',
		71 === $linked['attrs']['ref'] && array() === $linked['innerBlocks'],
	);
	$GLOBALS['theme_posts'][71]->post_status = 'draft';
	check(
		$menu . ' never references draft menu',
		!isset(homeward_theme_navigation($linked)['attrs']['ref']),
	);
	$GLOBALS['theme_options'] = array();
}
$custom = array('blockName' => 'core/navigation', 'attrs' => array('ref' => 99));
check('unrelated navigation preserved', $custom === homeward_theme_navigation($custom));
$css = read_css("$theme/assets/css/theme.css");
check(
	'top-level navigation centers items',
	str_contains($css, compact_css('.homeward-header .wp-block-navigation__container { align-items: center; }')),
);
check(
	'submenu and link labels share line height',
	str_contains($css, compact_css('.homeward-header .wp-block-navigation-item__content { line-height: 1.4; }')),
);
check(
	'submenu icons are centered',
	str_contains(
		$css,
		compact_css('display: inline-flex; align-items: center; justify-content: center; margin-block: 0;'),
	),
);
check(
	'animal section delegates its only heading to carousel',
	!str_contains($patterns['homeward/animal-highlights'], 'wp:heading') && str_contains(
		$patterns['homeward/animal-highlights'],
		'wp:homeward/animal-carousel',
	),
);
$hero = new DOMDocument();
$hero->loadXML(file_get_contents("$theme/assets/images/hero-placeholder.svg"));
$circle = $hero->getElementsByTagName('circle')->item(0);
$caption = $hero->getElementsByTagName('text')->item(0);
check(
	'hero caption sits below icon',
	(float) $caption->getAttribute('y') - 24 > (float) $circle->getAttribute('cy') + (float) $circle->getAttribute('r'),
);
check(
	'hero label occurs only inside image',
	!str_contains($patterns['homeward/hero'], 'Your rescue photo goes here'),
);

echo "$missing_fonts font files documented for download.\n";
summary('theme');
