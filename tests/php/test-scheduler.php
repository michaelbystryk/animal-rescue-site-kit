<?php

require __DIR__ . '/lib/bootstrap.php';
$GLOBALS['as_pending'] = array();
$GLOBALS['as_running'] = array();
$GLOBALS['as_calls'] = array();

function as_next_scheduled_action($hook, $args, $group) {
	return $GLOBALS['as_pending'][$hook] ?? (!empty($GLOBALS['as_running'][$hook]) ? true : false);
}

function as_schedule_single_action($when, $hook, $args, $group, $unique) {
	$GLOBALS['as_calls'][] = array($when, $hook, $unique);
	if ($unique && !empty($GLOBALS['as_running'][$hook])) {
		return 0;
	}
	$GLOBALS['as_pending'][$hook] = $when;
	return count($GLOBALS['as_calls']);
}

function as_unschedule_all_actions($hook, $args, $group) {
	unset($GLOBALS['as_pending'][$hook]);
}

Homeward\Scheduler::ensure();
$before = $GLOBALS['as_pending'];
Homeward\Scheduler::ensure();
check('a second ensure() schedules nothing new', $before === $GLOBALS['as_pending']);
check('ensure() schedules the three recurring hooks', count($before) === 3);
check('Action Scheduler replaces WP-Cron events', count($GLOBALS['cron']) === 0);
$hook = 'homeward_sync_listings';
unset($GLOBALS['as_pending'][$hook]);
$GLOBALS['as_running'][$hook] = true;
Homeward\Scheduler::once($hook, time() + 3600);
check('once() schedules while no run is in flight', isset($GLOBALS['as_pending'][$hook]));
check(
	'once() does not request a unique action while a run is in flight',
	false === $GLOBALS['as_calls'][count($GLOBALS['as_calls']) - 1][2],
);
Homeward\Scheduler::clear();
check('clear() removes every pending action', array() === $GLOBALS['as_pending']);
summary('scheduler');
