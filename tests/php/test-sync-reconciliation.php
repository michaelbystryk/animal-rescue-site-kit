<?php

require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Animal, Settings, Site_Health, Source, Sync, Sync_Lock};

class Reconciliation_Source implements Source {
	public function __construct(public $rows) {}

	public function label() {
		return 'Offline reconciliation';
	}

	public function fetch_all() {
		return $this->rows;
	}

	public function fetch_detail($id) {
		return new WP_Error('offline', 'No details');
	}
}

function reconcile_seed() {
	setup_reset();
	update_option(
		'homeward_settings',
		array('source' => 'fixture', 'shelter_id' => 0, 'species' => array('dog')),
	);
	$rows = array();
	for ($n = 1; $n <= 4; $n++) {
		$rows[] = array(
			'id' => 930000 + $n,
			'name' => 'Sample ' . $n,
			'animal' => 'Dog',
			'status' => 'Available',
		);
	}
	check('seed succeeds', Sync::run_listings(new Reconciliation_Source($rows))['ok']);
	return $rows;
}

function reconcile_run($rows) {
	return Sync::run_listings(new Reconciliation_Source($rows));
}

$rows = reconcile_seed();
$map = Animal::ps_map();
$before = serialize($GLOBALS['posts']);
$result = reconcile_run(array());
equals('empty feed held', false, $result['ok']);
equals('first drop makes no post changes', $before, serialize($GLOBALS['posts']));
equals('rejected run records received', 0, $result['counts']['received']);
equals('rejected run records available', 4, $result['counts']['available']);
equals('rejected run records leaving', 4, $result['counts']['leaving']);
equals('rejected run records missing', 4, $result['counts']['missing']);
equals('safety runs do not trigger backoff', 0, Sync::paused_until());
Sync::init();
check('notice hook registered', has_action('admin_notices', array(Sync::class, 'notice')));
check(
	'confirmation route registered',
	has_action('admin_post_homeward_confirm_sync', array(Sync::class, 'confirm_action')),
);
ob_start();
Sync::notice();
$notice = ob_get_clean();
check(
	'notice displays numbers and button',
	str_contains($notice, 'received 0') && str_contains($notice, 'available 4') && str_contains(
		$notice,
		'Confirm and apply',
	) && str_contains($notice, '_wpnonce'),
);
$health = Site_Health::result();
equals('pending health warning', 'recommended', $health['status']);
check('health numbers', str_contains($health['description'], 'missing 4'));
check('health confirmation button', str_contains($health['actions'], 'Confirm and apply'));
$p = Sync::pending();
$GLOBALS['can'] = false;
check('confirmation needs capability', is_wp_error(Sync::apply_pending($p['token'], 'valid')));
equals('unauthorized form absent', '', Sync::confirm_form());
$GLOBALS['can'] = true;
check('confirmation needs nonce', is_wp_error(Sync::apply_pending($p['token'], 'bad')));
check('confirmation needs current token', is_wp_error(Sync::apply_pending('stale', 'valid')));
$lock = Sync_Lock::acquire();
check('confirmation respects lock', is_wp_error(Sync::apply_pending($p['token'], 'valid')));
Sync_Lock::release($lock);
check('confirmed empty snapshot applied', Sync::apply_pending($p['token'], 'valid')['ok']);
foreach ($map as $id) {
	equals('confirmed missing animal gone', 'gone', get_post_meta($id, Animal::M_STATUS));
}
equals('pending snapshot cleared', array(), Sync::pending());
check('confirmation cannot replay', is_wp_error(Sync::apply_pending($p['token'], 'valid')));
// Repeated empty and large status drops converge at the configured threshold.
foreach (array('empty', 'partial', 'adopted') as $mode) {
	$rows = reconcile_seed();
	$next = 'empty' === $mode ? array() :('partial' === $mode ? array_slice($rows, 0, 1) : array_map(function ($r) {
		$r['status'] = 'Adopted';
		return $r;
	}, $rows));
	equals('first ' . $mode . ' held', false, reconcile_run($next)['ok']);
	equals('second ' . $mode . ' held', false, reconcile_run(array_reverse($next))['ok']);
	check('third ' . $mode . ' auto applied', reconcile_run($next)['ok']);
	foreach (Animal::ps_map() as $ps => $id) {
		if ('partial' !== $mode || $ps !== 930001) {
			equals('drop reconciles ' . $mode, 'gone', get_post_meta($id, Animal::M_STATUS));
		}
	}
	equals('no safety backoff', 0, Sync::paused_until());
}
$rows = reconcile_seed();
add_filter('homeward_sync_drop_confirm_runs', fn() => 2);
reconcile_run(array());
check('filter changes threshold', reconcile_run(array())['ok']);
// Changing feeds reset matching-drop runs, but cannot strand a repeatedly missing animal.
$rows = reconcile_seed();
$id = Animal::ps_map()[930004];
reconcile_run(array($rows[0]));
reconcile_run(array($rows[1]));
$r = reconcile_run(array($rows[2]));
equals('different snapshots still held', false, $r['ok']);
equals('matching streak reset', 1, Sync::pending()['runs']);
equals('missing bound enforced', 'gone', get_post_meta($id, Animal::M_STATUS));
$rows = reconcile_seed();
reconcile_run(array());
reconcile_run($rows);
equals('healthy run clears review', array(), Sync::pending());
reconcile_run(array());
equals('healthy run resets streak', 1, Sync::pending()['runs']);
$rows = reconcile_seed();
reconcile_run(array());
reconcile_run(new WP_Error('offline', 'fail'));
check('unresolved review survives failed fetch', (bool) Sync::pending());
reconcile_run(array());
equals('request failure interrupts streak', 1, Sync::pending()['runs']);
$rows = reconcile_seed();
reconcile_run(array());
$p = Sync::pending();
update_option(
	'homeward_settings',
	array('source' => 'fixture', 'shelter_id' => 0, 'species' => array('dog', 'cat')),
);
check(
	'changed config invalidates confirmation',
	is_wp_error(Sync::apply_pending($p['token'], 'valid')),
);
// Saved review state contains no settings secrets.
$rows = reconcile_seed();
update_option(
	'homeward_settings',
	array('source' => 'fixture', 'shelter_id' => 0, 'turnstile_secret' => 'PRIVATE_CONFIG_VALUE'),
);
reconcile_run(array());
check(
	'pending stores configuration hash only',
	!str_contains(json_encode(get_option(Sync::OPT_PENDING)), 'PRIVATE_CONFIG_VALUE'),
);
check(
	'missing counters store configuration hash only',
	!str_contains(json_encode(get_option(Sync::OPT_MISSING)), 'PRIVATE_CONFIG_VALUE'),
);
summary('sync reconciliation');
