<?php
namespace AffWP\Utils\Batch_Process;

use AffWP\Utils\Batch_Process;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'AffWP\Utils\Batch_Processor\Base' ) ) {
	require_once AFFILIATEWP_PLUGIN_DIR . 'includes/abstracts/class-affwp-batch-process.php';
}

/**
 * Implements a batch processor for migrating existing users to affiliate accounts.
 *
 * @since 2.0
 *
 * @see \AffWP\Utils\Batch_Processor\Base
 */
class Migrate_Users extends Batch_Process\Base {

	/**
	 * Batch process ID.
	 *
	 * @access public
	 * @since  2.0
	 * @var    string
	 */
	public $batch_id = 'migrate-users';

	/**
	 * Roles to migrate found users from.
	 *
	 * Should be set following instantiation via __set().
	 *
	 * @access public
	 * @since  2.0
	 * @var    array
	 */
	public $roles = array();

	/**
	 * IDs for existing affiliate users (to skip).
	 *
	 * @access public
	 * @since  2.0
	 * @var    array
	 */
	public $affiliate_user_ids = array();

	/**
	 * Initializes values needed following instantiation.
	 *
	 * @access public
	 * @since  2.0
	 *
	 * @param null|array $data Optional. Form data. Default null.
	 */
	public function init( $data = null ) {
		if ( null !== $data && ! empty( $data['roles'] ) ) {
			$this->roles = $data['roles'];
		}
	}

	/**
	 * Handles pre-fetching user IDs for accounts in migration.
	 *
	 * @access public
	 * @since  2.0
	 */
	public function pre_fetch() {
		$affiliate_user_ids = affiliate_wp()->utils->data->get( 'affwp_migrate_users_user_ids' );

		if ( false === $affiliate_user_ids ) {
			$affiliate_user_ids = affiliate_wp()->affiliates->get_affiliates( array(
				'number' => -1,
				'fields' => 'user_id',
			) );

			affiliate_wp()->utils->data->write( 'affwp_migrate_users_user_ids', $affiliate_user_ids );
		}

		$total_to_migrate = affiliate_wp()->utils->data->get( 'affwp_migrate_users_total_count' );

		if ( false === $total_to_migrate ) {
			$users = get_users( array(
				'fields'   => 'ids',
				'role__in' => $this->roles,
				'number'   => -1,
				'exclude'  => $affiliate_user_ids,
			) );

			$total_to_migrate = count( $users );

			affiliate_wp()->utils->data->write( 'affwp_migrate_users_total_count', $total_to_migrate, array( '%s', '%d', '%s' ) );
		}
	}

	/**
	 * Determines if the current user can run the user migration script.
	 *
	 * @access public
	 * @since  2.0
	 *
	 * @return bool True if the user has permission, otherwise false.
	 */
	public function can_process() {
		return current_user_can( 'manage_affiliates' );
	}

	/**
	 * Executes a single step in the batch process.
	 *
	 * @access public
	 * @since  2.0
	 *
	 * @param int|string $step Step number or 'done'.
	 * @return int|string|\WP_Error Next step number, 'done', or a WP_Error object.
	 */
	public function process_step( $step ) {
		if ( ! $this->roles ) {
			return new \WP_Error( 'no_roles_found', __( 'No user roles were selected for migration.', 'affiliate-wp' ) );
		}

		$current_count = affiliate_wp()->utils->data->get( 'affwp_migrate_users_current_count', 0 );

		$args = array(
			'number'     => 100,
			'offset'     => ( $step - 1 ) * 100,
			'exclude'    => affiliate_wp()->utils->data->get( 'affwp_migrate_users_user_ids', array() ),
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'role__in'   => $this->roles,
			'fields'     => array( 'ID', 'user_email', 'user_registered' )
		);

		$users = get_users( $args );

		if ( empty( $users ) ) {
			return 'done';
		}

		$inserted = array();

		foreach ( $users as $user ) {

			$args = array(
				'status'          => 'active',
				'user_id'         => $user->ID,
				'payment_email'	  => $user->user_email,
				'date_registered' => $user->user_registered
			);

			$inserted[] = affiliate_wp()->affiliates->insert( $args, 'affiliate' );

		}

		$current_count = $current_count + count( $inserted );

		affiliate_wp()->utils->data->write( 'affwp_migrate_users_current_count', $current_count, array( '%s', '%d', '%s' ) );

		$step++;

		return $step;
	}

	/**
	 * Retrieves the total count of migrated items.
	 *
	 * @access public
	 * @since  2.0
	 * @static
	 *
	 * @param string $key The stored option key.
	 * @return mixed|false The stored data, otherwise false.
	 */
	public static function get_items_total( $key ) {
		return affiliate_wp()->utils->data->get( $key );
	}

	/**
	 * Deletes the total count of migrated items.
	 *
	 * @access public
	 * @since  2.0
	 * @static
	 *
	 * @param string $key The stored option name to delete.
	 */
	public static function clear_items_total( $key ) {
		affiliate_wp()->utils->data->delete( $key );
	}

	/**
	 * Defines logic to execute after the batch processing is complete.
	 *
	 * @access public
	 * @since  2.0
	 */
	public function finish() {
		// Clean up.
		affiliate_wp()->utils->data->delete( 'affwp_migrate_users_user_ids' );
		affiliate_wp()->utils->data->delete( 'affwp_migrate_users_total_count' );
		affiliate_wp()->utils->data->delete( 'affwp_migrate_users_current_count' );
	}
}