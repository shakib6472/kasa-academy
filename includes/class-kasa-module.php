<?php
/**
 * Base class for Kasa Academy modules.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module is one feature area of the Academy.
 *
 * The learner dashboard, the visual learning pathway and the application
 * system each become a module in a later milestone. They live under modules/,
 * register themselves on the kasa_academy_register_modules action, and are
 * booted after plugins_loaded so LearnDash is guaranteed to be present.
 *
 * A module must never resolve which learners a user may see on its own. It
 * asks the scoping helper, so that the rule lives in exactly one place.
 */
abstract class Kasa_Module {

	/**
	 * Unique module slug.
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Human readable module name.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Register hooks. Called once, after plugins_loaded.
	 *
	 * @return void
	 */
	abstract public function boot();
}
