<?php
/** Public health tri-states, identifier exclusion, manual saves and demo coverage. */
require __DIR__ . '/lib/bootstrap.php';

use Homeward\{Animal, Animal_Post_Type, Kennel_Print, Pet_Data, Render, Settings};

$cases = array(
	array( true, true ), array( false, false ), array( 'True', true ), array( 'False', false ),
	array( 'yes', true ), array( 'no', false ), array( 1, true ), array( 0, false ),
	array( 'Not Sure', null ), array( null, null ), array( '', null ), array( array(), null ),
);
foreach ( $cases as $case ) {
	$clean = Pet_Data::sanitize_pet( array( 'is_spayed' => $case[ 0 ], 'shots_up_to_date' => $case[ 0 ] ) );
	foreach ( array( 'is_spayed', 'shots_up_to_date' ) as $key ) {
		equals( 'health tri-state ' . $key . ' ' . json_encode( $case[ 0 ] ), $case[ 1 ], $clean[ $key ] );
	}
	$chips = Render::chips( $clean );
	check( 'unknown health renders no label or icon', null === $case[ 1 ] ? '' === $chips
		: 2 === substr_count( $chips, $case[ 1 ] ? 'is-yes' : 'is-no' ) );
}
$clean = Pet_Data::sanitize_pet( array( 'name' => 'Synthetic animal', 'microchip_id' => 'PRIVATE_CHIP',
	'microchip_number' => 'PRIVATE_CHIP_NUMBER', 'microchip' => array( 'id' => 'PRIVATE_NESTED_CHIP' ),
	'rabies_tag' => 'PRIVATE_TAG', 'rabies_tag_number' => 'PRIVATE_TAG_NUMBER', 'license_number' => 'PRIVATE_LICENSE',
	'identifier' => 'PRIVATE_IDENTIFIER', 'is_spayed' => true, 'shots_up_to_date' => 'Not Sure',
	'photos' => array( array( 'main_photo_url' => 'https://petstablished.com/photo.jpg',
		'microchip_id' => 'PRIVATE_PHOTO_CHIP' ) ),
) );
foreach ( array( 'microchip_id', 'microchip_number', 'microchip', 'rabies_tag', 'rabies_tag_number',
	'license_number', 'identifier' ) as $key ) {
	check( 'sensitive key discarded ' . $key, ! array_key_exists( $key, $clean ) );
}
check( 'no nested identifiers retained', ! str_contains( json_encode( $clean ), 'PRIVATE_' ) );
equals( 'listing refresh omits absent health without erasing detail', array( 'raw_name' => 'Updated' ),
	Pet_Data::sanitize_pet( array( 'name' => 'Updated' ) ) );
$id = wp_insert_post( array( 'post_type' => Animal_Post_Type::TYPE, 'post_status' => 'publish',
	'post_title' => 'Synthetic animal' ) );
$_POST = array( 'homeward_animal_nonce' => 'valid', 'homeward_manual' => array(
	'status' => 'available', 'species' => 'dog', 'is_spayed' => 'yes', 'shots_up_to_date' => 'no',
	'microchip_id' => 'PRIVATE_MANUAL_CHIP', 'rabies_tag_number' => 'PRIVATE_MANUAL_TAG',
) );
Animal_Post_Type::save( $id );
$animal = Animal::get( $id );
equals( 'manual spayed toggle saves yes', true, $animal['is_spayed'] );
equals( 'manual vaccination toggle saves no', false, $animal['shots_up_to_date'] );
check( 'manual save ignores identifier injection', ! str_contains( json_encode( Animal::data( $id ) ), 'PRIVATE_' ) );
ob_start();
Animal_Post_Type::box( get_post( $id ) );
$form = ob_get_clean();
check( 'manual editor has translated health labels', str_contains( $form, 'Spayed or neutered' )
	&& str_contains( $form, 'Vaccinations up to date' ) );
check( 'profile renders health beside compatibility',
	str_contains( Render::animal_page( $animal, get_post( $id ) ), 'Spayed or neutered' ) );
check( 'kennel card renders confirmed negative health',
	str_contains( Kennel_Print::page( array( $animal ) ), 'Vaccinations not up to date' ) );
check( 'compact website cards reserve space for compatibility',
	! str_contains( Render::card( $animal ), 'Spayed or neutered' ) );
$_POST['homeward_manual']['is_spayed'] = '';
$_POST['homeward_manual']['shots_up_to_date'] = '';
Animal_Post_Type::save( $id );
$animal = Animal::get( $id );
check( 'manual unknown shows no health labels', null === $animal['is_spayed']
	&& null === $animal['shots_up_to_date'] && '' === Render::chips( $animal ) );
update_post_meta( $id, Animal::M_DATA, array( 'is_spayed' => true, 'shots_up_to_date' => true ) );
update_option( 'homeward_settings', Settings::sanitize( array( 'public_fields' => array() ) ) );
$animal = Animal::get( $id );
check( 'public field settings can hide both health icons', null === $animal['is_spayed']
	&& null === $animal['shots_up_to_date'] && '' === Render::chips( $animal ) );
foreach ( glob( HOMEWARD_DIR . 'fixtures/petstablished/detail-*.json' ) as $file ) {
	$pet = json_decode( file_get_contents( $file ), true )['pet'];
	check( 'fixture health fields ' . basename( $file ), array_key_exists( 'is_spayed', $pet )
		&& array_key_exists( 'shots_up_to_date', $pet ) );
}
$demo = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/demo-content/sample.json' ), true );
$states = array();
foreach ( $demo['animals'] as $pet ) {
	$clean = Pet_Data::sanitize_pet( $pet );
	$states[] = $clean['is_spayed'];
	$states[] = $clean['shots_up_to_date'];
}
check( 'demo includes confirmed and unknown health',
	in_array( true, $states, true ) && in_array( null, $states, true ) );
summary( 'health' );
