<?php

/**
 * Shared assertions for every PHP suite.
 *
 * check() asserts a condition, equals() asserts strict equality, contains() asserts a substring.
 * Failures are printed to STDERR with their label and counted; summary() prints one line per suite
 * and exits non-zero if anything failed, so bin/test.sh can rely on the exit status alone.
 */

if (!isset($GLOBALS['test_results'])) {
	$GLOBALS['test_results'] = array('passed' => 0, 'failed' => 0);
}

/** Assert that a condition is truthy. The label names the behaviour being protected. */
function check($label, $condition) {
	if ($condition) {
		++$GLOBALS['test_results']['passed'];
		return;
	}
	++$GLOBALS['test_results']['failed'];
	fwrite(STDERR, 'FAIL: ' . $label . "\n");
}

/** Assert that two values are identical (===). Reports both values on failure. */
function equals($label, $expected, $actual) {
	if ($expected === $actual) {
		++$GLOBALS['test_results']['passed'];
		return;
	}
	++$GLOBALS['test_results']['failed'];
	fwrite(
		STDERR,
		'FAIL: ' . $label . "\n    expected: " . substr(var_export($expected, true), 0, 300)
			. "\n    actual:   " . substr(var_export($actual, true), 0, 300) . "\n",
	);
}

/** Assert that a string contains a substring. */
function contains($label, $haystack, $needle) {
	check($label, is_string($haystack) && str_contains($haystack, (string) $needle));
}

/** Print the suite result and exit non-zero if any assertion failed. */
function summary($suite) {
	$passed = $GLOBALS['test_results']['passed'];
	$failed = $GLOBALS['test_results']['failed'];
	if ($failed > 0) {
		echo "FAILED: $failed of " . ($passed + $failed) . " $suite checks failed.\n";
		exit(1);
	}
	echo "$passed $suite checks passed.\n";
}
