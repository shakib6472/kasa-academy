<?php
/**
 * Uninstall.
 *
 * Runs only when the plugin is deleted through WordPress, never on
 * deactivation.
 *
 * What this removes: the capabilities this plugin added, the two roles it
 * created, and its own options.
 *
 * What this deliberately leaves behind: user role assignments. If a learner
 * holds kasa_learner in usermeta, that row stays. Deleting it would strip
 * every learner of their role and there would be no way to tell afterwards
 * which of them had been learners. Reinstalling the plugin recreates the role
 * and every one of those users works again.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/roles/capabilities.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/roles/class-kasa-roles.php';

if ( ! function_exists( 'get_editable_roles' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}

Kasa_Roles::uninstall();

delete_option( 'kasa_academy_learner_migration_version' );
delete_option( 'kasa_academy_matrix_file_version' );
delete_option( 'kasa_academy_advanced_level_corrected' );

/*
 * Loginly's default_role is not reset here.
 *
 * By this point the kasa_learner role is gone, so resetting Loginly to
 * subscriber would be the tidy thing to do. It is left alone on purpose: if
 * this plugin is deleted and reinstalled, registration keeps pointing at the
 * right role throughout. An administrator removing the plugin permanently
 * should set the registration role by hand, which is a visible, deliberate
 * act rather than a silent change made during an uninstall.
 */
