<?php

namespace Altis\Workflow\PublicationChecklist;

use stdClass;
use WP_REST_Request;

const GLOBAL_NAME = 'altis_publication_checklist_checks';
const INTERNAL_CHECKED_KEY = '__altis_publication_checklist_checked';
const POSTS_COLUMN = 'altis_publication_checklist_status';
const SCRIPT_ID = 'altis_publication_checklist';

/**
 * Bootstrap.
 */
function bootstrap() {
	add_action( 'wp_enqueue_editor', __NAMESPACE__ . '\\enqueue_assets' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_fields' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\set_up_checks' );
	add_action( 'manage_posts_columns', __NAMESPACE__ . '\\register_column' );
	add_action( 'manage_posts_custom_column',  __NAMESPACE__ . '\\render_column' );
}

/**
 * Set up the prepublish checks.
 */
function set_up_checks() {
	$GLOBALS[ GLOBAL_NAME ] = [];
	do_action( 'altis.publication-checklist.register_prepublish_checks' );

	if ( ! should_block_publish() ) {
		return;
	}

	add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\\block_publish_if_failing', 10, 2 );
	add_filter( 'rest_pre_insert_post', __NAMESPACE__ . '\\block_publish_for_rest', 10, 2 );
}

/**
 * Should failing checks block publication?
 *
 * @return bool True to block publication on failing checks, false to allow.
 */
function should_block_publish() {
	/**
	 * Filter to allow blocking publication on failing checks.
	 *
	 * This can be set to true to block publication altogether if a post is
	 * failing checks.
	 *
	 * @param bool $block_publication Should we block publication on failing checks?
	 */
	return apply_filters( 'altis.publication-checklist.block_on_failing', false );
}

/**
 * Enqueue browser assets for the editor.
 */
function enqueue_assets() {
	wp_enqueue_script(
		SCRIPT_ID,
		plugins_url( 'build/index.js', __DIR__ ),
		[
			'wp-block-editor',
			'wp-edit-post',
			'wp-plugins',
		]
	);
	wp_enqueue_style(
		'workflow-pub-checklist',
		plugins_url( 'build/style.css', __DIR__ )
	);

	wp_localize_script( SCRIPT_ID, 'altisPublicationChecklist', [
		'block_publish' => should_block_publish(),
	] );
}

/**
 * Register the Tasks column.
 *
 * @param array $columns Registered columns.
 * @return array Columns with additional Tasks column added.
 */
function register_column( array $columns ) : array {
	// If no checks have been registered, don't show the column.
	$registered = $GLOBALS[ GLOBAL_NAME ];
	if ( empty( $registered ) ) {
		return $columns;
	}

	/**
	 * Filter to control whether the Tasks column is shown.
	 *
	 * This can be set to false to hide the Tasks column from the posts
	 * list table.
	 *
	 * @param bool $show_column Should we show the Tasks column?
	 */
	$show_column = apply_filters( 'altis.publication-checklist.show_tasks_column', true );
	if ( ! $show_column ) {
		return $columns;
	}

	$new_columns = [];

	// Loop over the columns until we find the "tags" column. This allows us
	// to insert Tasks at the correct position.
	foreach ( $columns as $key => $value ) {
		$new_columns[ $key ] = $value;
		if ( $key === 'tags' ) {
			$new_columns[ POSTS_COLUMN ] = _x( 'Tasks', 'list table column header', 'altis-publication-checklist' );
		}
	}

	return $new_columns;
}

/**
 * Render the Tasks column.
 *
 * @param string $column_id Column being rendered.
 * @return void Outputs directly.
 */
function render_column( string $column_id ) {
	if ( $column_id !== POSTS_COLUMN ) {
		return;
	}

	$post_data = get_post( null, ARRAY_A );
	$meta = get_merged_meta( $post_data['ID'], [] );
	$statuses = get_check_status( $post_data, $meta );

	$incomplete = array_filter( $statuses, function ( Status $status ) {
		return $status->get_status() === Status::INCOMPLETE;
	} );
	$to_complete = count( $incomplete );
	if ( $to_complete === 0 ) {
		$icon_color = '#3fcf8e';
		$text = __( 'All done!', 'altis-publication-checklist' );
	} else {
		$icon_color = '#f97a14';
		$text = sprintf(
			_n( '%d left', '%d left', $to_complete, 'altis-publication-checklist' ),
			$to_complete
		);
	}

	printf(
		'<svg style="width: 10px; height: 10px; margin-right: 0.7em" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4" style="fill: %s" /></svg>',
		esc_attr( $icon_color )
	);

	echo esc_html( $text );
}

/**
 * Register REST fields for check status.
 */
function register_rest_fields() {
	register_rest_field( 'post', 'prepublish_checks', [
		'get_callback' => __NAMESPACE__ . '\\get_check_status_for_api',
	] );
}

/**
 * Register a prepublish check.
 *
 * @param string $id Check ID
 * @param array $options {
 *   @var callable $run_check Callback to run the checks.
 * }
 */
function register_prepublish_check( $id, $options ) {
	$GLOBALS[ GLOBAL_NAME ][ $id ] = $options;
}

/**
 * Get the check status, formatted for the REST API.
 *
 * @param array $data Raw REST API post data.
 * @return stdClass Map of check ID => status.
 */
function get_check_status_for_api( array $data ) : ?stdClass {
	/** @var WP_Post */
	$post = get_post( $data['id'], ARRAY_A );
	if ( empty( $post ) ) {
		return null;
	}
	$meta = get_post_meta( $data['id'] );

	$statuses = get_check_status( $post, $meta );
	$status_data = [];
	foreach ( $statuses as $id => $status ) {
		$status_data[ $id ] = [
			'status' => $status->get_status(),
			'message' => $status->get_message(),
			'data' => $status->get_data(),
		];
	}
	return (object) $status_data;
}

/**
 * Get the check status for a post.
 *
 * @param array $data Post data (may not have been saved).
 * @param array $meta Post metadata (may not have been saved).
 * @return Status[] Map of check ID => status.
 */
function get_check_status( array $data, array $meta ) : array {
	$checks = $GLOBALS[ GLOBAL_NAME ];
	$status = [];
	foreach ( $checks as $id => $options ) {
		$status[ $id ] = call_user_func( $options['run_check'], $data, $meta );
	}

	return $status;
}

/**
 * Combine all statuses to a pass/fail status.
 *
 * @param Status[] $statuses All check statuses (from get_check_status())
 * @return bool True if post passes checks, false otherwise.
 */
function get_combined_status( array $statuses ) : bool {
	foreach ( $statuses as $status ) {
		/** @var Status $status */
		if ( $status->get_status() === Status::INCOMPLETE ) {
			return false;
		}
	}

	return true;
}

/**
 * Get all meta values, including changes.
 *
 * This fetches all metadata from the database, normalises it to the same format
 * passed through to the REST API or wp_insert_post, and combines it with any
 * pending changes. This is then passed through to checks, allowing meta to be
 * checked before it is saved to the database.
 *
 * @param int $id Post ID to get meta for.
 * @param array $meta Changed meta.
 */
function get_merged_meta( int $id, array $meta ) : array {
	$registered = get_registered_meta_keys( 'post' );
	$registered = array_merge( $registered, get_registered_meta_keys( 'post', get_post_type( $id ) ) );

	$combined = [];

	// Fetch existing meta.
	$existing_meta = get_post_meta( $id );
	foreach ( $existing_meta as $key => $values ) {
		$options = $registered[ $key ] ?? null;
		$single = $options && $options['single'];
		if ( $single ) {
			$combined[ $key ] = maybe_unserialize( $values[0] );
		} else {
			$combined[ $key ] = array_map( 'maybe_unserialize', $values );
		}
	}

	// Add in changing meta.
	foreach ( $meta as $key => $value ) {
		$options = $registered[ $key ] ?? null;
		$single = $options && $options['single'];
		if ( $single ) {
			$combined[ $key ] = $value;
		} else {
			$combined[ $key ] = [ $value ];
		}
	}

	return $combined;
}

/**
 * Is the status a "published" status?
 *
 * @return bool True if the status counts as "published", false otherwise.
 */
function is_publish_status( string $status ) : bool {
	$statuses = [
		'publish',
		'private',
		'future',
	];

	/**
	 * Filter whether a status counts as a "published" status.
	 *
	 * Transitioning to a "published" status is blocked if checks fail, and if
	 * should_block_publish() is enabled.
	 *
	 * @param bool $is_publish_status Is the status a "published" status?
	 * @param string $status Status to check.
	 */
	return apply_filters( 'altis-publication-checklist.is_publish_status', in_array( $status, $statuses, true ), $status );
}

/**
 * Block publication for REST requests.
 *
 * We have more data at the REST API level, so running checks here allows more
 * accurate data to be passed through.
 *
 * @param stdClass $data Data to be passed through to wp_insert_post.
 * @param WP_REST_Request $request Full request data.
 * @return stdClass Updated data.
 */
function block_publish_for_rest( stdClass $data, WP_REST_Request $request ) : stdClass {
	// Don't block if the status isn't changing.
	if ( ! isset( $data->post_status ) ) {
		return $data;
	}

	// Don't block non-publishing statuses.
	if ( ! is_publish_status( $data->post_status ) ) {
		return $data;
	}

	$existing_post = get_post( $data->ID, ARRAY_A );
	$post = array_merge( $existing_post, (array) $data );
	$meta = get_merged_meta( $data->ID, $request['meta'] ?? [] );

	$checks = get_check_status( $post, $meta );
	$check_success = get_combined_status( $checks );
	if ( ! $check_success ) {
		// Don't allow status to be changed.
		unset( $data->post_status );
	}

	// Mark post as checked for this request, and skip follow-up checks.
	$data->{ INTERNAL_CHECKED_KEY } = true;

	return $data;
}

/**
 * Block publication of posts.
 *
 * This operates at a low level (inside wp_insert_post), so is less accurate
 * than higher-level checks.
 *
 * @param array $data Data to update on the post.
 * @param array $postarr Raw data for the post.
 * @return array Post data to update in DB.
 */
function block_publish_if_failing( array $data, array $postarr ) : array {
	// Skip checks if already checked.
	if ( isset( $postarr[ INTERNAL_CHECKED_KEY ] ) ) {
		return $data;
	}

	// Don't block if the status isn't changing.
	if ( isset( $postarr['ID'] ) ) {
		$existing = get_post( $postarr['ID'], ARRAY_A );
		if ( $existing['post_status'] === $data['post_status'] ) {
			return $data;
		}
	}

	// Don't block non-publishing statuses.
	if ( ! is_publish_status( $data['post_status'] ) ) {
		return $data;
	}

	// Block if it fails.
	if ( isset( $postarr['ID'] ) ) {
		$meta = get_merged_meta( $postarr['ID'], $data['meta_input'] ?? [] );
	} else {
		$meta = $data['meta_input'] ?? [];
	}

	$checks = get_check_status( $data, $meta );
	$check_success = get_combined_status( $checks );
	if ( ! $check_success ) {
		// Don't allow status to be changed.
		if ( isset( $existing ) ) {
			$data['post_status'] = $existing['post_status'];
		} else {
			$data['post_status'] = 'draft';
		}
	}

	return $data;
}
