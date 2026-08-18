<?php
/**
 * Plugin loader.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kasa Academy.
 *
 * Deliberately thin. Its job is to load the pieces and register the module
 * list, so that the learner dashboard, the learning pathway and the
 * application system can each arrive later as a self-contained module without
 * this file growing.
 *
 * Nothing here calls a LearnDash function while the file is being parsed.
 * LearnDash may load after this plugin, so every such call belongs inside a
 * hook. The two Kasa must-use plugins follow the same rule and it is worth
 * keeping consistent.
 */
final class Kasa_Academy {

	/**
	 * Singleton instance.
	 *
	 * @var Kasa_Academy|null
	 */
	private static $instance = null;

	/**
	 * Registered modules, keyed by slug.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Accessor.
	 *
	 * @return Kasa_Academy
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Nobody clones a singleton by accident.
	 */
	private function __clone() {}

	/**
	 * Load the files this plugin is made of.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once KASA_ACADEMY_PATH . 'includes/class-kasa-module.php';
		require_once KASA_ACADEMY_PATH . 'includes/roles/capabilities.php';
		require_once KASA_ACADEMY_PATH . 'includes/roles/class-kasa-roles.php';
		require_once KASA_ACADEMY_PATH . 'includes/roles/class-kasa-role-migration.php';
		require_once KASA_ACADEMY_PATH . 'includes/class-kasa-organisation.php';
		require_once KASA_ACADEMY_PATH . 'includes/scoping/class-kasa-scope.php';
		require_once KASA_ACADEMY_PATH . 'includes/class-kasa-learndash-compat.php';
		require_once KASA_ACADEMY_PATH . 'includes/enforcement/class-kasa-guards.php';
		require_once KASA_ACADEMY_PATH . 'includes/enforcement/class-kasa-meta-caps.php';
		require_once KASA_ACADEMY_PATH . 'includes/admin/class-kasa-organisation-admin.php';
		require_once KASA_ACADEMY_PATH . 'includes/admin/class-kasa-permissions-matrix.php';
		require_once KASA_ACADEMY_PATH . 'includes/admin/class-kasa-admin-menu.php';
		require_once KASA_ACADEMY_PATH . 'includes/export/class-kasa-export.php';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Kasa_Organisation::init();
		Kasa_LearnDash_Compat::init();
		Kasa_Meta_Caps::init();
		Kasa_Export::init();
		Kasa_Guards::init();

		if ( is_admin() ) {
			Kasa_Organisation_Admin::init();
			Kasa_Admin_Menu::init();
		}

		// Roles are reconciled in the admin only. A visitor never needs this
		// work done on their request, and the roles are already in the
		// database by the time anyone signs in.
		add_action( 'admin_init', array( 'Kasa_Roles', 'maybe_install' ) );
		add_action( 'admin_init', array( 'Kasa_Role_Migration', 'maybe_run' ) );

		// Deliberately outside the version gate. A label sync that failed
		// because LearnDash had not initialised yet must get another chance,
		// and the check itself is one cached option read.
		add_action( 'admin_init', array( 'Kasa_Roles', 'maybe_sync_learndash_group_leader_label' ), 20 );

		// Modules are booted late so they can rely on LearnDash being present.
		add_action( 'plugins_loaded', array( $this, 'boot_modules' ), 20 );
	}

	/**
	 * Translations, for the French and Spanish rollout.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'kasa-academy',
			false,
			dirname( KASA_ACADEMY_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register a module.
	 *
	 * @param Kasa_Module $module Module instance.
	 * @return void
	 */
	public function register_module( $module ) {
		if ( ! $module instanceof Kasa_Module ) {
			return;
		}

		$this->modules[ $module->slug() ] = $module;
	}

	/**
	 * Fetch a registered module.
	 *
	 * @param string $slug Module slug.
	 * @return Kasa_Module|null
	 */
	public function module( $slug ) {
		return isset( $this->modules[ $slug ] ) ? $this->modules[ $slug ] : null;
	}

	/**
	 * Boot every registered module.
	 *
	 * Empty in this milestone. The role and capability layer is deliberately
	 * not a module, because everything else depends on it.
	 *
	 * @return void
	 */
	public function boot_modules() {
		/**
		 * Fires before modules boot, so a module file can register itself.
		 *
		 * @param Kasa_Academy $plugin The plugin instance.
		 */
		do_action( 'kasa_academy_register_modules', $this );

		foreach ( $this->modules as $module ) {
			$module->boot();
		}
	}
}
