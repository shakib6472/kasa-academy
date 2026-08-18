<?php
/**
 * Applies the capability map to WordPress roles.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/capabilities.php';

/**
 * Role installer.
 *
 * Two rules govern everything in this class.
 *
 * First, WordPress stores roles in the database rather than reading them from
 * code on each load, and add_role() is a no-op when the role already exists.
 * So the map is re-applied whenever KASA_ROLES_VERSION stops matching the
 * stored option, not on every request.
 *
 * Second, this plugin is a guest in roles it does not own. It only ever adds
 * or removes capabilities that appear in its own managed set. A capability
 * put on a role by LearnDash, Wordfence, GamiPress or a site administrator is
 * never stripped, because this plugin has no way of knowing why it is there.
 */
class Kasa_Roles {

	/**
	 * The option holding the applied roles version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'kasa_academy_roles_version';

	/**
	 * Re-apply the map only when the code and the database disagree.
	 *
	 * Hooked to admin_init.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::VERSION_OPTION ) === KASA_ROLES_VERSION ) {
			return;
		}

		// Only record the version once the whole map actually applied.
		//
		// install() has to skip a role it extends but does not own when that
		// role is not in the database yet, and the one that can be missing is
		// group_leader, which belongs to LearnDash. Activate this plugin
		// before LearnDash, or with LearnDash switched off, and the Facilitator
		// half of the map is skipped. Stamping the version anyway would record
		// that as done, and the version gate would never let it run again, so
		// facilitators would sit there with no Kasa capabilities until somebody
		// noticed and bumped the constant by hand.
		//
		// Retrying is cheap. sync_managed_capabilities() only writes when a
		// capability is actually wrong, so a repeat run on an already correct
		// site is reads and no writes.
		if ( ! self::install() ) {
			return;
		}

		update_option( self::VERSION_OPTION, KASA_ROLES_VERSION );
	}

	/**
	 * Every capability this plugin claims authority over.
	 *
	 * A capability in this set is added to the roles that should have it and
	 * removed from the roles that should not. A capability outside this set is
	 * left alone wherever it appears.
	 *
	 * @return array List of capability slugs.
	 */
	public static function managed_capabilities() {
		return array_keys( kasa_academy_capability_definitions() );
	}

	/**
	 * Write the capability map into the database.
	 *
	 * Idempotent. Running it twice produces the same result as running it once.
	 *
	 * @return bool True when every role in the map was applied. False when one
	 *              had to be skipped, which tells the caller not to record the
	 *              version as done.
	 */
	public static function install() {
		$roles    = kasa_academy_role_definitions();
		$managed  = self::managed_capabilities();
		$complete = true;

		foreach ( $roles as $slug => $definition ) {
			$role = get_role( $slug );

			if ( ! $role instanceof WP_Role ) {
				// A role we do not own and which is not present. LearnDash may
				// not have registered group_leader yet, or may be inactive.
				// Nothing to extend, so skip rather than inventing it, and
				// report the run as incomplete so it is tried again later.
				if ( empty( $definition['managed'] ) ) {
					$complete = false;

					continue;
				}

				$role = add_role(
					$slug,
					$definition['display_name'],
					$definition['base_caps']
				);

				if ( ! $role instanceof WP_Role ) {
					$complete = false;

					continue;
				}
			}

			self::ensure_base_capabilities( $role, $definition['base_caps'] );
			self::sync_managed_capabilities( $role, $definition['caps'], $managed );

			if ( ! empty( $definition['rename'] ) ) {
				self::set_role_display_name( $slug, $definition['display_name'] );
			}
		}

		self::maybe_sync_learndash_group_leader_label();

		return $complete;
	}

	/**
	 * Ensure the capabilities a managed role needs to function are present.
	 *
	 * Additive only. This never removes anything, so a capability another
	 * plugin added to the role survives.
	 *
	 * @param WP_Role $role      Role object.
	 * @param array   $base_caps Capability slug => bool.
	 * @return void
	 */
	private static function ensure_base_capabilities( $role, $base_caps ) {
		foreach ( $base_caps as $cap => $grant ) {
			$has = isset( $role->capabilities[ $cap ] ) && $role->capabilities[ $cap ];

			if ( $grant && ! $has ) {
				$role->add_cap( $cap, true );
			}
		}
	}

	/**
	 * Bring the role's managed capabilities into line with the map.
	 *
	 * This is the half that makes the map authoritative. Dropping a capability
	 * from a role in capabilities.php and bumping KASA_ROLES_VERSION actually
	 * takes the capability away, rather than leaving it behind in the database
	 * where it would quietly keep working.
	 *
	 * @param WP_Role $role    Role object.
	 * @param array   $granted Capability slugs this role should have.
	 * @param array   $managed Every capability this plugin controls.
	 * @return void
	 */
	private static function sync_managed_capabilities( $role, $granted, $managed ) {
		$should_have = array_flip( $granted );

		foreach ( $managed as $cap ) {
			$wanted  = isset( $should_have[ $cap ] );
			$present = isset( $role->capabilities[ $cap ] ) && $role->capabilities[ $cap ];

			if ( $wanted && ! $present ) {
				$role->add_cap( $cap, true );
				continue;
			}

			if ( ! $wanted && isset( $role->capabilities[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Change a role's display name without disturbing its capabilities.
	 *
	 * WP_Role has no setter for the name, and the usual workaround of
	 * remove_role() followed by add_role() is risky here. If anything failed
	 * between the two calls the role would be gone, and every user holding it
	 * would lose access. Editing the roles option in place cannot half-succeed.
	 *
	 * @param string $slug Role slug.
	 * @param string $name Display name.
	 * @return void
	 */
	private static function set_role_display_name( $slug, $name ) {
		$wp_roles = wp_roles();

		if ( ! isset( $wp_roles->roles[ $slug ] ) ) {
			return;
		}

		if ( $wp_roles->roles[ $slug ]['name'] === $name ) {
			return;
		}

		$wp_roles->roles[ $slug ]['name'] = $name;
		$wp_roles->role_names[ $slug ]    = $name;

		update_option( $wp_roles->role_key, $wp_roles->roles );
	}

	/**
	 * Tell LearnDash the group leader is called a Facilitator.
	 *
	 * LearnDash keeps its own label for this role and uses it throughout its
	 * interface. Leaving the two out of step shows Facilitator on the users
	 * screen and Group Leader everywhere inside LearnDash.
	 *
	 * This must go through LearnDash's settings API rather than update_option().
	 * Every LearnDash settings section registers a pre_update_option filter
	 * that begins with a nonce check, and on failing it returns the old value:
	 *
	 *     if ( ! (bool) $this->verify_metabox_nonce_field() ) {
	 *         return $old_value;
	 *     }
	 *
	 * A plain update_option() carries no nonce, so LearnDash discards the write
	 * and returns success. The setting silently does not change. set_section_setting()
	 * routes through save_settings_values(), which sets the documented
	 * settings_bypass_nonce_check flag around the write.
	 *
	 * Run on its own rather than only inside the version gate, so that a sync
	 * which failed once, because LearnDash had not finished initialising, is
	 * retried on the next admin screen instead of being stranded by a version
	 * number that already matches.
	 *
	 * @return void
	 */
	public static function maybe_sync_learndash_group_leader_label() {
		$definitions = kasa_academy_role_definitions();

		if ( ! isset( $definitions[ KASA_ROLE_FACILITATOR ]['display_name'] ) ) {
			return;
		}

		if ( ! class_exists( 'LearnDash_Settings_Section' ) ) {
			// LearnDash is not loaded. The WordPress side rename has already
			// happened, and there is no LearnDash label to keep in step with.
			return;
		}

		$label   = $definitions[ KASA_ROLE_FACILITATOR ]['display_name'];
		$section = 'LearnDash_Settings_Section_Custom_Labels';

		$current = LearnDash_Settings_Section::get_section_setting( $section, 'group_leader' );

		if ( $current === $label ) {
			return;
		}

		/*
		 * Saving this section makes LearnDash call remove_role() then
		 * add_role() on group_leader, carrying the capabilities across. That is
		 * safe here only because this runs after install() has already put the
		 * Kasa capabilities on the role, so they are part of what gets carried.
		 * Do not move this call earlier.
		 */
		LearnDash_Settings_Section::set_section_setting( $section, 'group_leader', $label );
	}

	/**
	 * Remove this plugin's capabilities and roles.
	 *
	 * Called from uninstall.php only, never on deactivation. User role
	 * assignments are left untouched, so a learner keeps their kasa_learner
	 * assignment in usermeta and regains full function the moment the plugin
	 * is installed again.
	 *
	 * @return void
	 */
	public static function uninstall() {
		$roles   = kasa_academy_role_definitions();
		$managed = self::managed_capabilities();

		foreach ( get_editable_roles() as $slug => $unused ) {
			$role = get_role( $slug );

			if ( ! $role instanceof WP_Role ) {
				continue;
			}

			foreach ( $managed as $cap ) {
				// group_leader belongs to LearnDash. Taking it off the
				// LearnDash role would break LearnDash itself.
				if ( 'group_leader' === $cap && KASA_ROLE_FACILITATOR === $slug ) {
					continue;
				}

				if ( isset( $role->capabilities[ $cap ] ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		foreach ( $roles as $slug => $definition ) {
			if ( ! empty( $definition['managed'] ) ) {
				remove_role( $slug );
			}
		}

		delete_option( self::VERSION_OPTION );
	}
}
