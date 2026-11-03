<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Pet_Data, Sync, Source};

class Wait_Source implements Source {
	public function __construct(public $rows) {}

	public function label() {
		return 'Offline wait dates';
	}

	public function fetch_all() {
		return $this->rows;
	}

	public function fetch_detail($id) {
		return new WP_Error('offline', 'No details');
	}
}

$day = 86400;
$date = fn($ts) => gmdate('Y-m-d', $ts);

function wait_row($n, $status = 'Available', $extra = array()) {
	return array_merge(
		array('id' => 940000 + $n, 'name' => 'Sample ' . $n, 'animal' => 'Dog', 'status' => $status),
		$extra,
	);
}

function wait_sync($rows) {
	return Sync::run_listings(new Wait_Source($rows))['ok'];
}

function wait_seed($count, $extra = array()) {
	setup_reset();
	update_option('homeward_settings', array('source' => 'fixture', 'shelter_id' => 0, 'species' => array('dog')));
	$rows = array();
	for ($n = 1; $n <= $count; $n++) {
		$rows[] = wait_row($n, 'Available', $extra[$n] ?? array('date_aquired' => gmdate('Y-m-d', time() - 300 * 86400)));
	}
	check('wait seed succeeds', wait_sync($rows));
	return $rows;
}

function wait_id($n) {
	return Animal::ps_map()[940000 + $n];
}

function wait_with_status($rows, $n, $status) {
	$rows[$n - 1]['status'] = $status;
	return $rows;
}

// date_aquired is whitelisted, kept as a date only, and preferred for the wait.
check('date_aquired is whitelisted', in_array('date_aquired', Pet_Data::WHITELIST, true));
$clean = Pet_Data::sanitize_pet(
	array('id' => 1, 'date_aquired' => gmdate('Y-m-d\T05:00:00.000\Z', time() - 90 * $day), 'microchip' => '123', 'rabies_tag' => '456'),
);
check('date_aquired survives sanitising', isset($clean['date_aquired']) && '' !== $clean['date_aquired']);
check('microchip and rabies ids are never kept', !isset($clean['microchip']) && !isset($clean['rabies_tag']));
equals('bad date_aquired is blanked', '', Pet_Data::sanitize_pet(array('id' => 1, 'date_aquired' => 'soon'))['date_aquired']);

$rows = wait_seed(
	3,
	array(
		1 => array('date_aquired' => $date(time() - 120 * $day), 'date_received' => null, 'created_at' => $date(time() - 400 * $day)),
		2 => array('date_aquired' => $date(time() - 50 * $day), 'date_received' => $date(time() - 60 * $day)),
		3 => array('date_received' => $date(time() - 30 * $day), 'created_at' => $date(time() - 90 * $day)),
	),
);
equals('date_aquired beats created_at', strtotime($date(time() - 120 * $day) . ' UTC'), Animal::get(wait_id(1))['waiting_since']);
equals('date_aquired beats date_received', Animal::get(wait_id(2))['intake'], strtotime($date(time() - 50 * $day) . ' UTC'));
equals('date_received still beats created_at', Animal::get(wait_id(3))['intake'], strtotime($date(time() - 30 * $day) . ' UTC'));

// Relisting: first sight, repeats and short blips never count.
$rows = wait_seed(30);
$relisted = fn($n) => (int) get_post_meta(wait_id($n), Animal::M_RELISTED, true);
equals('first sight is not a relisting', 0, array_sum(array_map($relisted, range(1, 5))));
wait_sync($rows);
equals('repeat sync of available animals sets nothing', 0, array_sum(array_map($relisted, range(1, 5))));

// 2 adopted, 3 left the feed, 4 on hold, 5 pending.
$away = wait_with_status(wait_with_status(wait_with_status($rows, 2, 'Adopted'), 4, 'On Hold'), 5, 'Pending');
unset($away[2]);
wait_sync(array_values($away));
equals('adopted animal is gone', 'gone', get_post_meta(wait_id(2), Animal::M_STATUS));
equals('nothing relisted while away', 0, array_sum(array_map($relisted, range(1, 5))));

// 2 and 3 away for five days, 4 on hold, 5 pending then all available again.
foreach (array(2, 3) as $n) {
	update_post_meta(wait_id($n), Animal::M_GONE, time() - 5 * $day);
}
wait_sync($rows);
check('adopted then back after five days is relisted now', abs($relisted(2) - time()) < 5);
check('missing then back after five days is relisted now', abs($relisted(3) - time()) < 5);
check('hold then available counts as relisting', abs($relisted(4) - time()) < 5);
equals('pending then available keeps the original wait', 0, $relisted(5));
$animal = Animal::get(wait_id(2));
equals('a relisted animal waits from its return', $relisted(2), $animal['waiting_since']);
check('a relisted animal is not New', 'new' !== $animal['badge'][1] && $animal['intake'] < $animal['waiting_since']);

// A short absence (a source blip) is not a relisting.
wait_sync(wait_with_status($rows, 1, 'Adopted'));
update_post_meta(wait_id(1), Animal::M_GONE, time() - 3600);
wait_sync($rows);
equals('gone for an hour is not relisted', 0, $relisted(1));
add_filter('homeward_relist_min_days', fn() => 7);
wait_sync(wait_with_status($rows, 1, 'Adopted'));
update_post_meta(wait_id(1), Animal::M_GONE, time() - 5 * $day);
wait_sync($rows);
equals('minimum days away is filterable', 0, $relisted(1));
unset($GLOBALS['hooks']['homeward_relist_min_days']);

// Staying available keeps a stored relisting date.
update_post_meta(wait_id(2), Animal::M_RELISTED, time() - 30 * $day);
wait_sync($rows);
equals('staying available keeps the relisted date', time() - 30 * $day, $relisted(2));

// A genuinely new arrival is still New.
$rows[] = wait_row(31, 'Available', array('date_aquired' => $date(time() - 2 * $day)));
wait_sync($rows);
equals('new arrival has no relisting', 0, $relisted(31));
equals('new arrival is badged New', 'new', Animal::get(wait_id(31))['badge'][1]);

// Implausible waits are ignored for Longest waiting: older than three years never wins it.
$extra = array(
	1 => array('date_aquired' => $date(time() - 8 * 365 * $day)),
	2 => array('date_aquired' => $date(time() - 200 * $day)),
	3 => array('date_aquired' => $date(time() - 100 * $day)),
);
wait_seed(3, $extra);
equals('eight-year wait does not take Longest waiting', 'available', Animal::get(wait_id(1))['badge'][1]);
equals('oldest believable wait takes Longest waiting', 'longest-waiting', Animal::get(wait_id(2))['badge'][1]);
add_filter('homeward_max_waiting_years', fn() => 20);
Animal::flush();
equals('max waiting years is filterable', 'longest-waiting', Animal::get(wait_id(1))['badge'][1]);

// Future dates are rejected outright.
equals('future date_aquired is dropped', '', Pet_Data::sanitize_pet(array('id' => 1, 'date_aquired' => $date(time() + 5 * $day)))['date_aquired']);

summary('wait date');
