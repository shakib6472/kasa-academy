<?php
/*
* Plugin Name:       Kasa Academy
* Plugin URI:        https://github.com/shakib6472/kasa-academy
* Description:       Roles, capabilities and group scoping for the Kasa Learning Academy. Built as a foundation so the learner dashboard, learning pathway and application system can be added later as separate modules.
* Version:           1.0.0
* Requires at least: 6.9
* Requires PHP:      7.2
* Author:            Shakib Shown
* Author URI:        https://github.com/shakib6472/
* License:           GPL v2 or later
* License URI:       https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain:       kasa-academy
* Domain Path:       /languages
*/
if (!defined('ABSPATH')) {
exit; // Exit if accessed directly.
}

/**
 * The plugin version. Bump on release.
 */
define( 'KASA_ACADEMY_VERSION', '1.0.0' );

/**
 * The roles and capabilities version.
 *
 * WordPress stores roles in the database, it does not read them from code on
 * every load, so editing the capability map alone changes nothing. This
 * constant is compared against the `kasa_academy_roles_version` option on
 * admin_init. When the two differ the whole map is re-applied and the option
 * is brought up to date.
 *
 * Increment this whenever the capability map in includes/roles/capabilities.php
 * changes, otherwise the change will never reach an existing install.
 */
define( 'KASA_ROLES_VERSION', '1.0.0' );

define( 'KASA_ACADEMY_FILE', __FILE__ );
define( 'KASA_ACADEMY_PATH', plugin_dir_path( __FILE__ ) );
define( 'KASA_ACADEMY_URL', plugin_dir_url( __FILE__ ) );
define( 'KASA_ACADEMY_BASENAME', plugin_basename( __FILE__ ) );

require_once KASA_ACADEMY_PATH . 'includes/class-kasa-academy.php';

/**
 * Main instance accessor.
 *
 * @return Kasa_Academy
 */
function kasa_academy() {
	return Kasa_Academy::instance();
}

/**
 * Activation.
 *
 * Roles are written here so a fresh activation has them immediately, rather
 * than waiting for the first admin_init. Everything it calls is idempotent.
 */
function kasa_academy_activate() {
	require_once KASA_ACADEMY_PATH . 'includes/roles/class-kasa-roles.php';
	require_once KASA_ACADEMY_PATH . 'includes/roles/class-kasa-role-migration.php';

	// Only stamp the version if the whole map applied. Activating this plugin
	// before LearnDash means the Facilitator role does not exist yet and gets
	// skipped; leaving the version unset lets the next admin_init finish the
	// job instead of the gate declaring it done.
	if ( Kasa_Roles::install() ) {
		update_option( 'kasa_academy_roles_version', KASA_ROLES_VERSION );
	}

	// maybe_run() rather than run(), so activation also records the migration
	// version. Calling run() directly would leave the option unset and the
	// migration would repeat on the next admin_init. It is idempotent, so that
	// would be harmless, but it would also be needless work on every admin
	// page load until an admin_init finally recorded it.
	Kasa_Role_Migration::maybe_run();
}
register_activation_hook( __FILE__, 'kasa_academy_activate' );

/**
 * Deactivation.
 *
 * Deliberately does not remove roles or capabilities.
 *
 * Loginly's `default_role` setting points at kasa_learner, so if this plugin
 * removed that role on deactivation every new registration would land on a
 * role that no longer exists, and every existing learner would hold a role
 * WordPress could not resolve. Roles are only touched on uninstall, and even
 * then user role assignments are left alone. See uninstall.php.
 */
function kasa_academy_deactivate() {
	// Intentionally empty. See the docblock above before adding anything here.
}
register_deactivation_hook( __FILE__, 'kasa_academy_deactivate' );

kasa_academy();
