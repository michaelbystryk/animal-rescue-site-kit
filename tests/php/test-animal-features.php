<?php

/** Offline front-end regressions using synthetic provider records. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Animal_Post_Type, Blocks, Pet_Data, Render, Settings};
set_error_handler(function ($severity, $message, $file, $line) {
	if (error_reporting() & $severity) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	return false;
});

function feature_settings($values) {
	update_option('homeward_settings', Settings::sanitize($values));
	Animal::flush();
}

function feature_animal($raw) {
	$data = Pet_Data::sanitize_pet(
		array_merge(array('name' => 'Synthetic pet', 'animal' => 'dog', 'status' => 'Available'), $raw),
	);
	$id = wp_insert_post(
		array(
			'post_type' => Animal_Post_Type::TYPE,
			'post_status' => 'publish',
			'post_title' => $data['raw_name'],
			'post_content' => $data['description'] ?? '',
		),
	);
	update_post_meta($id, Animal::M_DATA, $data);
	update_post_meta($id, Animal::M_STATUS, 'available');
	Animal::refresh_derived($id);
	return Animal::get($id);
}

$families = array(
	'Jindo' => array('Jindo', 'Jindo Mix', 'Jindos (All Types)'),
	'Husky' => array('Husky', 'Siberian Husky', 'Siberian', 'Malamute', 'Malamutes Mix'),
	'Shepherd' => array(
		'Shepherds Mix',
		'German Shepherd Dog',
		'Malinois',
		'Anatolian',
		'Kangal',
		'Shepherds (All Types)',
	),
	'Retriever' => array('Golden', 'Labrador', 'Lab', 'Labrador Retriever Mix'),
	'Collie and herding' => array(
		'Collie',
		'Sheltie',
		'Aussie',
		'Corgi',
		'Collies Mix',
		'Catahoula Leopard Dog Mix',
		'Heeler',
		'Australian Cattle Dog',
	),
	'Bully breeds' => array(
		'Pit Bull',
		'Pit Bull Terrier',
		'Pit-Bull Mix',
		'Staffordshire',
		'Staffordshire Bull Terrier Mix',
		'American Staffordshire Terrier',
		'Bull Terrier',
		'American Bully',
		'Boxer',
		'Bulldog',
		'Shar-Pei Mix',
		'Mastiff',
		'Cane Corso',
	),
	'Terrier' => array(
		'Terriers',
		'Terrier Mix',
		'Jack Russell',
		'Jack Russell Terrier Mix',
		'Parson Russell Terrier',
		'Border Terrier',
	),
	'Spitz' => array('Spitz', 'Shiba', 'Pomeranian', 'Samoyed', 'Akita'),
	'Hound' => array(
		'Hound',
		'Beagle',
		'Coonhound',
		'Whippet',
		'Greyhound',
		'Dachshund',
		'Ibizan',
		'Saluki Mix',
		'Podenco',
		'Galgo',
	),
	'Pointer' => array(
		'English Pointer',
		'Vizsla Mix',
		'German Shorthaired Pointer Mix',
		'Weimaraner',
		'Setter',
		'Wirehaired Pointer',
	),
	'Spaniel' => array('Spaniel', 'Spaniels Mix'),
	'Guardian and giant' => array(
		'Great Pyrenees',
		'Great Pyrenees Mix',
		'Newfoundland Mix',
		'Rottweiler Mix',
		'Bernese Mountain Dog',
		'Saint Bernard',
		'St. Bernard',
		'Leonberger',
		'Great Dane',
	),
	'Small breeds' => array('Chihuahua', 'Papillon', 'Poodle', 'Maltese', 'Shih Tzu', 'Yorkie'),
	'Other' => array('', 'Unknown', 'Mixed Breed', 'Dalmatian', 'Mix Types', 'Slab'),
);
foreach ($families as $family => $examples) {
	foreach ($examples as $breed) {
		check('breed family ' . $breed, $family === Pet_Data::breed_family($breed));
		check(
			'case and spacing ' . $breed,
			$family === Pet_Data::breed_family('  ' . strtoupper($breed) . '  '),
		);
	}
}
$ordered = array_keys($families);
foreach ($ordered as $i => $family) {
	foreach (array_slice($ordered, $i + 1, -1) as $later) {
		check(
			'first family wins ' . $family . '/' . $later,
			$family === Pet_Data::breed_family($families[$later][0] . ' / ' . $families[$family][0]),
		);
	}
}
$bully = feature_animal(array('primary_breed' => 'Staffordshire Bull Terrier', 'secondary_breed' => 'Jindo'));
$terrier = feature_animal(array('primary_breed' => 'Jack Russell Terrier'));
$other = feature_animal(array('secondary_breed' => 'Jindo'));
check(
	'family uses only primary breed',
	'Bully breeds' === $bully['breed_family'] && 'Other' === $other['breed_family'],
);
$html = Render::listing(array($bully, $terrier), array('filters' => true, 'courtesy' => array($other)));
check('dog-only filter defaults on', str_contains($html, 'name="breed"'));
preg_match('/<details[^>]*>(.*?)<\/details>/s', $html, $details);
check(
	'breed is an advanced filter with mix hint',
	str_contains($details[1], 'name="breed"') && str_contains($details[1], 'Includes mixes'),
);
check(
	'separate bully and terrier options and courtesy family',
	str_contains(
		$html,
		'<option value="bully-breeds">Bully breeds (1)</option>',
	) && str_contains(
		$html,
		'<option value="terrier">Terrier (1)</option>',
	) && str_contains($html, '<option value="other">Other (1)</option>'),
);
preg_match('/data-homeward-animals-json>(.*?)<\/script>/s', $html, $json);
$rows = json_decode($json[1], true, 512, JSON_THROW_ON_ERROR);
check(
	'filter JSON matches family slugs',
	array('bully-breeds', 'terrier', 'other') === array_column($rows, 'breed'),
);
check(
	'fewer than two families hide filter',
	!str_contains(Render::listing(array($bully, $bully), array('filters' => true)), 'name="breed"'),
);
check(
	'block can hide filter',
	!str_contains(Blocks::render('animal-grid', array('breedFilter' => false)), 'name="breed"'),
);
check('block shows filter by default', str_contains(Blocks::render('animal-grid'), 'name="breed"'));
feature_settings(array('species' => array('dog', 'cat')));
check(
	'mixed species configuration allows dog breed filter',
	str_contains(Blocks::render('animal-grid', array('species' => 'dog')), 'name="breed"'),
);
feature_settings(array('species' => array('cat')));
check(
	'cat configuration hides filter',
	!str_contains(Render::listing(array($bully, $terrier), array('filters' => true)), 'name="breed"'),
);
feature_settings(array('species' => array('dog'), 'public_fields' => array()));
check('breed visibility hides family data', '' === Animal::get($bully['id'])['breed_family']);
check(
	'breed visibility hides family filter',
	!str_contains(Render::listing(array($bully, $terrier), array('filters' => true)), 'name="breed"'),
);
feature_settings(
	array(
		'public_fields' => Settings::PUBLIC_FIELDS,
		'noun_singular' => 'companion',
		'noun_plural' => 'companions',
	),
);
check(
	'listing counts use configured noun',
	str_contains(Render::listing(array($bully, $terrier), array('filters' => true)), '2 companions'),
);

$chips = new ReflectionMethod(Render::class, 'chips');
$fields = array(
	'is_ok_with_other_dogs' => 'ok_dogs',
	'is_ok_with_other_cats' => 'ok_cats',
	'is_ok_with_other_kids' => 'ok_kids',
);
foreach (
	array(
		'Great with dogs, cats and kids.',
		'Not good with dogs, cats or children.',
		'Dogs: yes. Cats: no. Kids: unknown.',
	) as $description
) {
	$unknown = feature_animal(
		array('description' => $description, 'ok_dogs' => true, 'ok_cats' => false, 'ok_kids' => true),
	);
	foreach ($fields as $raw => $key) {
		check('description and injected internal keys never set ' . $key, null === $unknown[$key]);
	}
	check('unknown compatibility renders nothing', '' === $chips->invoke(null, $unknown));
	foreach (array(true, false, null, 'yes', 'no', 'unknown', '', 1, 0) as $toggle) {
		$expected = in_array($toggle, array(true, 'yes', 1), true) ? true :(
			in_array($toggle, array(false, 'no', 0), true) ? false : null
		);
		$raw = array_fill_keys(array_keys($fields), $toggle);
		$animal = feature_animal(array_merge($raw, array('description' => $description)));
		foreach ($fields as $key) {
			check(
				'only toggle controls ' . $key . ' ' . json_encode($toggle),
				$expected === $animal[$key],
			);
		}
		$markup = $chips->invoke(null, $animal);
		check(
			'tri-state chips reflect toggles',
			null === $expected ? '' === $markup : 3 === substr_count(
				$markup,
				true === $expected ? 'is-yes' : 'is-no',
			),
		);
		$animal['description'] = 'Contradictory narrative does not change chips.';
		check(
			'view model description cannot change chips',
			$markup === $chips->invoke(null, $animal),
		);
	}
}
$preferred = Pet_Data::sanitize_pet(
	array(
		'description' => 'Good with cats and kids.',
		'is_ok_with_other_cats' => true,
		'is_ok_with_cats' => null,
		'is_ok_with_other_kids' => true,
		'is_ok_with_kids' => false,
	),
);
check(
	'preferred toggles preserve explicit unknown and no',
	null === $preferred['ok_cats'] && false === $preferred['ok_kids'],
);
check(
	'share defaults enable supported networks',
	array('facebook', 'x', 'email', 'whatsapp') === Settings::get('share_networks'),
);
check(
	'share settings reject unsupported networks and duplicates',
	array('x', 'email') === Settings::sanitize(
		array('share_networks' => array('evil', 'email', 'x', 'email', array())),
	)['share_networks'],
);
check(
	'share settings allow no networks',
	array() === Settings::sanitize(array('share_networks' => array()))['share_networks'],
);
summary('animal feature');
