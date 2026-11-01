<?php

require __DIR__ . '/lib/bootstrap.php';
$root = dirname(__DIR__, 2);
exec('bash ' . escapeshellarg($root . '/bin/build-zips.sh') . ' 2>&1', $build_output, $build_code);
equals('zip build succeeds', 0, $build_code);
foreach (array('homeward-rescue', 'homeward') as $package) {
	$output = array();
	exec(
		'unzip -p ' . escapeshellarg(
			$root . '/dist/' . $package . '.zip',
		) . ' ' . escapeshellarg($package . '/LICENSE'),
		$output,
		$code,
	);
	equals('license packaged ' . $package, 0, $code);
	equals(
		'complete GPL text ' . $package,
		trim(file_get_contents($root . '/LICENSE')),
		trim(implode("\n", $output)),
	);
}
$output = array();
exec(
	'unzip -p ' . escapeshellarg($root . '/dist/homeward-rescue.zip') . ' homeward-rescue/config.example.json',
	$output,
	$code,
);
equals('example configuration packaged', 0, $code);
equals(
	'packaged configuration matches',
	json_decode(file_get_contents($root . '/config.example.json'), true),
	json_decode(implode("\n", $output), true),
);
summary('packaging');
