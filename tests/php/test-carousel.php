<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Sync, Source};

class Carousel_Source implements Source {
	public function __construct(public $rows) {}

	public function label() {
		return 'Offline carousel';
	}

	public function fetch_all() {
		return $this->rows;
	}

	public function fetch_detail($id) {
		return new WP_Error('offline', 'No details');
	}
}

// Seed $count available dogs, then mark the given 1-based positions featured.
function carousel_seed($count, $featured) {
	setup_reset();
	update_option('homeward_settings', array('source' => 'fixture', 'shelter_id' => 0, 'species' => array('dog')));
	$rows = array();
	for ($n = 1; $n <= $count; $n++) {
		$rows[] = array('id' => 950000 + $n, 'name' => 'Sample ' . $n, 'animal' => 'Dog', 'status' => 'Available');
	}
	check('carousel seed succeeds', Sync::run_listings(new Carousel_Source($rows))['ok']);
	$map = Animal::ps_map();
	foreach ($featured as $n) {
		update_post_meta($map[950000 + $n], Animal::M_FEATURED, 1);
	}
	Animal::flush();
	return $map;
}

function carousel_ids($limit) {
	return array_column(Animal::carousel($limit), 'id');
}

// One featured animal leads, and the rest of the list is filled with others up to the limit.
$map = carousel_seed(6, array(4));
$ids = carousel_ids(4);
equals('carousel is filled to the limit', 4, count($ids));
equals('featured animal comes first', $map[950004], $ids[0]);
equals('carousel has no repeats', count($ids), count(array_unique($ids)));

// Fewer animals than the limit returns them all, once each.
$ids = carousel_ids(20);
equals('short roster returns every animal', 6, count($ids));
equals('short roster has no repeats', 6, count(array_unique($ids)));
equals('featured still first in a short roster', $map[950004], $ids[0]);

// Featured animals at or above the limit are the whole carousel.
$map = carousel_seed(6, array(1, 2, 3));
$ids = carousel_ids(3);
equals('only featured when featured fill the limit', 3, count($ids));
equals('every carousel entry is featured', array($map[950001], $map[950002], $map[950003]), array_values(array_intersect(array($map[950001], $map[950002], $map[950003]), $ids)));
$ids = carousel_ids(2);
equals('featured are capped at the limit', 2, count($ids));

// No featured animals: just the newest, up to the limit.
carousel_seed(6, array());
equals('no featured falls back to the newest', 3, count(carousel_ids(3)));

summary('carousel');
