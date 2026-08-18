<?php
/**
 * Object aware capabilities.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ties the capability map and the scoping rules together.
 *
 * A plain capability answers only half the question. current_user_can(
 * 'kasa_view_group_learners' ) is true for every facilitator on the platform,
 * including for a group that belongs to somebody else. The capabilities
 * registered here take an ID and answer the whole question:
 *
 *     current_user_can( 'kasa_view_learner_detail', $learner_id )
 *
 * That is false unless the user both holds the underlying capability and has
 * that learner inside their own scope.
 *
 * This exists so a later module cannot forget to scope. Calling
 * current_user_can() is the habit every WordPress developer already has; the
 * scoping comes along with it, rather than being a second step someone has to
 * remember. Forgetting it once, on a platform holding children's work, is a
 * disclosure rather than a bug.
 */
class Kasa_Meta_Caps {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map' ), 10, 4 );
	}

	/**
	 * The object aware capabilities, and what each one needs.
	 *
	 * Each entry is: meta capability => array( primitive capability, subject ).
	 * Subject is 'group' when the ID is a group, 'learner' when it is a user.
	 *
	 * @return array
	 */
	private static function definitions() {
		return array(
			'kasa_view_group'          => array( 'kasa_view_group_learners', 'group' ),
			'kasa_export_group'        => array( 'kasa_export_group_progress', 'group' ),
			'kasa_manage_group'        => array( 'kasa_manage_group_members', 'group' ),
			'kasa_view_learner'        => array( 'kasa_view_group_learners', 'learner' ),
			'kasa_view_learner_detail' => array( 'kasa_view_group_detail', 'learner' ),
		);
	}

	/**
	 * Resolve one of our object aware capabilities.
	 *
	 * Returns do_not_allow rather than an empty array on every failure path,
	 * including a missing ID. An empty array would mean "no capability
	 * required", which WordPress reads as granted.
	 *
	 * @param array  $caps    Primitive capabilities required.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User being checked.
	 * @param array  $args    Extra arguments; $args[0] is the object ID.
	 * @return array
	 */
	public static function map( $caps, $cap, $user_id, $args ) {
		$definitions = self::definitions();

		if ( ! isset( $definitions[ $cap ] ) ) {
			return $caps;
		}

		list( $primitive, $subject ) = $definitions[ $cap ];

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array( 'do_not_allow' );
		}

		$object_id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		// Asking "can this user view a group" without saying which group is
		// not a question we can answer safely, so we refuse it.
		if ( ! $object_id ) {
			return array( 'do_not_allow' );
		}

		if ( Kasa_Scope::is_unrestricted( $user_id ) ) {
			return array( $primitive );
		}

		if ( ! user_can( $user_id, $primitive ) ) {
			return array( 'do_not_allow' );
		}

		$in_scope = ( 'group' === $subject )
			? kasa_user_can_see_group( $object_id, $user_id )
			: kasa_user_can_see_learner( $object_id, $user_id );

		return $in_scope ? array( $primitive ) : array( 'do_not_allow' );
	}
}
