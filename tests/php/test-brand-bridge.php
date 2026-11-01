<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Brand, Settings};

class Theme_Data_Double {
	public $patch;

	public function __construct(public $data) {}

	public function get_data() {
		return $this->data;
	}

	public function update_with($patch) {
		$this->patch = $patch;
		return $this;
	}
}

$fonts = array();
foreach (Brand::fonts() as $slug => $font) {
	$fonts[] = array(
		'slug' => $slug,
		'fontFamily' => $font['stack'],
		'fontFace' => array(array('src' => 'file:./assets/fonts/' . $slug . '.woff2')),
	);
}
$fonts[] = array('slug' => 'heading', 'fontFamily' => 'old heading');
$fonts[] = array('slug' => 'body', 'fontFamily' => 'old body');
$data = new Theme_Data_Double(
	array(
		'version' => 3,
		'settings' => array(
			'color' => array(
				'palette' => array(
					'theme' => array(
						array('slug' => 'custom', 'color' => '#123456'),
						array('slug' => 'primary', 'color' => '#000000'),
					),
				),
			),
			'typography' => array('fontFamilies' => array('theme' => $fonts)),
		),
		'styles' => array('spacing' => array('blockGap' => '2rem')),
	),
);
Brand::init();
equals('theme data object returned', $data, apply_filters('wp_theme_json_data_theme', $data));
equals('v3 schema', 3, $data->patch['version']);
$colors = array_column($data->patch['settings']['color']['palette'], 'color', 'slug');
foreach (Brand::colors() as $slug => $color) {
	equals('palette ' . $slug, $color, $colors[$slug]);
}
equals('unrelated palette preserved', '#123456', $colors['custom']);
equals('no duplicate palette rows', 6, count($data->patch['settings']['color']['palette']));
$mapped = array_column($data->patch['settings']['typography']['fontFamilies'], null, 'slug');
foreach (array_slice($fonts, 0, 12) as $font) {
	equals('font faces preserved ' . $font['slug'], $font, $mapped[$font['slug']]);
}
equals('brand reads the shared font list', Homeward\Fonts::all(), Brand::fonts());
equals('twelve fonts bridged', 12, count(Brand::fonts()));
foreach (
	array('playfair-display', 'dm-serif-display', 'merriweather', 'figtree', 'work-sans', 'nunito') as $new
) {
	$s = Settings::sanitize(array('font_heading' => $new, 'font_body' => $new));
	equals('sanitize accepts ' . $new, $new, $s['font_heading']);
	equals('sanitize accepts body ' . $new, $new, $s['font_body']);
}
equals(
	'heading defaults to Fraunces',
	Brand::fonts()['fraunces']['stack'],
	$mapped['heading']['fontFamily'],
);
equals('body defaults to Inter', Brand::fonts()['inter']['stack'], $mapped['body']['fontFamily']);
check('no new font face on alias', !isset($mapped['heading']['fontFace']));
check('does not override other theme styles', !isset($data->patch['styles']));
foreach (Brand::contrast_report(Settings::defaults()) as $check) {
	check('default readable ' . $check['text'] . '/' . $check['background'], $check['passes']);
}
equals('black and white contrast', 21.0, Brand::contrast('#000000', '#ffffff'));
equals('short hex supported', Brand::contrast('#ffffff', '#000000'), Brand::contrast('#fff', '#000'));
foreach (array_keys(Brand::fonts()) as $slug) {
	update_option('homeward_settings', array('font_heading' => $slug, 'font_body' => $slug));
	equals('chosen heading ' . $slug, Brand::fonts()[$slug]['stack'], Brand::font_stack('heading'));
	equals('chosen body ' . $slug, Brand::fonts()[$slug]['stack'], Brand::font_stack('body'));
}
update_option('homeward_settings', array('font_pair' => 'classic'));
equals('legacy classic heading', Brand::fonts()['lora']['stack'], Brand::font_stack('heading'));
update_option('homeward_settings', Settings::sanitize(array('org_name' => 'Still classic')));
equals(
	'legacy pair survives unrelated save',
	Brand::fonts()['lora']['stack'],
	Brand::font_stack('heading'),
);
check(
	'unrelated save does not opt in to fonts',
	!array_key_exists('font_heading', get_option('homeward_settings')),
);
update_option('homeward_settings', array('font_pair' => 'system'));
equals('legacy system stack', 'system-ui, sans-serif', Brand::font_stack('body'));
update_option(
	'homeward_settings',
	Settings::sanitize(
		array('font_heading' => 'lora', 'font_body' => 'nunito-sans', 'color_primary' => '#234567'),
	),
);
Brand::filter_theme_json($data);
equals('new choice overrides legacy', Brand::fonts()['lora']['stack'], Brand::font_stack('heading'));
equals(
	'saved palette reflected',
	'#234567',
	array_column($data->patch['settings']['color']['palette'], 'color', 'slug')['primary'],
);
$sanitized = Settings::sanitize(
	array('font_heading' => 'remote-font', 'font_body' => array('inter'), 'color_surface' => '</style>'),
);
equals('invalid heading sanitized', 'fraunces', $sanitized['font_heading']);
equals('invalid body sanitized', 'inter', $sanitized['font_body']);
equals('invalid color sanitized', '', $sanitized['color_surface']);
foreach (array('#1F4E79', '#fff080', '#ffffff', '#111111') as $primary) {
	update_option('homeward_settings', array('color_primary' => $primary));
	preg_match('/--homeward-primary-foreground:(#[a-f0-9]+);/i', Brand::css(), $match);
	check(
		'primary foreground differs from background ' . $primary,
		strtolower($primary) !== strtolower($match[1]),
	);
	check('primary foreground meets AA ' . $primary, Brand::contrast($primary, $match[1]) >= 4.5);
}
summary('brand bridge');
