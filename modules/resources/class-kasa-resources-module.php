<?php
/**
 * Shared documents on the dashboard.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-kasa-resource-store.php';

/**
 * The handbook, the manuals and the facilitator-only material.
 *
 * Facilitators put these up from the dashboard itself rather than wp-admin,
 * because most of them will never see wp-admin. That makes this the one place
 * in the Academy where a file upload is reachable from the front end, so the
 * three questions are kept apart and each answered in one place:
 *
 *   who may upload      kasa_manage_resources, facilitators and administrators
 *   who may read one    per document, here, in user_can_read()
 *   where the file goes Kasa_Resource_Store, which is the only thing that
 *                       touches the filesystem
 */
class Kasa_Resources_Module extends Kasa_Module {

	/**
	 * The post type holding one document each.
	 *
	 * @var string
	 */
	const POST_TYPE = 'kasa_resource';

	/**
	 * Query variable the download route answers on.
	 *
	 * @var string
	 */
	const DOWNLOAD_VAR = 'kasa_resource';

	/**
	 * Query variable that asks to confirm a removal.
	 *
	 * @var string
	 */
	const CONFIRM_VAR = 'kasa_confirm_remove';

	/**
	 * Audiences a document can be published to.
	 *
	 * Deliberately only two. Milestone 2 already decided this split and gave it
	 * a capability: kasa_view_facilitator_resources is held by facilitators and
	 * administrators, and withheld from learners and partners because the
	 * facilitator material carries safeguarding procedures and the selection
	 * rubric. Inventing a third audience here would put the same decision in two
	 * places.
	 *
	 * @var array
	 */
	const AUDIENCES = array( 'everyone', 'facilitators' );

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'resources';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Shared documents', 'kasa-academy' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'maybe_download' ) );

		add_action( 'admin_post_kasa_upload_resource', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_kasa_delete_resource', array( __CLASS__, 'handle_delete' ) );

		// The dashboard button is not the only way a document can disappear:
		// wp-admin, WP-CLI and a user deletion can all remove the post. Without
		// this the row would go and the file would stay on disk forever.
		add_action( 'before_delete_post', array( __CLASS__, 'forget_file' ), 10, 2 );
	}

	/**
	 * The post type.
	 *
	 * Not public and with no admin screen. A document is not a page: it has no
	 * permalink to reach, it must never turn up in search or a sitemap, and
	 * every read goes through the download route so the capability check cannot
	 * be walked around.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Shared documents', 'kasa-academy' ),
					'singular_name' => __( 'Shared document', 'kasa-academy' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				// A handbook is the Academy's, not the uploader's. Left at the
				// default, deleting a facilitator who has moved on would take
				// every document they ever uploaded off every learner's
				// dashboard, silently and with no trash to restore from.
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'excerpt', 'author' ),
			)
		);
	}

	/**
	 * Remove the stored file when its document is deleted, however that happens.
	 *
	 * before_delete_post rather than deleted_post, because the meta holding the
	 * filename is gone by the time the post is.
	 *
	 * @param int     $post_id Post being deleted.
	 * @param WP_Post $post    The post, passed since WordPress 5.5.
	 * @return void
	 */
	public static function forget_file( $post_id, $post = null ) {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );

		if ( self::POST_TYPE !== $type ) {
			return;
		}

		Kasa_Resource_Store::delete( (string) get_post_meta( $post_id, 'kasa_resource_stored', true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Who may see what                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether this user may upload and remove documents.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return bool
	 */
	public static function user_can_manage( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		return $user_id > 0 && user_can( $user_id, 'kasa_manage_resources' );
	}

	/**
	 * Whether this user may read this document.
	 *
	 * @param int $resource_id Document.
	 * @param int $user_id     Optional. Defaults to the current user.
	 * @return bool
	 */
	public static function user_can_read( $resource_id, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$resource_id = absint( $resource_id );

		if ( self::POST_TYPE !== get_post_type( $resource_id ) || 'publish' !== get_post_status( $resource_id ) ) {
			return false;
		}

		// The dashboard is where documents live, so reaching one means being
		// allowed on the dashboard in the first place.
		if ( ! user_can( $user_id, 'kasa_view_dashboard' ) ) {
			return false;
		}

		if ( 'facilitators' === self::audience( $resource_id ) ) {
			return user_can( $user_id, 'kasa_view_facilitator_resources' );
		}

		return true;
	}

	/**
	 * The audience a document was published to.
	 *
	 * Anything unrecognised is treated as facilitator-only. A document whose
	 * meta went missing should become harder to reach, not easier.
	 *
	 * @param int $resource_id Document.
	 * @return string
	 */
	public static function audience( $resource_id ) {
		$audience = (string) get_post_meta( $resource_id, 'kasa_resource_audience', true );

		return 'everyone' === $audience ? 'everyone' : 'facilitators';
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Every document this user may read.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return array
	 */
	public static function for_user( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'suppress_filters' => false,
			)
		);

		$documents = array();

		foreach ( $posts as $post ) {
			if ( ! self::user_can_read( $post->ID, $user_id ) ) {
				continue;
			}

			$documents[] = array(
				'id'          => (int) $post->ID,
				'title'       => get_the_title( $post ),
				'description' => (string) $post->post_excerpt,
				'audience'    => self::audience( $post->ID ),
				'filename'    => (string) get_post_meta( $post->ID, 'kasa_resource_filename', true ),
				'extension'   => strtoupper( (string) get_post_meta( $post->ID, 'kasa_resource_extension', true ) ),
				'size'        => size_format( (int) get_post_meta( $post->ID, 'kasa_resource_size', true ) ),
				'url'         => self::download_url( $post->ID ),
				'can_remove'  => self::user_can_delete( $post->ID, $user_id ),
			);
		}

		return $documents;
	}

	/**
	 * The download address for a document.
	 *
	 * @param int $resource_id Document.
	 * @return string
	 */
	public static function download_url( $resource_id ) {
		return add_query_arg( self::DOWNLOAD_VAR, absint( $resource_id ), home_url( '/' ) );
	}

	/**
	 * Answer a download request, if this is one.
	 *
	 * @return void
	 */
	public static function maybe_download() {
		if ( is_admin() || ! isset( $_GET[ self::DOWNLOAD_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$resource_id = absint( wp_unslash( $_GET[ self::DOWNLOAD_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $resource_id ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Not auth_redirect(), which lands on wp-login.php. The rest of the
			// dashboard sends people to the site's own login page, and a child
			// following a shared link should not suddenly meet a different one.
			wp_safe_redirect(
				add_query_arg(
					'redirect_to',
					rawurlencode( self::download_url( $resource_id ) ),
					home_url( '/login/' )
				)
			);
			exit;
		}

		// One answer for "you may not" and "there is no such document", so this
		// route cannot be used to find out which documents exist.
		if ( ! self::user_can_read( $resource_id ) ) {
			wp_die(
				esc_html__( 'That document is not available to you.', 'kasa-academy' ),
				esc_html__( 'Not available', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		Kasa_Resource_Store::stream(
			(string) get_post_meta( $resource_id, 'kasa_resource_stored', true ),
			(string) get_post_meta( $resource_id, 'kasa_resource_filename', true )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Accept an upload from the dashboard.
	 *
	 * @return void
	 */
	public static function handle_upload() {
		check_admin_referer( 'kasa_upload_resource' );

		if ( ! self::user_can_manage() ) {
			wp_die(
				esc_html__( 'You are not allowed to add documents.', 'kasa-academy' ),
				esc_html__( 'Not allowed', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		$title = isset( $_POST['kasa_resource_title'] )
			? sanitize_text_field( wp_unslash( $_POST['kasa_resource_title'] ) )
			: '';

		$description = isset( $_POST['kasa_resource_description'] )
			? sanitize_text_field( wp_unslash( $_POST['kasa_resource_description'] ) )
			: '';

		$audience = isset( $_POST['kasa_resource_audience'] )
			? sanitize_key( wp_unslash( $_POST['kasa_resource_audience'] ) )
			: '';

		if ( ! in_array( $audience, self::AUDIENCES, true ) ) {
			$audience = 'facilitators';
		}

		if ( '' === $title ) {
			self::bounce( 'error', __( 'Please give the document a name.', 'kasa-academy' ) );
		}

		$file = isset( $_FILES['kasa_resource_file'] ) ? $_FILES['kasa_resource_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$saved = Kasa_Resource_Store::save( $file );

		if ( is_wp_error( $saved ) ) {
			self::bounce( 'error', $saved->get_error_message() );
		}

		$resource_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => $description,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $resource_id ) ) {
			// The row failed, so the file must not be left behind orphaned.
			Kasa_Resource_Store::delete( $saved['stored'] );
			self::bounce( 'error', __( 'The document could not be saved.', 'kasa-academy' ) );
		}

		update_post_meta( $resource_id, 'kasa_resource_stored', $saved['stored'] );
		update_post_meta( $resource_id, 'kasa_resource_filename', $saved['name'] );
		update_post_meta( $resource_id, 'kasa_resource_extension', $saved['extension'] );
		update_post_meta( $resource_id, 'kasa_resource_size', $saved['size'] );
		update_post_meta( $resource_id, 'kasa_resource_audience', $audience );

		self::bounce(
			'ok',
			sprintf(
				/* translators: %s: the document's name. */
				__( '%s is now on the dashboard.', 'kasa-academy' ),
				$title
			)
		);
	}

	/**
	 * The document this user is being asked to confirm the removal of.
	 *
	 * Removal is permanent and there is no trash, so it takes two deliberate
	 * actions rather than one tap. The confirmation is a plain link back to the
	 * dashboard, which means it works with no JavaScript at all — the phones
	 * these facilitators use cannot be assumed to run any.
	 *
	 * @return int Document ID, or 0.
	 */
	public static function confirming_removal() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading only; the removal itself is a nonced POST.
		if ( ! isset( $_GET[ self::CONFIRM_VAR ] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resource_id = absint( wp_unslash( $_GET[ self::CONFIRM_VAR ] ) );

		return ( $resource_id && self::user_can_delete( $resource_id ) ) ? $resource_id : 0;
	}

	/**
	 * The address that asks for confirmation.
	 *
	 * @param int $resource_id Document.
	 * @return string
	 */
	public static function confirm_url( $resource_id ) {
		return add_query_arg( self::CONFIRM_VAR, absint( $resource_id ), Kasa_Dashboard_Module::url() );
	}

	/**
	 * Take a document down.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		check_admin_referer( 'kasa_delete_resource' );

		$resource_id = isset( $_POST['kasa_resource_id'] ) ? absint( wp_unslash( $_POST['kasa_resource_id'] ) ) : 0;

		if ( ! $resource_id || ! self::user_can_delete( $resource_id ) ) {
			wp_die(
				esc_html__( 'You are not allowed to remove that document.', 'kasa-academy' ),
				esc_html__( 'Not allowed', 'kasa-academy' ),
				array( 'response' => 403 )
			);
		}

		$title = get_the_title( $resource_id );

		Kasa_Resource_Store::delete( (string) get_post_meta( $resource_id, 'kasa_resource_stored', true ) );
		wp_delete_post( $resource_id, true );

		self::bounce(
			'ok',
			sprintf(
				/* translators: %s: the document's name. */
				__( '%s has been removed.', 'kasa-academy' ),
				$title
			)
		);
	}

	/**
	 * Whether this user may remove this document.
	 *
	 * A facilitator may take down what they put up. Tidying up after other
	 * facilitators is an administrator's job, so that one person cannot quietly
	 * remove another's safeguarding material.
	 *
	 * @param int $resource_id Document.
	 * @param int $user_id     Optional. Defaults to the current user.
	 * @return bool
	 */
	public static function user_can_delete( $resource_id, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! self::user_can_manage( $user_id ) ) {
			return false;
		}

		if ( self::POST_TYPE !== get_post_type( $resource_id ) ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return (int) get_post_field( 'post_author', $resource_id ) === $user_id;
	}

	/* ------------------------------------------------------------------ */
	/* Talking back to the dashboard                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Where the message waiting for one user is kept.
	 *
	 * @param int $user_id Whose message.
	 * @return string
	 */
	private static function notice_key( $user_id ) {
		return 'kasa_notice_' . absint( $user_id );
	}

	/**
	 * Go back to the dashboard carrying a message, and stop.
	 *
	 * The message is left in a transient rather than in the URL. Sending the
	 * words themselves through a query string would mean the dashboard printed
	 * whatever a link told it to, inside the Academy's own notice styling — so
	 * anyone could send a child a link that made their own dashboard say
	 * something the Academy never said. Nothing user-supplied reaches the banner
	 * now: the message is written here, on the server, and read back once.
	 *
	 * @param string $kind    'ok' or 'error'.
	 * @param string $message What to say.
	 * @return void
	 */
	private static function bounce( $kind, $message ) {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			set_transient(
				self::notice_key( $user_id ),
				array(
					'kind' => 'ok' === $kind ? 'ok' : 'error',
					'text' => (string) $message,
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( Kasa_Dashboard_Module::url() );
		exit;
	}

	/**
	 * The message left by the last upload or removal, if there is one.
	 *
	 * Read once and cleared, so a refresh does not show it again.
	 *
	 * @return array|null kind and text.
	 */
	public static function notice() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return null;
		}

		$notice = get_transient( self::notice_key( $user_id ) );

		if ( ! is_array( $notice ) || empty( $notice['text'] ) || empty( $notice['kind'] ) ) {
			return null;
		}

		delete_transient( self::notice_key( $user_id ) );

		return array(
			'kind' => 'ok' === $notice['kind'] ? 'ok' : 'error',
			'text' => (string) $notice['text'],
		);
	}
}

add_action(
	'kasa_academy_register_modules',
	function ( $plugin ) {
		$plugin->register_module( new Kasa_Resources_Module() );
	}
);
