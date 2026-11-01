<?php

/** The contact-form block must accept every message topic, or WordPress silently swaps it for "general". */
define('ABSPATH', __DIR__);
require dirname(__DIR__, 2) . '/plugin/homeward-rescue/includes/class-messages.php';
$enum = json_decode(
	file_get_contents(dirname(__DIR__, 2) . '/plugin/homeward-rescue/blocks/contact-form/block.json'),
	true,
)['attributes']['topic']['enum'];
require __DIR__ . '/lib/assert.php';

equals('block.json topic enum matches Messages::TOPICS', \Homeward\Messages::TOPICS, $enum);
check('contact-form block offers at least one topic', count($enum) > 0);
summary('contact topics');
