<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Animal_Post_Type, Sync};
Animal_Post_Type::register();
equals(
	'staff can create manual animals',
	'edit_homeward_animals',
	$GLOBALS['registered']['types']['homeward_animal']['capabilities']['create_posts'],
);
$id = wp_insert_post(array('post_type' => 'homeward_animal', 'post_title' => 'Sample animal'));
ob_start();
Animal_Post_Type::box(get_post($id));
$html = ob_get_clean();
foreach (array_keys(Animal_Post_Type::manual_fields()) as $field) {
	check('manual form field ' . $field, str_contains($html, 'homeward_manual[' . $field . ']'));
}
$input = array(
	'status' => 'available',
	'species' => 'dog',
	'sex' => 'Female',
	'age_group' => 'Adult',
	'size' => 'Medium',
	'primary_breed' => 'Sample breed',
	'adoption_fee' => '150',
	'ok_dogs' => 'yes',
	'ok_cats' => 'no',
	'ok_kids' => '',
	'housebroken' => 'yes',
	'description' => '<p>A profile written by staff.</p>',
);
$_POST = array('homeward_animal_nonce' => 'bad', 'homeward_manual' => $input);
Animal_Post_Type::save($id);
equals('nonce required', array(), Animal::data($id));
$_POST['homeward_animal_nonce'] = 'valid';
$GLOBALS['can'] = false;
Animal_Post_Type::save($id);
equals('capability required', array(), Animal::data($id));
$GLOBALS['can'] = array('homeward_edit_overrides', 'edit_post');
// The save action may fire recursively when the description is saved.
add_action('save_post_homeward_animal', array(Animal_Post_Type::class, 'save'));
Animal_Post_Type::save($id);
$animal = Animal::get($id);
equals('manual source marker', 'manual', get_post_meta($id, Animal::M_ADAPTER));
equals('manual description saved', $input['description'], get_post($id)->post_content);
foreach (
	array(
		'status' => 'available',
		'species' => 'dog',
		'sex' => 'Female',
		'age_group' => 'Adult',
		'size' => 'Medium',
		'breed' => 'Sample breed',
		'fee' => '150',
		'ok_dogs' => true,
		'ok_cats' => false,
		'ok_kids' => null,
		'housebroken' => true,
	) as $key => $value
) {
	equals('profile ' . $key, $value, $animal[$key]);
}
check('manual animal listed', $animal['listed']);
set_post_thumbnail($id, 55);
check('manual featured image displayed', str_contains(Animal::get($id)['thumb'], '55'));
foreach (array('pending', 'gone', 'available') as $status) {
	$_POST['homeward_manual']['status'] = $status;
	Animal_Post_Type::save($id);
	equals('staff sets ' . $status, $status, get_post_meta($id, Animal::M_STATUS));
	equals('adopted hidden', 'gone' !== $status, '1' === get_post_meta($id, Animal::M_LISTED));
}
// A forged profile submission cannot change a synced animal.
$_POST = array();
$GLOBALS['can'] = true;
update_option('homeward_settings', array('source' => 'fixture', 'shelter_id' => 0));
Sync::run_listings();
$synced = array_values(Animal::ps_map())[0];
$before = serialize(get_post($synced));
$profile_snapshot_meta = $GLOBALS['meta'][$synced];
$_POST = array('homeward_animal_nonce' => 'valid', 'homeward_manual' => $input);
Animal_Post_Type::save($synced);
equals('synced content has no manual edit', $before, serialize(get_post($synced)));
equals('synced source profile unchanged', $profile_snapshot_meta[Animal::M_DATA], Animal::data($synced));
ob_start();
Animal_Post_Type::box(get_post($synced));
check('synced form excludes manual profile', !str_contains(ob_get_clean(), 'homeward_manual['));
$_POST = array();
$before = serialize(get_post($id));
$profile_snapshot_meta = $GLOBALS['meta'][$id];
Sync::run_now();
Sync::daily_refresh();
Sync::process_details();
Sync::cleanup();
equals('sync preserves manual post', $before, serialize(get_post($id)));
equals('sync preserves manual metadata', $profile_snapshot_meta, $GLOBALS['meta'][$id]);
check('manual absent from source map', !in_array($id, Animal::ps_map(), true));
// Retention and old numeric IDs cannot claim a manual record.
update_post_meta($id, Animal::M_PS_ID, 910001);
update_post_meta($id, Animal::M_STATUS, 'gone');
update_post_meta($id, Animal::M_GONE, time() - 100 * DAY_IN_SECONDS);
update_option(
	'homeward_settings',
	array('source' => 'fixture', 'shelter_id' => 0, 'gone_retention_days' => 1),
);
Sync::cleanup();
equals('manual adopted post never retired', 'publish', get_post_status($id));
check('manual marker wins over legacy ID', !in_array($id, Animal::ps_map(), true));
summary('manual animals');
