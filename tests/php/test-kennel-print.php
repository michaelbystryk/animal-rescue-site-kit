<?php
/** Print routes, staff access, visible-list selection and escaped card output. */
require __DIR__ . '/lib/bootstrap.php';

use Homeward\{Animal, Animal_Post_Type, Frontend, Kennel_Print, Pet_Data, Settings};

function print_pet( $name, $status = 'publish', $availability = 'available' ) {
	$id = wp_insert_post( array(
		'post_type'    => Animal_Post_Type::TYPE,
		'post_status'  => $status,
		'post_title'   => $name,
		'post_content' => '<p>Kind &amp; curious. Loves walks and quiet evenings.</p>',
	) );
	update_post_meta( $id, Animal::M_DATA, Pet_Data::sanitize_pet( array(
		'name'                    => $name,
		'animal'                  => 'Dog',
		'status'                  => ucfirst( $availability ),
		'age'                     => 'Adult',
		'sex'                     => 'Female',
		'size'                    => 'Medium',
		'primary_breed'           => 'Mixed Breed',
		'adoption_fee'            => '125',
		'is_spayed'               => 'True',
		'shots_up_to_date'         => true,
		'is_ok_with_other_dogs'    => true,
		'photos'                  => array( array( 'main_photo_url' => 'https://petstablished.com/photo.jpg' ) ),
	) ) );
	update_post_meta( $id, Animal::M_STATUS, $availability );
	Animal::refresh_derived( $id );
	return $id;
}

function print_denied( $label, $callback ) {
	try {
		$callback();
		check( $label, false );
	} catch ( RuntimeException $e ) {
		check( $label, true );
	}
}

update_option( 'homeward_settings', Settings::sanitize( array(
	'org_name' => 'Rescue <script>bad()</script> & friends',
	'name_cleaning' => false,
	'currency' => 'CAD',
	'logo_url' => 'https://example.test/logo.png',
) ) );
$id = print_pet( 'Molly <b>"& friends</b>' );
update_post_meta( $id, Animal::M_NAME, 'Molly <b>"& friends</b>' );
$second = print_pet( 'Second animal' );
$pending = print_pet( 'Pending animal', 'publish', 'pending' );
$private = print_pet( 'Private animal', 'private' );
$protected = print_pet( 'Protected animal' );
update_post_meta( $protected, Animal::M_DATA, array( 'raw_name' => 'Protected animal' ) );
$GLOBALS['posts'][ $protected ]->post_password = 'secret';
$GLOBALS['current_id'] = $id;
Kennel_Print::init();
Frontend::register_assets();
check( 'print endpoint is hooked', isset( $GLOBALS['hooks']['template_include'] ) );
check( 'bulk endpoint is hooked', isset( $GLOBALS['hooks']['admin_post_homeward_print'] ) );
check( 'row action is hooked', isset( $GLOBALS['hooks']['post_row_actions'] ) );
$_GET = array( 'homeward_print' => '1' );
$template = Kennel_Print::template( '/theme/single.php' );
equals( 'profile query route chooses standalone template', HOMEWARD_DIR . 'templates/kennel-print.php', $template );
ob_start();
include $template;
$html = ob_get_clean();
check( 'route contains two half-page cards for one animal', 2 === substr_count( $html, '<article ' ) );
check( 'QR uses local shared encoder and canonical page data',
	str_contains( $html, '/assets/js/qr.js' ) && str_contains( $html, 'data-print-qr="' . get_permalink( $id ) ) );
check( 'QR has short-link fallback', str_contains( $html, 'data-print-qr-fallback="' ) );
check( 'names are escaped in headings and scan captions',
	str_contains( $html, 'Scan to meet Molly &lt;b&gt;&quot;&amp; friends&lt;/b&gt;' )
	&& ! str_contains( $html, '<b>"& friends</b>' ) );
check( 'rescue logo and name are escaped',
	str_contains( $html, '/logo.png' ) && ! str_contains( $html, '<script>bad' ) );
check( 'facts, fee, description and health appear', str_contains( $html, 'Adult · Female · Medium · Mixed Breed' )
	&& str_contains( $html, 'CAD 125' ) && str_contains( $html, 'Kind &amp; curious.' )
	&& str_contains( $html, 'Spayed or neutered' ) && str_contains( $html, 'Vaccinations up to date' ) );
check( 'default crop favours upper third', str_contains( $html, 'object-position:50% 33%' ) );
check( 'layout and paper choices available',
	str_contains( $html, 'value="poster"' ) && str_contains( $html, 'value="a4"' ) );
check( 'standalone print view has no theme navigation', ! str_contains( $html, '<nav' ) );
update_post_meta( $id, 'homeward_focal_point', array( 'x' => 0.25, 'y' => 0.15 ) );
$html = Kennel_Print::page( array( Kennel_Print::animal( $id ) ) );
check( 'existing focal point is used', str_contains( $html, 'object-position:25% 15%' ) );
update_post_meta( $id, 'homeward_focal_point', array( 'x' => 'bad" onload="bad()', 'y' => 0 ) );
$html = Kennel_Print::page( array( Kennel_Print::animal( $id ) ) );
check( 'invalid focal point falls back safely', str_contains( $html, 'object-position:50% 33%' ) );
$html = Kennel_Print::page( array( Kennel_Print::animal( $id ), Kennel_Print::animal( $second ),
	Kennel_Print::animal( $pending ) ) );
check( 'bulk cards pair sheets without duplicating rows', 3 === substr_count( $html, '<article ' )
	&& 2 === substr_count( $html, 'class="homeward-print-sheet"' ) );
foreach ( array( $private, $protected, 99999 ) as $excluded ) {
	equals( 'private and absent records excluded ' . $excluded, null, Kennel_Print::animal( $excluded ) );
}
check( 'staff row action and bulk option appear',
	isset( Kennel_Print::row_actions( array(), get_post( $id ) )['homeward_print'] )
	&& isset( Kennel_Print::bulk_actions( array() )['homeward_print'] ) );
$_REQUEST['_wpnonce'] = 'valid';
$url = Kennel_Print::bulk( '/edit.php', 'homeward_print', array( $id, $second ) );
parse_str( parse_url( $url, PHP_URL_QUERY ), $request );
equals( 'bulk action validates the WordPress list nonce', 'bulk-posts', $GLOBALS['last_nonce_action'] );
equals( 'signed bulk request preserves row order', array( $id, $second ),
	array_column( Kennel_Print::admin_animals( $request ), 'id' ) );
equals( 'print nonce is bound to the exact IDs', 'homeward_print:' . $request['ids'], $GLOBALS['last_nonce_action'] );
print_denied( 'invalid endpoint nonce rejected', static function () use ( $request ) {
	$request['_wpnonce'] = 'invalid';
	Kennel_Print::admin_animals( $request );
} );
print_denied( 'malformed IDs rejected', static function () {
	Kennel_Print::admin_animals( array( 'ids' => array( 1 ), '_wpnonce' => 'valid' ) );
} );
$_REQUEST['_wpnonce'] = 'invalid';
print_denied( 'invalid bulk nonce rejected', static function () use ( $id ) {
	Kennel_Print::bulk( '/edit.php', 'homeward_print', array( $id ) );
} );
$GLOBALS['wp_query']->posts = array_map( 'get_post', array( $id, $pending, $private, $protected ) );
ob_start();
Kennel_Print::list_button( Animal_Post_Type::TYPE, 'top' );
$button = ob_get_clean();
preg_match( '/href="([^"]+)"/', $button, $link );
parse_str( parse_url( html_entity_decode( $link[ 1 ] ), PHP_URL_QUERY ), $visible );
equals( 'print all available uses only visible available published rows', (string) $id, $visible['ids'] );
$GLOBALS['can'] = false;
equals( 'visitor sees no staff link', '', Kennel_Print::link( $id ) );
equals( 'visitor sees no row action', array(), Kennel_Print::row_actions( array(), get_post( $id ) ) );
equals( 'visitor sees no bulk option', array(), Kennel_Print::bulk_actions( array() ) );
print_denied( 'visitor print route blocked', static function () { Kennel_Print::template( '/single.php' ); } );
print_denied( 'visitor signed endpoint blocked', static function () use ( $request ) {
	Kennel_Print::admin_animals( $request );
} );
$_REQUEST['_wpnonce'] = 'valid';
print_denied( 'visitor bulk action blocked even with nonce', static function () use ( $id ) {
	Kennel_Print::bulk( '/edit.php', 'homeward_print', array( $id ) );
} );
summary( 'kennel print' );
