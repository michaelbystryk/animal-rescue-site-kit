<?php

/**
 * Read a stylesheet in a compact canonical form so assertions do not depend on how it is laid out.
 * Comments are dropped and the whitespace around braces, colons, semicolons and commas is removed.
 */
function compact_css($css) {
	$css = preg_replace('~/\*.*?\*/~s', '', $css);
	$css = preg_replace('/\s+/', ' ', $css);
	$css = preg_replace('/\s*([{};,])\s*/', '$1', $css);
	return trim(preg_replace('/:\s+/', ':', $css));
}

function read_css($file) {
	return compact_css(file_get_contents($file));
}
