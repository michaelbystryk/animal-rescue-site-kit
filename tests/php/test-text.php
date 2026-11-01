<?php

/** Run with the non-polyfilled mbstring functions disabled. */
require __DIR__ . '/lib/bootstrap.php';
use Homeward\{Text, Pet_Data, Description};
check('mb_strtoupper is disabled for this run', !function_exists('mb_strtoupper'));
check('mb_strtolower is disabled for this run', !function_exists('mb_strtolower'));
check('mb_strrpos is disabled for this run', !function_exists('mb_strrpos'));
check('title case works without mbstring', 'Hello' === Pet_Data::title_case('HELLO'));
check('lower-casing works without mbstring', 'hello' === Text::lower('HELLO'));
check('upper-casing works without mbstring', 'HELLO' === Text::upper('hello'));
check('last position counts characters, not bytes', 4 === Text::last_position('café au', ' '));
check('last position reports a missing needle', false === Text::last_position('café', ' '));
check('excerpt keeps multibyte text intact', 'café…' === Description::excerpt('café au lait', 6));
check('description cleaning returns a string', is_string(Description::clean('<p>A FRIENDLY ANIMAL LOVES WALKS.</p>')));
summary('mbstring fallback');
