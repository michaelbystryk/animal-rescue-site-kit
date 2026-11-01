<?php

/** Offline service integration checks using the shared WordPress store. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{
	Settings,
	Settings_Page,
	Plugin,
	Email_Guard,
	Redirects,
	Llms_Txt,
	Analytics,
	Seo,
	Animal,
	Animal_Post_Type,
	Content_Post_Types,
	Sync,
};

function is_admin() {
	return !empty($GLOBALS['service_context']['admin']);
}

function is_feed() {
	return !empty($GLOBALS['service_context']['feed']);
}

function wp_doing_ajax() {
	return !empty($GLOBALS['service_context']['ajax']);
}

function wp_is_json_request() {
	return !empty($GLOBALS['service_context']['json']);
}

function is_preview() {
	return !empty($GLOBALS['service_context']['preview']);
}

function is_user_logged_in() {
	return !empty($GLOBALS['service_context']['logged_in']);
}

function is_home() {
	return !empty($GLOBALS['service_context']['home']);
}

function is_front_page() {
	return !empty($GLOBALS['service_context']['front']);
}

function is_page() {
	return is_singular('page');
}

function is_archive() {
	return (bool) $GLOBALS['archive'];
}

function strip_shortcodes($s) {
	return preg_replace('/\[[^\]]*\]/', '', $s);
}

function wp_get_environment_type() {
	return 'development';
}

function wp_add_privacy_policy_content($name, $text) {
	$GLOBALS['privacy'][] = $text;
}

function get_page_uri($post) {
	$post = get_post($post);
	return $post->test_path ?? $post->post_name;
}

function settings_errors() {}

function settings_fields(...$args) {}

function submit_button($text = 'Save Changes', ...$args) {
	echo '<button type="submit">' . esc_html($text) . '</button>';
}

function wp_redirect($url, $status = 302, $by = '') {
	$GLOBALS['service_redirect'] = array($url, $status, $by);
	throw new RuntimeException('redirect intercepted');
}

/** DOM-backed subset of the WordPress HTML processor needed by the SEO tests. */

class WP_HTML_Tag_Processor {
	private $html, $tokens = array(), $index = -1, $changes = array();

	public function __construct($html) {
		$this->html = $html;
		preg_match_all('~</?[a-z][^>]*>~i', $html, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [$tag, $offset]) {
			preg_match('~^<(/?)([a-z0-9]+)~i', $tag, $m);
			$dom = new DOMDocument();
			@$dom->loadHTML('<!doctype html><html><body>' . $tag . '</body></html>');
			$node = $dom->getElementsByTagName($m[2])->item(0);
			$attrs = array();
			if ($node) {
				foreach ($node->attributes as $attr) {
					$attrs[$attr->name] = $attr->value;
				}
			}
			$this->tokens[] = array(
				'raw' => $tag,
				'offset' => $offset,
				'tag' => strtoupper($m[2]),
				'closer' => (bool) $m[1],
				'attrs' => $attrs,
			);
		}
	}

	public function next_tag($query = null) {
		while (isset($this->tokens[++$this->index])) {
			$token = $this->tokens[$this->index];
			if ($token['closer'] && (!is_array($query) || ($query['tag_closers'] ?? '') !== 'visit')) {
				continue;
			}
			if (is_string($query) && $token['tag'] !== strtoupper($query)) {
				continue;
			}
			return true;
		}
		return false;
	}

	public function get_tag() {
		return $this->tokens[$this->index]['tag'];
	}

	public function is_tag_closer() {
		return $this->tokens[$this->index]['closer'];
	}

	public function get_attribute($name) {
		return $this->tokens[$this->index]['attrs'][$name] ?? null;
	}

	public function has_class($name) {
		return in_array($name, preg_split('/\s+/', trim($this->get_attribute('class') ?? '')), true);
	}

	public function set_attribute($name, $value) {
		$this->tokens[$this->index]['attrs'][$name] = $value;
		$this->changes[$this->index] = true;
	}

	public function get_updated_html() {
		$html = $this->html;
		$indices = array_keys($this->changes);
		rsort($indices);
		foreach ($indices as $i) {
			$token = $this->tokens[$i];
			$tag = '<' . strtolower($token['tag']);
			foreach ($token['attrs'] as $name => $value) {
				$tag .= ' ' . $name . '="' . esc_attr($value) . '"';
			}
			$html = substr_replace($html, $tag . '>', $token['offset'], strlen($token['raw']));
		}
		return $html;
	}
}

function service_output($callback) {
	ob_start();
	$callback();
	return ob_get_clean();
}

function service_settings($values) {
	update_option('homeward_settings', Settings::sanitize($values));
}

function service_context($values = array()) {
	$GLOBALS['service_context'] = $values;
}

equals('email guard defaults on', true, Settings::get('email_guard'));
equals('llms defaults on', true, Settings::get('llms_txt'));
equals('analytics defaults off', false, Analytics::configured());
Plugin::init();
foreach (
	array(
		'render_block',
		'parse_request',
		'template_redirect',
		'rank_math/json_ld',
		'wpseo_schema_graph',
	) as $hook
) {
	check('service hook ' . $hook, !empty($GLOBALS['hooks'][$hook]));
}

// Email rendering never edits raw elements, unrelated attributes or editor responses.
$html = '<p>fake@example.test <a title="href=mailto:other@example.test" '
	. 'href="mailto:fake@example.test?subject=Hello&amp;body=World">Email us</a>'
	. '<button data-homeward-copy="fake@example.test">Copy</button></p>';
$encoded = Email_Guard::render($html);
check('email text is obfuscated', str_contains($encoded, 'fake [at] example [dot] test'));
preg_match('/data-e="([^"]+)"/', $encoded, $match);
equals('email encoding is reversible', 'fake@example.test', strrev(base64_decode($match[1])));
preg_match('/data-homeward-mailto="([^"]+)"/', $encoded, $match);
equals(
	'mailto parameters decoded',
	'fake@example.test?subject=Hello&body=World',
	strrev(base64_decode($match[1])),
);
check(
	'unrelated attribute preserved',
	str_contains($encoded, 'title="href=mailto:other@example.test"'),
);
check(
	'copy address protected',
	str_contains($encoded, 'data-homeward-copy="" data-homeward-email-copy='),
);
equals('rendering is idempotent', $encoded, Email_Guard::render($encoded));
foreach (
	array(
		'<script>var a="fake@example.test";</script>',
		'<style>/* fake@example.test */</style>',
		'<textarea>fake@example.test</textarea>',
		'<!-- fake@example.test -->',
		'<input value="fake@example.test">',
	) as $raw
) {
	equals('raw content preserved', $raw, Email_Guard::render($raw));
}
foreach (array('admin', 'feed', 'ajax', 'json') as $context) {
	service_context(array($context => true));
	equals('guard skips ' . $context, $html, Email_Guard::render($html));
}
service_context();
check(
	'decoder restores text and links',
	str_contains(service_output(array(Email_Guard::class, 'decoder')), "el.textContent = decode"),
);
service_settings(array('email_guard' => false));
equals('guard setting disables rendering', $html, Email_Guard::render($html));
equals('guard setting disables decoder', '', service_output(array(Email_Guard::class, 'decoder')));

// Redirect parsing and matching, including graph cycles and installation boundaries.
$rules = Redirects::parse(
	"/Old /new\nlegacy/ https://other.invalid/destination 302\n/with-query /new?utm_source=chosen#part 301",
	$errors,
);
equals('valid redirect lines have no errors', array(), $errors);
equals('default redirect status', 301, $rules['/old/']['status']);
equals('explicit temporary status', 302, $rules['/legacy/']['status']);
equals(
	'matching ignores case and trailing slash',
	'/new',
	Redirects::match ('/OLD/', array(), $rules)['target'],
);
equals(
	'matching decodes percent encoded path',
	'/new',
	Redirects::match ('/%6fld', array(), $rules)['target'],
);
equals('matching is exact', null, Redirects::match ('/old/child', array(), $rules));
equals(
	'unrelated queries dropped',
	'/new?utm_medium=email',
	Redirects::match (
		'/old',
		array('utm_medium' => 'email', 'password' => 'never', 'utm_term' => array('no')),
		$rules
	)['target'],
);
equals(
	'target query and fragment retained',
	'/new?utm_source=chosen&utm_medium=email#part',
	Redirects::match (
		'/with-query',
		array('utm_source' => 'incoming', 'utm_medium' => 'email'),
		$rules
	)['target'],
);
equals(
	'root source supported',
	'/new',
	Redirects::match ('/', array(), Redirects::parse('/ /new'))['target'],
);
foreach (
	array(
		'https://old.invalid/path /new',
		'//old.invalid /new',
		'/a/../b /new',
		'/a?query=1 /new',
		'/a#fragment /new',
		'/a /new 307',
		'/a /new extra extra',
		'/a javascript:alert(1)',
		'/a //other.invalid/new',
		'/a http://other.invalid/new',
		'/a https://user:pass@other.invalid/new',
		'/a /%2fother.invalid',
		'/a /new%0d%0aHeader:value',
	) as $line
) {
	equals('unsafe redirect rejected ' . $line, array(), Redirects::parse($line));
}
equals('nested path encoding rejected', array(), Redirects::parse('/%2561 /new'));
check(
	'encoded spaces allowed in target query',
	Redirects::valid_target('/new?utm_campaign=hello%20world'),
);
check('same site HTTP target allowed', Redirects::valid_target('http://example.invalid/new'));
foreach (
	array(
		'/a /a',
		'/a /A/?changed=1',
		'/a https://example.invalid:443/a',
		'/a https://example.invalid./a',
		'/a /b' . "\n" . '/b /a',
		'/c /a' . "\n" . '/a /b' . "\n" . '/b /a',
		'/a /b' . "\n" . '/b /c' . "\n" . '/c /a',
	) as $cycle
) {
	equals('redirect cycle rejected', array(), Redirects::parse($cycle));
}
equals('noncyclic chain retained', 2, count(Redirects::parse("/a /b\n/b /c")));
equals('duplicate normalized source rejected', array(2), (function () {
	Redirects::parse("/a /b\n/A/ /c", $errors);
	return $errors;
})());
service_settings(array('redirects' => "/old /new\n/bad javascript:alert(1)\n/another /destination 302"));
equals(
	'multiline redirects survive settings sanitization',
	"/old/ /new 301\n/another/ /destination 302",
	Settings::get('redirects'),
);
check('invalid lines reported', !empty($GLOBALS['settings_errors']));
$GLOBALS['home_url'] = 'https://example.invalid/rescue';
equals('subdirectory request stripped', '/old', Redirects::request_path('/rescue/old?x=1'));
equals('sibling directory ignored', null, Redirects::request_path('/rescue-other/old'));
equals('subdirectory home matched', '', Redirects::request_path('/rescue'));
equals(
	'subdirectory absolute loop rejected',
	array(),
	Redirects::parse('/a https://example.invalid/rescue/a'),
);
$_SERVER['REQUEST_URI'] = '/rescue/old?utm_source=legacy';
$_SERVER['REQUEST_METHOD'] = 'HEAD';
try {
	Redirects::maybe_redirect();
} catch (RuntimeException $e) {
	equals('redirect interception', $e->getMessage(), 'redirect intercepted');
}
equals(
	'subdirectory HTTP redirect target',
	array('https://example.invalid/rescue/new?utm_source=legacy', 301, 'Homeward Rescue'),
	$GLOBALS['service_redirect'],
);
unset($GLOBALS['service_redirect']);
$_SERVER['REQUEST_METHOD'] = 'POST';
Redirects::maybe_redirect();
check('POST not redirected', !isset($GLOBALS['service_redirect']));
$_SERVER['REQUEST_METHOD'] = 'GET';
service_context(array('admin' => true));
Redirects::maybe_redirect();
check('admin not redirected', !isset($GLOBALS['service_redirect']));
service_context();
unset($GLOBALS['home_url']);
service_settings(array('redirects' => '/old https://other.invalid/new 302'));
$_SERVER['REQUEST_URI'] = '/old';
try {
	Redirects::maybe_redirect();
} catch (RuntimeException $e) {}
equals(
	'external HTTPS redirect supported',
	array('https://other.invalid/new', 302, 'Homeward Rescue'),
	$GLOBALS['service_redirect'],
);

// Analytics is opt-in, visitor-only, and includes the required privacy controls.
equals('statistics region defaults to US', 'us', Settings::get('posthog_region'));
update_option('homeward_settings', array('posthog_host' => 'https://eu.i.posthog.com'));
equals('legacy EU host selects EU region', 'eu', Settings::get('posthog_region'));
service_settings(array('posthog_region' => 'us'));
equals('region derives US host', 'https://us.i.posthog.com', Settings::get('posthog_host'));
service_settings(array('posthog_region' => 'eu', 'posthog_host' => ''));
equals('empty advanced host derives EU host', 'https://eu.i.posthog.com', Settings::get('posthog_host'));
service_settings(array('posthog_key' => 'invalid', 'posthog_host' => 'http://other.invalid'));
equals('invalid key disables analytics', false, Analytics::configured());
equals(
	'invalid host replaced with region host',
	'https://eu.i.posthog.com',
	Settings::get('posthog_host'),
);
foreach (
	array(
		'http://other.invalid',
		'https://user:pass@other.invalid',
		'https://other.invalid?secret=1',
		'https://other.invalid/#fragment',
		'javascript:alert(1)',
	) as $host
) {
	equals('analytics host rejected ' . $host, '', Analytics::sanitize_host($host));
}
service_settings(
	array(
		'posthog_key' => 'phc_' . str_repeat('Z', 20),
		'posthog_host' => 'https://analytics.example.invalid/ingest/',
	),
);
equals(
	'analytics host supports proxy path',
	'https://analytics.example.invalid/ingest',
	Settings::get('posthog_host'),
);
service_settings(
	array('posthog_region' => 'us', 'posthog_host' => 'https://analytics.example.invalid/ingest/'),
);
equals(
	'custom host overrides region',
	'https://analytics.example.invalid/ingest',
	Settings::get('posthog_host'),
);
service_settings(array('phone' => '123'));
equals(
	'unrelated save keeps custom host',
	'https://analytics.example.invalid/ingest',
	Settings::get('posthog_host'),
);
equals(
	'extended key characters accepted',
	'phc_' . str_repeat('x_-', 30),
	Analytics::sanitize_key('phc_' . str_repeat('x_-', 30)),
);
service_settings(array('posthog_key' => 'bad'));
equals('invalid key preserves saved key', 'phc_' . str_repeat('Z', 20), Settings::get('posthog_key'));
check(
	'settings invalid key gives friendly error',
	str_contains(end($GLOBALS['settings_errors'])[2], 'phc_'),
);
equals('configured visitor tracked', true, Analytics::enabled());
foreach (array('logged_in', 'admin', 'preview', 'feed', 'ajax', 'json') as $context) {
	service_context(array($context => true));
	equals('analytics skips ' . $context, false, Analytics::enabled());
	equals(
		'analytics script absent for ' . $context,
		'',
		service_output(array(Analytics::class, 'snippet')),
	);
	equals(
		'analytics events absent for ' . $context,
		'',
		service_output(array(Analytics::class, 'events')),
	);
}
service_context();
$config = Analytics::config();
foreach (
	array(
		'autocapture',
		'rageclick',
		'capture_exceptions',
		'disable_session_recording',
		'disable_surveys',
	) as $key
) {
	equals('analytics config ' . $key, true, $config[$key]);
}
equals('dead clicks disabled', false, $config['capture_dead_clicks']);
equals('text masked', true, $config['mask_all_text']);
equals('attributes masked', true, $config['mask_all_element_attributes']);
equals('memory by default', 'memory', $config['persistence']);
service_settings(array('posthog_cookies' => true));
equals('cookies require opt in', 'localStorage+cookie', Analytics::config()['persistence']);
equals('heatmaps disabled', false, $config['enable_heatmaps']);
equals('web vitals enabled', array('web_vitals' => true), $config['capture_performance']);
$snippet = service_output(array(Analytics::class, 'snippet'));
check('analytics emits configured host', str_contains($snippet, 'analytics.example.invalid'));
check('analytics tracks environment', str_contains($snippet, 'development'));
check(
	'analytics events use kit selectors',
	str_contains(service_output(array(Analytics::class, 'events')), 'data-homeward-filters'),
);
$GLOBALS['privacy'] = array();
Analytics::privacy_content();
check('enabled analytics privacy content', str_contains(implode('', $GLOBALS['privacy']), 'PostHog'));
service_settings(array('posthog_key' => ''));
$GLOBALS['privacy'] = array();
Analytics::privacy_content();
equals('disabled analytics no privacy mention', array(), $GLOBALS['privacy']);
foreach ($GLOBALS['hooks']['admin_init'] ?? array() as [$callback, $argc]) {
	if ($callback instanceof Closure) {
		$callback();
	}
}
$policy = implode(' ', $GLOBALS['privacy']);
foreach (
	array(
		'stored privately for 24 months',
		'Turnstile',
		'Cloudflare',
		'visitor IP',
		'hotlinked',
		'Petstablished',
	) as $term
) {
	check('privacy policy mentions ' . $term, str_contains($policy, $term));
}

equals('disabled analytics no script', '', service_output(array(Analytics::class, 'snippet')));
equals('empty key disables analytics', false, Analytics::enabled());
equals('empty key emits no event script', '', service_output(array(Analytics::class, 'events')));

// Organization data, SEO plugin graphs, public page text and sitemap parity.
service_settings(
	array(
		'org_name' => 'Synthetic Rescue',
		'logo_url' => 'https://example.invalid/logo.svg',
		'social' => array(array('service' => 'Profile', 'url' => 'https://social.example.invalid/rescue')),
		'contact_email' => 'hello@example.test',
		'phone' => '123',
		'country' => 'CA',
		'source' => 'fixture',
		'shelter_id' => 123,
	),
);
Sync::run_listings();
$animal_id = Animal::ps_map()[910001];
$animal_data = Animal::data($animal_id);
$animal_data['photos'] = array(
	array(
		'full' => 'https://example.invalid/synthetic-photo.jpg',
		'thumb' => 'https://example.invalid/synthetic-photo.jpg',
	),
);
update_post_meta($animal_id, Animal::M_DATA, $animal_data);
Animal::flush();
$legacy_rules = Redirects::parse('/legacy /' . Settings::get('archive_slug') . '/');
equals(
	'legacy pet link resolves animal',
	get_permalink($animal_id),
	Redirects::match ('/legacy', array('pet_id' => 910001), $legacy_rules)['target'],
);
equals(
	'unknown legacy pet falls back to archive',
	'/' . Settings::get('archive_slug') . '/',
	Redirects::match ('/legacy', array('pet_id' => 999999999), $legacy_rules)['target'],
);
equals(
	'array pet ID ignored',
	'/' . Settings::get('archive_slug') . '/',
	Redirects::match ('/legacy', array('pet_id' => array(910001)), $legacy_rules)['target'],
);
$old_page = wp_insert_post(
	array(
		'post_type' => 'page',
		'post_title' => 'Old page',
		'post_name' => 'old',
		'post_content' => '<p>Old content.</p>',
	),
);
$public = wp_insert_post(
	array(
		'post_type' => 'page',
		'post_title' => 'Help &amp; adopt',
		'post_name' => 'help',
		'post_content' => '<p class="is-style-eyebrow">Small heading</p><p>First paragraph.</p>'
			. '<p class="homeward-page-header__lead">A clear &amp; helpful introduction.</p>',
	),
);
$private = wp_insert_post(
	array('post_type' => 'page', 'post_title' => 'Private page', 'post_status' => 'private'),
);
$protected = wp_insert_post(
	array('post_type' => 'page', 'post_title' => 'Protected page', 'post_password' => 'synthetic'),
);
$draft = wp_insert_post(array('post_type' => 'page', 'post_title' => 'Draft page', 'post_status' => 'draft'));
$org = Seo::organization();
equals('schema name from settings', 'Synthetic Rescue', $org['name']);
equals('schema logo from settings', 'https://example.invalid/logo.svg', $org['logo']['url']);
equals('schema social from settings', array('https://social.example.invalid/rescue'), $org['sameAs']);
equals(
	'page descriptions favor lead paragraph',
	'A clear & helpful introduction.',
	Seo::page_description(get_post($public)->post_content),
);
$GLOBALS['current_id'] = $public;
equals(
	'page plugin description generated',
	'A clear & helpful introduction.',
	Seo::plugin_description('automatic'),
);
update_post_meta($public, 'rank_math_description', 'Editor description');
equals(
	'custom Rank Math description retained',
	'Editor description',
	Seo::plugin_description('Editor description'),
);
delete_post_meta($public, 'rank_math_description');
update_post_meta($public, '_yoast_wpseo_metadesc', 'Editor description');
equals(
	'custom Yoast description retained',
	'Editor description',
	Seo::plugin_description('Editor description'),
);
$graph = Seo::plugin_schema(
	array(
		'org' => array('@type' => 'Organization', '@id' => '#existing-org', 'name' => 'Old'),
		'site' => array('@type' => 'WebSite', 'publisher' => array('@id' => '#old')),
		'person' => array('@type' => 'Person', '@id' => '#author'),
		'article' => array('@type' => 'Article', '@id' => '#article'),
		'page' => array(
			'@type' => 'WebPage',
			'@id' => '#existing-page',
			'author' => array('@id' => '#author'),
			'mainEntity' => array('@id' => '#article'),
		),
	),
);
equals('organization identifier retained', '#existing-org', $graph['org']['@id']);
equals(
	'website publisher references merged org',
	array('@id' => '#existing-org'),
	$graph['site']['publisher'],
);
check(
	'nonarticle author and article nodes removed',
	!isset($graph['person']) && !isset($graph['article']),
);
check(
	'removed schema references removed',
	!isset($graph['page']['author']) && !isset($graph['page']['mainEntity']),
);
equals('page identifier retained', '#existing-page', $graph['page']['@id']);
equals(
	'Yoast graph is indexed',
	range(0, count(Seo::yoast_schema(array())) - 1),
	array_keys(Seo::yoast_schema(array())),
);
$GLOBALS['current_id'] = $animal_id;
$animal_graph = Seo::plugin_schema(
	array(
		'page' => array(
			'@type' => 'WebPage',
			'@id' => '#animal',
			'isPartOf' => array('@id' => '#custom-site'),
		),
	),
);
equals('animal graph keeps page identifier', '#animal', $animal_graph['page']['@id']);
equals(
	'animal graph keeps website reference',
	array('@id' => '#custom-site'),
	$animal_graph['page']['isPartOf'],
);
equals(
	'animal graph has about data',
	Animal::get($animal_id, false)['name'],
	$animal_graph['page']['about']['name'],
);
check('animal graph has photo data', !empty($animal_graph['page']['about']['image']));
check(
	'correct Rank Math image hook registered',
	isset($GLOBALS['hooks']['rank_math/opengraph/facebook/image']),
);
add_filter('homeward_seo_plugin_active', fn() => true);
equals('SEO plugin owns head tags', '', service_output(array(Seo::class, 'head')));
$GLOBALS['hooks']['homeward_seo_plugin_active'] = array();
$head = service_output(array(Seo::class, 'head'));
check(
	'standalone head has schema and meta',
	str_contains(
		$head,
		'application/ld+json',
	) && str_contains($head, 'og:image') && str_contains($head, 'name="description"'),
);
$GLOBALS['current_id'] = 0;
$GLOBALS['archive'] = Animal_Post_Type::TYPE;
check(
	'animal archive description includes org',
	str_contains(Seo::plugin_description('old'), 'Synthetic Rescue'),
);
$archive = Seo::plugin_schema(array())['homeward_animals'];
equals('archive schema counts visible animals', count(Animal::all_listed()), $archive['numberOfItems']);
check(
	'archive item positions sequential',
	array_column($archive['itemListElement'], 'position') === range(1, $archive['numberOfItems']),
);
$GLOBALS['archive'] = Content_Post_Types::STORY;
check('story archive meta', str_contains(Seo::plugin_description('old'), 'Adoption stories'));
$GLOBALS['archive'] = Content_Post_Types::EVENT;
check('event archive meta', str_contains(Seo::plugin_description('old'), 'Events and fundraisers'));
$GLOBALS['archive'] = false;
update_option('rank-math-options-sitemap', array('unrelated' => 'kept'));
Seo::enable_sitemaps();
foreach (array(Animal_Post_Type::TYPE, Content_Post_Types::STORY, Content_Post_Types::EVENT) as $type) {
	equals(
		'Rank Math sitemap enabled ' . $type,
		'on',
		get_option('rank-math-options-sitemap')['pt_' . $type . '_sitemap'],
	);
	equals('Rank Math post type included ' . $type, false, Seo::exclude_post_type(true, $type));
}
equals('Rank Math other option preserved', 'kept', get_option('rank-math-options-sitemap')['unrelated']);
equals('unknown sitemap type untouched', true, Seo::exclude_post_type(true, 'other'));
service_settings(array('redirects' => '/old /new'));
check(
	'redirected page excluded from plugin sitemap',
	false === Seo::sitemap_entry(array('loc' => 'old'), 'post', get_post($old_page)),
);
check(
	'redirected page excluded from core sitemap',
	in_array($old_page, Seo::sitemap_args(array(), 'page')['post__not_in'], true),
);
check('existing sitemap exclusions retained', in_array(999, Seo::sitemap_exclusions('999'), true));
update_post_meta($animal_id, Animal::M_LISTED, '0');
check(
	'hidden animal excluded from plugin sitemap',
	in_array($animal_id, Seo::sitemap_exclusions(array()), true),
);
equals(
	'hidden legacy pet falls back to archive',
	'/' . Settings::get('archive_slug') . '/',
	Redirects::match ('/legacy', array('pet_id' => 910001), $legacy_rules)['target'],
);
Animal::flush();
$GLOBALS['current_id'] = $public;
$alt = Seo::image_alt(
	'<figure><img class="wp-image-91" alt=""><span aria-hidden="true"><img alt=""></span>'
		. '<img role="presentation" alt=""><img alt="Existing"></figure>',
	array('blockName' => 'core/image', 'attrs' => array('id' => 91)),
);
check(
	'image alt fallback uses configured noun',
	str_contains($alt, 'alt="animal, Help &amp; adopt page"'),
);
check('hidden image alt preserved', str_contains($alt, 'aria-hidden="true"><img alt=""'));
check('decorative image alt preserved', str_contains($alt, 'role="presentation" alt=""'));
check('existing image alt retained', str_contains($alt, 'alt="Existing"'));
update_post_meta(91, '_wp_attachment_image_alt', 'Known image description');
check(
	'attachment alt used',
	str_contains(
		Seo::image_alt('<img alt="">', array('blockName' => 'core/image', 'attrs' => array('id' => 91))),
		'Known image description',
	),
);

// The practice-content removal is available as a single, separate admin form.
update_option(Homeward\Demo_Content::OPTION, array('created' => array()));
$practice_form = service_output(array(Settings_Page::class, 'screen'));
check(
	'practice removal button is visible',
	str_contains($practice_form, 'Remove practice content') && str_contains(
		$practice_form,
		'value="homeward_remove_demo"',
	),
);
check(
	'practice removal handler registered',
	!empty($GLOBALS['hooks']['admin_post_homeward_remove_demo']),
);
delete_option(Homeward\Demo_Content::OPTION);
// llms document and settings form reflect configured content, never private pages.
service_settings(
	array(
		'archive_slug' => 'companions',
		'noun_plural' => 'companions',
		'llms_pages' => array($public, $private, $protected, $draft, $animal_id, $old_page, $public),
	),
);
equals('llms page IDs normalized', 6, count(Settings::get('llms_pages')));
$guide = Llms_Txt::text();
check('llms organization configured', str_starts_with($guide, '# Synthetic Rescue'));
check('llms archive configured', str_contains($guide, 'https://example.invalid/companions/'));
check('llms published page included', str_contains($guide, 'Help & adopt'));
foreach (array('Private page', 'Protected page', 'Draft page', 'Old page') as $label) {
	check('llms excludes ' . $label, !str_contains($guide, $label));
}
check('llms core sitemap link', str_contains($guide, '/wp-sitemap.xml'));
$_SERVER['REQUEST_URI'] = '/llms.txt?source=test';
equals('llms exact request', true, Llms_Txt::is_request());
$_SERVER['REQUEST_URI'] = '/other/llms.txt';
equals('llms unrelated request', false, Llms_Txt::is_request());
$GLOBALS['home_url'] = 'https://example.invalid/rescue';
$_SERVER['REQUEST_URI'] = '/rescue/llms.txt';
equals('llms subdirectory request', true, Llms_Txt::is_request());
unset($GLOBALS['home_url']);
service_settings(array('llms_txt' => false));
$_SERVER['REQUEST_URI'] = '/llms.txt';
equals('llms setting disables route', false, Llms_Txt::is_request());
$form = service_output(array(Settings_Page::class, 'screen'));
check('redirect setting is multiline', str_contains($form, 'rows="5" id="homeward-redirects"'));
foreach (
	array(
		'email_guard',
		'llms_txt',
		'posthog_key',
		'posthog_region',
		'posthog_host',
		'logo_url',
		'llms_pages',
	) as $key
) {
	check('settings field ' . $key, str_contains($form, 'id="homeward-' . $key . '"'));
}
check('settings helper explains opt-in', str_contains($form, 'Leave blank to keep statistics off'));
check(
	'settings region choices rendered',
	str_contains($form, 'United States') && str_contains($form, 'European Union'),
);
check('advanced custom host labelled', str_contains($form, 'Advanced: custom host'));
check(
	'custom host is rendered for editing',
	str_contains($form, 'value="https://analytics.example.invalid/ingest"'),
);
service_settings(array('posthog_region' => 'eu', 'posthog_host' => ''));
$form = service_output(array(Settings_Page::class, 'screen'));
check(
	'cloud host leaves advanced field blank',
	str_contains($form, 'id="homeward-posthog_host" name="homeward_settings[posthog_host]" value=""'),
);
service_settings(array('posthog_key' => 'phc_' . str_repeat('Z', 20), 'email_guard' => true));
define('REST_REQUEST', true);
equals('REST response not tracked', false, Analytics::enabled());
equals('REST response email unmodified', $html, Email_Guard::render($html));

summary('site service');
