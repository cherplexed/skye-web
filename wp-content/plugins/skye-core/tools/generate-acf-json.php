<?php
/**
 * Generates acf-json/group_skye_*.json from Fields::* so the ACF definition and the PHP schema
 * can never drift. Run: php tools/generate-acf-json.php  (from the plugin directory)
 *
 * A file is only rewritten when its content changed (the `modified` stamp alone never causes a diff).
 * With --check nothing is written and the exit code is 1 when any file is out of date (used by CI).
 */
declare(strict_types=1);

define( 'SKYE_CORE_DIR', dirname( __DIR__ ) );
require SKYE_CORE_DIR . '/src/PostTypes.php';
require SKYE_CORE_DIR . '/src/Taxonomies.php';
require SKYE_CORE_DIR . '/src/Fields.php';

use Skye\Fields;
use Skye\PostTypes;

function acf_key( string $prefix, string $name ): string {
	return 'field_skye_' . $prefix . '_' . $name;
}

function acf_field( string $prefix, string $name, array $def ): array {
	$base = [
		'key'   => acf_key( $prefix, $name ),
		'label' => ucfirst( str_replace( '_', ' ', $name ) ),
		'name'  => $name,
		'wrapper' => [ 'width' => '', 'class' => '', 'id' => '' ],
		// WPML: translate or copy — read by WPML's ACF bridge.
		'wpml_cf_preferences' => $def['i18n'] ? 2 : 1,
	];
	$type = $def['type'];
	return $base + match ( $type ) {
		'text'       => [ 'type' => 'text' ] + ( isset( $def['default'] ) ? [ 'default_value' => $def['default'] ] : [] ),
		'url'        => [ 'type' => 'url' ],
		'textarea'   => [ 'type' => 'textarea', 'rows' => 3, 'new_lines' => '' ],
		'html'       => [ 'type' => 'wysiwyg', 'media_upload' => 0 ],
		'number'     => [ 'type' => 'number', 'default_value' => $def['default'] ?? '' ],
		'bool'       => [ 'type' => 'true_false', 'ui' => 1 ],
		'enum'       => [ 'type' => 'select', 'choices' => $def['choices'], 'default_value' => $def['default'] ?? '', 'return_format' => 'value' ],
		'checkboxes' => [ 'type' => 'checkbox', 'choices' => $def['choices'], 'return_format' => 'value', 'layout' => 'horizontal' ],
		'latlng'     => [ 'type' => 'group', 'layout' => 'table', 'sub_fields' => [
			[ 'key' => acf_key( $prefix, $name . '_lat' ), 'label' => 'Lat', 'name' => 'lat', 'type' => 'number', 'step' => 'any', 'wpml_cf_preferences' => 1 ],
			[ 'key' => acf_key( $prefix, $name . '_lng' ), 'label' => 'Lng', 'name' => 'lng', 'type' => 'number', 'step' => 'any', 'wpml_cf_preferences' => 1 ],
		] ],
		'image'      => [ 'type' => 'image', 'return_format' => 'id' ],
		'gallery'    => [ 'type' => 'gallery', 'return_format' => 'id' ],
		'file'       => [ 'type' => 'file', 'return_format' => 'url' ],
		'relation'   => [ 'type' => 'relationship', 'post_type' => [ $def['to'] ], 'return_format' => 'id', 'filters' => [ 'search' ] ] + ( isset( $def['max'] ) ? [ 'max' => $def['max'] ] : [] ),
		'repeater'   => [ 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add row', 'sub_fields' => array_values( array_map(
			static fn( string $sub, array $sdef ) => acf_field( $prefix . '_' . $name, $sub, $sdef ),
			array_keys( $def['sub'] ), $def['sub']
		) ) ],
	};
}

$titles = [
	PostTypes::PLACE      => 'Place',
	PostTypes::ROUTE      => 'Route',
	PostTypes::BRAND      => 'Brand',
	PostTypes::TOUR       => 'Tour',
	PostTypes::LODGING    => 'Lodging',
	PostTypes::MOTORHOME  => 'Motorhome spot',
	PostTypes::EXPERIENCE => 'Experience',
	PostTypes::GUIDE      => 'Guide (practical information)',
];

$check = in_array( '--check', $argv, true );
$stale = 0;

foreach ( Fields::all() as $post_type => $fields ) {
	$prefix = $post_type;
	$json   = [
		'key'      => 'group_skye_' . $prefix,
		'title'    => 'Skye: ' . $titles[ $post_type ],
		'fields'   => array_values( array_map( static fn( $n, $d ) => acf_field( $prefix, $n, $d ), array_keys( $fields ), $fields ) ),
		'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => $post_type ] ] ],
		'position' => 'normal',
		'style'    => 'default',
		'active'   => true,
		'show_in_rest' => 1,
		'modified' => time(),
	];
	$out = SKYE_CORE_DIR . '/acf-json/group_skye_' . $prefix . '.json';

	$current = is_file( $out ) ? json_decode( (string) file_get_contents( $out ), true ) : null;
	if ( is_array( $current ) ) {
		$a = $current;
		$b = $json;
		unset( $a['modified'], $b['modified'] );
		if ( $a === $b ) {
			echo "up to date $out\n";
			continue;
		}
	}
	if ( $check ) {
		echo "OUT OF DATE $out\n";
		++$stale;
		continue;
	}
	@mkdir( dirname( $out ), 0775, true );
	file_put_contents( $out, json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo "wrote $out\n";
}

exit( $stale ? 1 : 0 );
