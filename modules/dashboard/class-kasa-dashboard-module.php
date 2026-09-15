<?php
/**
 * The learner dashboard module.
 *
 * @package KasaAcademy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-kasa-dashboard-data.php';

/**
 * One /dashboard/ URL, a different dashboard behind it for each role.
 *
 * A learner sees their programmes and progress, a facilitator sees the groups
 * they lead, a partner sees their own cohorts. The role decides which, and it
 * is decided here on the server; nothing in the request can change it.
 *
 * This milestone builds the shell: the page, the routing, the dispatch and the
 * signed-out state. The sections themselves come next, so that a routing
 * problem and a data problem never have to be told apart at the same time.
 */
class Kasa_Dashboard_Module extends Kasa_Module {

	/**
	 * The page slug.
	 *
	 * @var string
	 */
	const SLUG = 'dashboard';

	/**
	 * Option holding the ID of the page we created.
	 *
	 * @var string
	 */
	const PAGE_OPTION = 'kasa_academy_dashboard_page_id';

	/**
	 * The views this dashboard can render.
	 *
	 * @var array
	 */
	const VIEWS = array( 'learner', 'facilitator', 'partner' );

	/**
	 * Module slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'dashboard';
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Learner dashboard', 'kasa-academy' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_shortcode( 'kasa_dashboard', array( __CLASS__, 'render' ) );

		// Early enough to beat template_redirect, where the core redirect lives.
		add_action( 'parse_request', array( __CLASS__, 'free_the_dashboard_url' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The page                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * The dashboard page, if there is one.
	 *
	 * @return int Page ID, or 0.
	 */
	public static function page_id() {
		$stored = (int) get_option( self::PAGE_OPTION );

		if ( $stored && 'page' === get_post_type( $stored ) && 'publish' === get_post_status( $stored ) ) {
			return $stored;
		}

		// The option can go stale if somebody rebuilds the site or moves the
		// page, so fall back to the slug before concluding there is none.
		$found = get_page_by_path( self::SLUG );

		return $found instanceof WP_Post ? (int) $found->ID : 0;
	}

	/**
	 * The dashboard URL.
	 *
	 * @return string
	 */
	public static function url() {
		$page_id = self::page_id();

		return $page_id ? (string) get_permalink( $page_id ) : home_url( '/' . self::SLUG . '/' );
	}

	/**
	 * Create the page if it is not there.
	 *
	 * Called on activation. Idempotent, and deliberately unwilling to fight an
	 * administrator: a page already at this slug is adopted rather than
	 * duplicated, and one that was deliberately trashed is left alone.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	public static function install_page() {
		/*
		 * The stored ID comes first, and the slug only after it.
		 *
		 * A site is free to move this page to /my-learning/ or anything else,
		 * and everything else here follows the rename because it resolves the
		 * page by ID. Looking it up by slug alone would not: on the next
		 * activation there would be no page at /dashboard/, so a second, empty
		 * one would be created and this option would be repointed at it,
		 * orphaning the real dashboard the site had been using.
		 */
		$stored = (int) get_option( self::PAGE_OPTION );

		if ( $stored && 'page' === get_post_type( $stored ) && 'publish' === get_post_status( $stored ) ) {
			return $stored;
		}

		$existing = get_page_by_path( self::SLUG, OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			update_option( self::PAGE_OPTION, (int) $existing->ID );

			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'     => __( 'Dashboard', 'kasa-academy' ),
				'post_name'      => self::SLUG,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_content'   => '[kasa_dashboard]',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}

		update_option( self::PAGE_OPTION, (int) $page_id );

		return (int) $page_id;
	}

	/* ------------------------------------------------------------------ */
	/* Routing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Stop WordPress treating /dashboard/ as a shortcut to wp-admin.
	 *
	 * WordPress core hard codes three shortcuts in wp_redirect_admin_locations()
	 * in wp-includes/canonical.php:
	 *
	 *     $admins = array(
	 *         home_url( 'wp-admin', 'relative' ),
	 *         home_url( 'dashboard', 'relative' ),
	 *         home_url( 'admin', 'relative' ),
	 *         ...
	 *     );
	 *
	 * so a page at /dashboard/ is redirected to wp-admin and can never be
	 * reached. That is a hard blocker for a learner dashboard on that URL, and
	 * for a learner it is worse than a dead link: they are bounced towards an
	 * admin screen they are not allowed to open.
	 *
	 * The callback is removed only on a request that is actually for our
	 * dashboard, so /admin and /wp-admin keep working as WordPress intends.
	 * Removing it outright would take away two shortcuts administrators use,
	 * to fix a collision on a third.
	 *
	 * @return void
	 */
	public static function free_the_dashboard_url() {
		if ( is_admin() ) {
			return;
		}

		if ( ! self::request_is_dashboard() ) {
			return;
		}

		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations' );
	}

	/**
	 * Whether this request is for the dashboard page.
	 *
	 * Compared on the path alone, because the query string is irrelevant and
	 * the core check this works around compares the same way.
	 *
	 * @return bool
	 */
	private static function request_is_dashboard() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return false;
		}

		$ours = wp_parse_url( self::url(), PHP_URL_PATH );

		if ( ! is_string( $ours ) ) {
			return false;
		}

		return untrailingslashit( $path ) === untrailingslashit( $ours );
	}

	/* ------------------------------------------------------------------ */
	/* Which dashboard                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Decide which view this user gets.
	 *
	 * The order matches Kasa_Scope: a partner is scoped by organisation and a
	 * facilitator by the groups they lead, and a partner holds both kinds of
	 * capability, so the partner test comes first.
	 *
	 * @param int $user_id Optional. Defaults to the current user.
	 * @return string One of self::VIEWS, or an empty string for nobody.
	 */
	public static function view_for( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id ) {
			return '';
		}

		/*
		 * An administrator may look at any view, because they support the
		 * people using them and cannot help with a screen they cannot see.
		 *
		 * This is the ONLY case where the request decides anything. For every
		 * other role the view comes from the role and ?view= is ignored
		 * completely, so a learner cannot read a facilitator's dashboard by
		 * editing the URL. The scoping helpers would still refuse them the
		 * data, but a screen that half loads and then shows nothing is a
		 * confusing way to be told no.
		 */
		if ( Kasa_Scope::is_unrestricted( $user_id ) ) {
			return self::requested_view();
		}

		if ( user_can( $user_id, 'kasa_view_org_cohorts' ) ) {
			return 'partner';
		}

		if ( user_can( $user_id, 'kasa_view_group_learners' ) ) {
			return 'facilitator';
		}

		if ( user_can( $user_id, 'kasa_view_dashboard' ) ) {
			return 'learner';
		}

		return '';
	}

	/**
	 * The view an administrator asked for, defaulting to the learner's.
	 *
	 * @return string
	 */
	private static function requested_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing a read-only preview, not acting.
		$asked = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

		return in_array( $asked, self::VIEWS, true ) ? $asked : 'learner';
	}

	/**
	 * Whether the current user may switch views.
	 *
	 * @return bool
	 */
	public static function may_switch_view() {
		return Kasa_Scope::is_unrestricted( get_current_user_id() );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Load the stylesheet, and only on the dashboard.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		$page_id = self::page_id();

		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}

		$path = KASA_ACADEMY_PATH . 'modules/dashboard/assets/dashboard.css';

		wp_enqueue_style(
			'kasa-dashboard',
			KASA_ACADEMY_URL . 'modules/dashboard/assets/dashboard.css',
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : KASA_ACADEMY_VERSION
		);
	}

	/**
	 * The shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return self::render_signed_out();
		}

		$view = self::view_for();

		if ( '' === $view ) {
			return self::render_no_access();
		}

		$user_id = get_current_user_id();

		ob_start();
		?>
		<div class="kd" data-view="<?php echo esc_attr( $view ); ?>">
			<?php
			if ( 'learner' === $view ) {
				$programmes = Kasa_Dashboard_Data::learner_programmes( $user_id );

				self::render_hero( $view, Kasa_Dashboard_Data::headline_next_step( $programmes ) );
				self::render_notice();
				self::render_programmes( $programmes );
				self::render_badges( Kasa_Dashboard_Data::badges( $user_id ) );
				self::render_certificates( Kasa_Dashboard_Data::certificates( $user_id, $programmes ) );
			} else {
				$groups = Kasa_Dashboard_Data::groups_for( $user_id );

				self::render_hero( $view, null, $groups );
				self::render_notice();
				self::render_groups( $groups, $view );
				self::render_roster( $groups, $view );
			}

			/*
			 * Documents are shown to every role, because the handbook is for
			 * everyone. Which ones appear is decided per document, by
			 * Kasa_Resources_Module, not by the view being rendered: an
			 * administrator previewing the learner view is still an
			 * administrator, and hiding a file from a preview would only
			 * pretend otherwise.
			 */
			self::render_resources(
				Kasa_Resources_Module::for_user( $user_id ),
				Kasa_Resources_Module::user_can_manage( $user_id )
			);

			self::render_application( $user_id );

			self::render_placeholder( $view );

			if ( self::may_switch_view() ) {
				self::render_admin_bar( $view );
			}
			?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Sections                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * A progress ring.
	 *
	 * The same shape the course page uses, so a learner meets one idea rather
	 * than two. A number is abstract; a ring filling up is not, and that
	 * matters most for the youngest learners here.
	 *
	 * @param int    $percent 0 to 100.
	 * @param string $colour  Stroke colour.
	 * @param int    $size    Pixel width and height.
	 * @return void
	 */
	private static function render_ring( $percent, $colour, $size = 84 ) {
		$percent = max( 0, min( 100, (int) $percent ) );
		$radius  = ( $size / 2 ) - 6;
		$length  = 2 * M_PI * $radius;
		$offset  = $length * ( 1 - ( $percent / 100 ) );
		?>
		<div class="kd-ring">
			<svg width="<?php echo esc_attr( (string) $size ); ?>" height="<?php echo esc_attr( (string) $size ); ?>"
			     viewBox="0 0 <?php echo esc_attr( (string) $size ); ?> <?php echo esc_attr( (string) $size ); ?>"
			     role="img"
			     aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percentage complete. */ __( '%d percent complete', 'kasa-academy' ), $percent ) ); ?>">
				<circle cx="<?php echo esc_attr( (string) ( $size / 2 ) ); ?>" cy="<?php echo esc_attr( (string) ( $size / 2 ) ); ?>"
				        r="<?php echo esc_attr( (string) $radius ); ?>" fill="none"
				        stroke="<?php echo esc_attr( $colour ); ?>" stroke-opacity="0.15" stroke-width="9"/>
				<circle cx="<?php echo esc_attr( (string) ( $size / 2 ) ); ?>" cy="<?php echo esc_attr( (string) ( $size / 2 ) ); ?>"
				        r="<?php echo esc_attr( (string) $radius ); ?>" fill="none"
				        stroke="<?php echo esc_attr( $colour ); ?>" stroke-width="9" stroke-linecap="round"
				        stroke-dasharray="<?php echo esc_attr( (string) round( $length, 2 ) ); ?>"
				        stroke-dashoffset="<?php echo esc_attr( (string) round( $offset, 2 ) ); ?>"/>
			</svg>
			<span class="kd-ring-num"><?php echo esc_html( $percent . '%' ); ?></span>
		</div>
		<?php
	}

	/**
	 * The learner's programme cards.
	 *
	 * @param array $programmes From Kasa_Dashboard_Data.
	 * @return void
	 */
	private static function render_programmes( $programmes ) {
		?>
		<div class="kd-head">
			<h2><?php esc_html_e( 'My programmes', 'kasa-academy' ); ?></h2>
			<p><?php esc_html_e( 'Only the programmes you are enrolled in appear here.', 'kasa-academy' ); ?></p>
		</div>
		<?php

		if ( empty( $programmes ) ) {
			?>
			<div class="kd-empty">
				<div class="kd-empty-mark" aria-hidden="true">&#127793;</div>
				<strong><?php esc_html_e( 'Nothing to learn yet', 'kasa-academy' ); ?></strong>
				<p><?php esc_html_e( 'Once you are enrolled in a programme it will appear here, with your progress.', 'kasa-academy' ); ?></p>
				<a class="kd-btn kd-btn-solid" href="<?php echo esc_url( home_url( '/programs/' ) ); ?>">
					<?php esc_html_e( 'Browse programmes', 'kasa-academy' ); ?>
				</a>
			</div>
			<?php
			return;
		}
		?>
		<div class="kd-cards">
			<?php foreach ( $programmes as $p ) : ?>
				<a class="kd-card" href="<?php echo esc_url( $p['url'] ); ?>"
				   style="--kd-p:<?php echo esc_attr( $p['primary'] ); ?>;--kd-s:<?php echo esc_attr( $p['secondary'] ); ?>;--kd-p-rgb:<?php echo esc_attr( $p['rgb'] ); ?>">
					<div class="kd-card-top">
						<div class="kd-card-emoji" aria-hidden="true"><?php echo esc_html( $p['emoji'] ); ?></div>
						<div>
							<h3><?php echo esc_html( $p['title'] ); ?></h3>
							<?php if ( $p['total'] ) : ?>
								<div class="kd-card-meta">
									<?php
									printf(
										/* translators: 1: steps completed, 2: steps in total. */
										esc_html__( '%1$d of %2$d steps', 'kasa-academy' ),
										(int) $p['completed'],
										(int) $p['total']
									);
									?>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<div class="kd-card-row">
						<?php self::render_ring( $p['percent'], $p['primary'] ); ?>
						<div class="kd-ring-side">
							<div class="kd-ring-lab"><?php esc_html_e( 'Your journey', 'kasa-academy' ); ?></div>
							<span class="kd-pill kd-pill-tag"><?php echo esc_html( $p['status_label'] ); ?></span>
						</div>
					</div>

					<span class="kd-card-go">
						<?php
						echo 'completed' === $p['status']
							? esc_html__( 'Look back at it', 'kasa-academy' )
							: esc_html__( 'Keep going', 'kasa-academy' );
						?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The facilitator's groups, or the partner's cohorts.
	 *
	 * @param array  $groups From Kasa_Dashboard_Data.
	 * @param string $view   Current view.
	 * @return void
	 */
	private static function render_groups( $groups, $view ) {
		$is_partner = ( 'partner' === $view );
		?>
		<div class="kd-head">
			<h2><?php echo $is_partner ? esc_html__( 'My cohorts', 'kasa-academy' ) : esc_html__( 'My groups', 'kasa-academy' ); ?></h2>
			<p>
				<?php
				echo $is_partner
					? esc_html__( 'The cohorts belonging to your organisation, and how they are getting on.', 'kasa-academy' )
					: esc_html__( 'Only the groups you lead. Everything here is scoped to them.', 'kasa-academy' );
				?>
			</p>
		</div>
		<?php

		if ( empty( $groups ) ) {
			?>
			<div class="kd-empty">
				<div class="kd-empty-mark" aria-hidden="true">&#128101;</div>
				<strong><?php esc_html_e( 'No groups yet', 'kasa-academy' ); ?></strong>
				<p>
					<?php
					echo $is_partner
						? esc_html__( 'Once your organisation has a cohort it will appear here. If you expected one already, an administrator can check which organisation your account belongs to.', 'kasa-academy' )
						: esc_html__( 'Once you are made the leader of a group it will appear here.', 'kasa-academy' );
					?>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<div class="kd-cards">
			<?php foreach ( $groups as $g ) : ?>
				<a class="kd-card" href="<?php echo esc_url( $g['url'] ); ?>"
				   style="--kd-p:<?php echo esc_attr( $g['primary'] ); ?>;--kd-s:<?php echo esc_attr( $g['secondary'] ); ?>;--kd-p-rgb:<?php echo esc_attr( $g['rgb'] ); ?>">
					<div class="kd-card-top">
						<div class="kd-card-emoji" aria-hidden="true"><?php echo esc_html( $g['emoji'] ); ?></div>
						<div>
							<h3><?php echo esc_html( $g['title'] ); ?></h3>
							<div class="kd-card-meta">
								<?php
								printf(
									/* translators: %d: number of learners. */
									esc_html( _n( '%d learner', '%d learners', (int) $g['learners'], 'kasa-academy' ) ),
									(int) $g['learners']
								);

								if ( $g['course_name'] ) {
									echo ' &middot; ' . esc_html( $g['course_name'] );
								}
								?>
							</div>
						</div>
					</div>

					<div class="kd-card-row">
						<?php self::render_ring( $g['percent'], $g['primary'] ); ?>
						<div class="kd-ring-side">
							<div class="kd-ring-lab"><?php esc_html_e( 'Group average', 'kasa-academy' ); ?></div>
							<?php if ( $g['not_started'] > 0 ) : ?>
								<span class="kd-pill kd-pill-tag">
									<?php
									printf(
										/* translators: %d: number of learners who have not begun. */
										esc_html( _n( '%d not started', '%d not started', (int) $g['not_started'], 'kasa-academy' ) ),
										(int) $g['not_started']
									);
									?>
								</span>
							<?php else : ?>
								<span class="kd-pill kd-pill-tag"><?php esc_html_e( 'Everyone has begun', 'kasa-academy' ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<span class="kd-card-go">
						<?php
						echo $is_partner
							? esc_html__( 'Open cohort', 'kasa-academy' )
							: esc_html__( 'Open group', 'kasa-academy' );
						?>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The greeting, which is the one part of the shell that differs by role.
	 *
	 * @param string     $view Current view.
	 * @param array|null $next The learner's single next step, if there is one.
	 * @param array      $groups Groups, for the facilitator and partner counts.
	 * @return void
	 */
	private static function render_hero( $view, $next = null, $groups = array() ) {
		$user  = wp_get_current_user();
		$first = $user->first_name ? $user->first_name : $user->display_name;

		$copy = array(
			'learner'     => array(
				/* translators: %s: the learner's first name. */
				'title' => sprintf( __( 'Welcome back, %s', 'kasa-academy' ), $first ),
				'lede'  => __( 'Your programmes, your progress and your badges all live here.', 'kasa-academy' ),
				'brow'  => __( 'My Academy', 'kasa-academy' ),
			),
			'facilitator' => array(
				/* translators: %s: the facilitator's first name. */
				'title' => sprintf( __( 'Welcome back, %s', 'kasa-academy' ), $first ),
				'lede'  => __( 'The groups you lead, and how your learners are getting on.', 'kasa-academy' ),
				'brow'  => __( 'Facilitator', 'kasa-academy' ),
			),
			'partner'     => array(
				'title' => $first,
				'lede'  => __( 'Your cohorts, with who has completed what.', 'kasa-academy' ),
				'brow'  => __( 'Implementation Partner', 'kasa-academy' ),
			),
		);

		$c = isset( $copy[ $view ] ) ? $copy[ $view ] : $copy['learner'];
		?>
		<section class="kd-hero">
			<span class="kd-glyph kd-g1" aria-hidden="true">&lt;/&gt;</span>
			<span class="kd-glyph kd-g2" aria-hidden="true">&#9670;</span>
			<span class="kd-glyph kd-g3" aria-hidden="true">&#10022;</span>
			<span class="kd-glyph kd-g4" aria-hidden="true">&#9650;</span>

			<p class="kd-eyebrow"><?php echo esc_html( $c['brow'] ); ?></p>
			<h1 class="kd-title"><?php echo esc_html( $c['title'] ); ?></h1>
			<p class="kd-lede"><?php echo esc_html( $c['lede'] ); ?></p>

			<?php
			if ( 'learner' === $view && $next ) {
				self::render_next_step( $next );
			}

			if ( 'learner' !== $view && $groups ) {
				self::render_group_stats( $groups, $view );
			}
			?>
		</section>
		<?php
	}

	/**
	 * The one button that says what to do now.
	 *
	 * The whole point of the dashboard for a young learner. Four cards and no
	 * suggestion is a decision to make; one button is a thing to do.
	 *
	 * @param array $next course and step.
	 * @return void
	 */
	private static function render_next_step( $next ) {
		?>
		<div class="kd-next">
			<div class="kd-next-what">
				<small><?php esc_html_e( 'Pick up where you left off', 'kasa-academy' ); ?></small>
				<strong><?php echo esc_html( $next['step']['title'] ); ?></strong>
			</div>
			<a class="kd-btn kd-btn-white" href="<?php echo esc_url( $next['step']['url'] ); ?>">
				<?php esc_html_e( 'Continue', 'kasa-academy' ); ?> &rarr;
			</a>
		</div>
		<?php
	}

	/**
	 * Counts across a facilitator's groups or a partner's cohorts.
	 *
	 * @param array  $groups From Kasa_Dashboard_Data.
	 * @param string $view   Current view.
	 * @return void
	 */
	private static function render_group_stats( $groups, $view ) {
		$learners = 0;
		$sum      = 0;
		$done     = 0;

		foreach ( $groups as $g ) {
			$learners += (int) $g['learners'];
			$sum      += (int) $g['percent'];
			$done     += (int) $g['completed'];
		}

		$average = $groups ? (int) round( $sum / count( $groups ) ) : 0;

		$stats = array(
			array(
				'n' => count( $groups ),
				'l' => 'partner' === $view ? __( 'Cohorts', 'kasa-academy' ) : __( 'Groups', 'kasa-academy' ),
			),
			array( 'n' => $learners, 'l' => __( 'Learners', 'kasa-academy' ) ),
			array( 'n' => $average . '%', 'l' => __( 'Average progress', 'kasa-academy' ) ),
			array( 'n' => $done, 'l' => __( 'Completed', 'kasa-academy' ) ),
		);
		?>
		<div class="kd-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="kd-stat">
					<b><?php echo esc_html( (string) $stat['n'] ); ?></b>
					<span><?php echo esc_html( $stat['l'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The badge wall.
	 *
	 * Earned badges are in colour and unearned ones are grey, because the whole
	 * point of showing a badge nobody has won yet is to make it look winnable.
	 * The greying is done with a filter on the mark alone, so the name of the
	 * badge stays readable — a grey badge is a goal, not a disabled control.
	 *
	 * Nothing is rendered at all when there are no badges, which is the state on
	 * a site with no GamiPress or no achievements.
	 *
	 * @param array $badges From Kasa_Dashboard_Data.
	 * @return void
	 */
	private static function render_badges( $badges ) {
		if ( empty( $badges ) ) {
			return;
		}

		$won = 0;

		foreach ( $badges as $badge ) {
			if ( $badge['earned'] ) {
				$won++;
			}
		}
		?>
		<div class="kd-head">
			<h2><?php esc_html_e( 'My badges', 'kasa-academy' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: badges earned, 2: badges available in total. */
					esc_html__( '%1$d of %2$d won so far.', 'kasa-academy' ),
					(int) $won,
					count( $badges )
				);
				?>
			</p>
		</div>

		<!-- role="list" is not redundant: WebKit drops list semantics from a ul
		     whose list-style is none, and VoiceOver on iOS then announces eight
		     loose runs of text instead of "list, 8 items". -->
		<ul class="kd-badges" role="list">
			<?php foreach ( $badges as $badge ) : ?>
				<li class="kd-badge<?php echo $badge['earned'] ? '' : ' is-locked'; ?>"
				    style="--kd-p:<?php echo esc_attr( $badge['primary'] ); ?>">
					<span class="kd-badge-mark" aria-hidden="true">
						<?php if ( '' !== $badge['image'] ) : ?>
							<img src="<?php echo esc_url( $badge['image'] ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<?php echo esc_html( $badge['emoji'] ); ?>
						<?php endif; ?>
					</span>

					<span class="kd-badge-name"><?php echo esc_html( $badge['title'] ); ?></span>

					<?php if ( '' !== $badge['excerpt'] ) : ?>
						<span class="kd-badge-note"><?php echo esc_html( $badge['excerpt'] ); ?></span>
					<?php endif; ?>

					<span class="kd-badge-state">
						<?php
						if ( $badge['earned'] ) {
							echo $badge['earned_on']
								? esc_html(
									sprintf(
										/* translators: %s: the date the badge was won. */
										__( 'Won %s', 'kasa-academy' ),
										date_i18n( get_option( 'date_format' ), $badge['earned_on'] )
									)
								)
								: esc_html__( 'Won', 'kasa-academy' );
						} else {
							esc_html_e( 'Not yet', 'kasa-academy' );
						}
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Certificates, earned and still to be earned.
	 *
	 * A programme with no certificate set up produces nothing here, so this
	 * section is silent rather than broken on a site where certificates were
	 * never configured. A programme that has one but is unfinished shows it
	 * locked, with how far there is left to go.
	 *
	 * @param array $certificates From Kasa_Dashboard_Data.
	 * @return void
	 */
	private static function render_certificates( $certificates ) {
		if ( empty( $certificates ) ) {
			return;
		}
		?>
		<div class="kd-head">
			<h2><?php esc_html_e( 'My certificates', 'kasa-academy' ); ?></h2>
			<p><?php esc_html_e( 'Finish a programme and its certificate unlocks here.', 'kasa-academy' ); ?></p>
		</div>

		<div class="kd-cards">
			<?php foreach ( $certificates as $certificate ) : ?>
				<div class="kd-cert<?php echo $certificate['earned'] ? '' : ' is-locked'; ?>"
				     style="--kd-p:<?php echo esc_attr( $certificate['primary'] ); ?>;--kd-s:<?php echo esc_attr( $certificate['secondary'] ); ?>;--kd-p-rgb:<?php echo esc_attr( $certificate['rgb'] ); ?>">
					<div class="kd-cert-seal" aria-hidden="true">
						<?php echo $certificate['earned'] ? '🏆' : '🔒'; ?>
					</div>

					<h3><?php echo esc_html( $certificate['course'] ); ?></h3>

					<?php if ( $certificate['earned'] ) : ?>
						<p><?php esc_html_e( 'You finished it. This one is yours to keep.', 'kasa-academy' ); ?></p>
						<?php
						// A learner who finishes two programmes gets two links, and
						// out of context both would read only "View and download".
						// The label names the programme and warns about the new tab;
						// the visible words stay short.
						$label = sprintf(
							/* translators: %s: programme name. */
							__( 'View and download your %s certificate (opens in a new tab)', 'kasa-academy' ),
							$certificate['course']
						);
						?>
						<a class="kd-btn kd-btn-solid" href="<?php echo esc_url( $certificate['url'] ); ?>"
						   target="_blank" rel="noopener"
						   aria-label="<?php echo esc_attr( $label ); ?>">
							<?php esc_html_e( 'View and download', 'kasa-academy' ); ?>
						</a>
					<?php else : ?>
						<p>
							<?php
							printf(
								/* translators: %d: how far through the programme the learner is, as a percentage. */
								esc_html__( 'You are %d%% of the way there. Finish the programme to unlock it.', 'kasa-academy' ),
								(int) $certificate['percent']
							);
							?>
						</p>
						<span class="kd-pill kd-pill-tag"><?php esc_html_e( 'Locked for now', 'kasa-academy' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Who is in each group, and how far along they are.
	 *
	 * A facilitator and a partner see the same roster. The difference the brief
	 * draws between them is quiz answers and written reflections — children's own
	 * words, which a partner organisation has no need for — and none of that is
	 * on this page at all. Both hold kasa_view_group_learners, and the list of
	 * groups came from Kasa_Scope, so a learner outside their scope cannot appear
	 * here however it is reached.
	 *
	 * Each group is a native <details>. A facilitator with three groups of thirty
	 * would otherwise scroll past ninety rows to reach anything below, and the
	 * disclosure works with no JavaScript and answers to the keyboard, which the
	 * phones these facilitators share cannot be assumed to do better.
	 *
	 * @param array  $groups From Kasa_Dashboard_Data.
	 * @param string $view   Current view.
	 * @return void
	 */
	private static function render_roster( $groups, $view ) {
		$with_learners = array();

		foreach ( $groups as $group ) {
			if ( ! empty( $group['roster'] ) ) {
				$with_learners[] = $group;
			}
		}

		if ( empty( $with_learners ) ) {
			return;
		}

		// One group opens on arrival; several stay shut so the page is still
		// scannable and the facilitator chooses which to open.
		$open = 1 === count( $with_learners );
		?>
		<div class="kd-head">
			<h2><?php esc_html_e( 'Who is in them', 'kasa-academy' ); ?></h2>
			<p>
				<?php
				echo 'partner' === $view
					? esc_html__( 'Everyone in your cohorts, and how far they have got.', 'kasa-academy' )
					: esc_html__( 'Everyone in your groups, and how far they have got.', 'kasa-academy' );
				?>
			</p>
		</div>

		<?php foreach ( $with_learners as $group ) : ?>
			<details class="kd-roster" <?php echo $open ? 'open' : ''; ?>
			         style="--kd-p:<?php echo esc_attr( $group['primary'] ); ?>;--kd-p-rgb:<?php echo esc_attr( $group['rgb'] ); ?>">
				<summary>
					<span class="kd-roster-name"><?php echo esc_html( $group['title'] ); ?></span>
					<span class="kd-roster-count">
						<?php
						printf(
							/* translators: %d: how many learners are in this group. */
							esc_html( _n( '%d learner', '%d learners', count( $group['roster'] ), 'kasa-academy' ) ),
							count( $group['roster'] )
						);
						?>
					</span>
				</summary>

				<ul class="kd-people" role="list">
					<?php foreach ( $group['roster'] as $learner ) : ?>
						<li class="kd-person is-<?php echo esc_attr( $learner['status'] ); ?>">
							<span class="kd-person-name"><?php echo esc_html( $learner['name'] ); ?></span>

							<span class="kd-person-bar" role="img"
							      aria-label="<?php
									echo esc_attr(
										sprintf(
											/* translators: 1: learner name, 2: percentage complete. */
											__( '%1$s, %2$d%% complete', 'kasa-academy' ),
											$learner['name'],
											$learner['percent']
										)
									);
									?>">
								<span style="width:<?php echo esc_attr( max( 2, $learner['percent'] ) ); ?>%"></span>
							</span>

							<span class="kd-person-pc" aria-hidden="true"><?php echo esc_html( $learner['percent'] ); ?>%</span>
							<span class="kd-pill kd-pill-state"><?php echo esc_html( $learner['status_label'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * What the last upload or removal had to say.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$notice = Kasa_Resources_Module::notice();

		if ( ! $notice ) {
			return;
		}
		?>
		<div class="kd-notice kd-notice-<?php echo esc_attr( $notice['kind'] ); ?>" role="status">
			<span aria-hidden="true"><?php echo 'ok' === $notice['kind'] ? '&#10003;' : '&#9888;'; ?></span>
			<?php echo esc_html( $notice['text'] ); ?>
		</div>
		<?php
	}

	/**
	 * The documents this person may download.
	 *
	 * A learner sees the handbook and the manuals. A facilitator sees those and
	 * the facilitator-only material, and gets the form to add more. Nobody sees
	 * a section at all until there is something in it, except a facilitator, who
	 * needs somewhere to put the first one.
	 *
	 * @param array $documents   From Kasa_Resources_Module::for_user().
	 * @param bool  $can_manage  Whether this user may upload and remove.
	 * @return void
	 */
	private static function render_resources( $documents, $can_manage ) {
		if ( empty( $documents ) && ! $can_manage ) {
			return;
		}

		$confirming = Kasa_Resources_Module::confirming_removal();
		?>
		<div class="kd-head">
			<h2><?php esc_html_e( 'Downloads', 'kasa-academy' ); ?></h2>
			<p><?php esc_html_e( 'Handbooks, manuals and rulebooks to keep.', 'kasa-academy' ); ?></p>
		</div>

		<?php if ( empty( $documents ) ) : ?>
			<div class="kd-empty">
				<div class="kd-empty-mark" aria-hidden="true">&#128218;</div>
				<strong><?php esc_html_e( 'Nothing here yet', 'kasa-academy' ); ?></strong>
				<p><?php esc_html_e( 'Add the first document below and everyone who should see it will find it here.', 'kasa-academy' ); ?></p>
			</div>
		<?php else : ?>
			<ul class="kd-docs" role="list">
				<?php foreach ( $documents as $document ) : ?>
					<li class="kd-doc">
						<span class="kd-doc-kind" aria-hidden="true"><?php echo esc_html( $document['extension'] ); ?></span>

						<span class="kd-doc-about">
							<strong><?php echo esc_html( $document['title'] ); ?></strong>
							<?php if ( '' !== $document['description'] ) : ?>
								<small><?php echo esc_html( $document['description'] ); ?></small>
							<?php endif; ?>
							<small class="kd-doc-meta">
								<?php
								echo esc_html( $document['size'] );

								if ( 'facilitators' === $document['audience'] ) {
									echo ' &middot; ';
									esc_html_e( 'Facilitators only', 'kasa-academy' );
								}
								?>
							</small>
						</span>

						<a class="kd-btn kd-btn-ghost kd-doc-get" href="<?php echo esc_url( $document['url'] ); ?>"
						   aria-label="<?php
							echo esc_attr(
								sprintf(
									/* translators: 1: the document's name, 2: file type, 3: file size. */
									__( 'Download %1$s, %2$s, %3$s', 'kasa-academy' ),
									$document['title'],
									$document['extension'],
									$document['size']
								)
							);
							?>">
							<?php esc_html_e( 'Download', 'kasa-academy' ); ?>
						</a>

						<?php if ( ! empty( $document['can_remove'] ) ) : ?>
							<?php if ( $confirming === $document['id'] ) : ?>
								<div class="kd-doc-confirm">
									<strong><?php esc_html_e( 'Remove this for everyone?', 'kasa-academy' ); ?></strong>
									<span><?php esc_html_e( 'It cannot be undone, and nobody will be able to download it again.', 'kasa-academy' ); ?></span>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="kasa_delete_resource" />
										<input type="hidden" name="kasa_resource_id" value="<?php echo esc_attr( $document['id'] ); ?>" />
										<?php wp_nonce_field( 'kasa_delete_resource' ); ?>
										<button type="submit" class="kd-btn kd-btn-danger">
											<?php
											printf(
												/* translators: %s: the document's name. */
												esc_html__( 'Yes, remove %s', 'kasa-academy' ),
												esc_html( $document['title'] )
											);
											?>
										</button>
									</form>

									<a class="kd-linkish" href="<?php echo esc_url( Kasa_Dashboard_Module::url() ); ?>">
										<?php esc_html_e( 'Keep it', 'kasa-academy' ); ?>
									</a>
								</div>
							<?php else : ?>
								<a class="kd-linkish kd-doc-remove"
								   href="<?php echo esc_url( Kasa_Resources_Module::confirm_url( $document['id'] ) ); ?>"
								   aria-label="<?php
									echo esc_attr(
										sprintf(
											/* translators: %s: the document's name. */
											__( 'Remove %s', 'kasa-academy' ),
											$document['title']
										)
									);
									?>">
									<?php esc_html_e( 'Remove', 'kasa-academy' ); ?>
								</a>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php
		if ( $can_manage ) {
			self::render_upload_form();
		}
	}

	/**
	 * The form a facilitator adds a document with.
	 *
	 * On the dashboard rather than in wp-admin, because most facilitators will
	 * never open wp-admin. The audience choice is the important field, so it is
	 * two plain radio buttons that say who ends up able to read the file, rather
	 * than a select whose default nobody reads.
	 *
	 * @return void
	 */
	private static function render_upload_form() {
		$types = array_keys( Kasa_Resource_Store::allowed_types() );
		?>
		<form class="kd-upload" method="post" enctype="multipart/form-data"
		      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

			<input type="hidden" name="action" value="kasa_upload_resource" />
			<?php wp_nonce_field( 'kasa_upload_resource' ); ?>

			<h3><?php esc_html_e( 'Add a document', 'kasa-academy' ); ?></h3>

			<p class="kd-field">
				<label for="kasa-doc-title"><?php esc_html_e( 'Name', 'kasa-academy' ); ?></label>
				<input type="text" id="kasa-doc-title" name="kasa_resource_title" required
				       placeholder="<?php esc_attr_e( 'Competition Manual', 'kasa-academy' ); ?>" />
			</p>

			<p class="kd-field">
				<label for="kasa-doc-description"><?php esc_html_e( 'One line about it', 'kasa-academy' ); ?></label>
				<input type="text" id="kasa-doc-description" name="kasa_resource_description"
				       placeholder="<?php esc_attr_e( 'What it is for, in a few words', 'kasa-academy' ); ?>" />
			</p>

			<fieldset class="kd-field">
				<legend><?php esc_html_e( 'Who can download it', 'kasa-academy' ); ?></legend>

				<label class="kd-choice">
					<input type="radio" name="kasa_resource_audience" value="everyone" />
					<span>
						<strong><?php esc_html_e( 'Everyone', 'kasa-academy' ); ?></strong>
						<small><?php esc_html_e( 'Learners, facilitators and partners.', 'kasa-academy' ); ?></small>
					</span>
				</label>

				<label class="kd-choice">
					<input type="radio" name="kasa_resource_audience" value="facilitators" checked />
					<span>
						<strong><?php esc_html_e( 'Facilitators only', 'kasa-academy' ); ?></strong>
						<small><?php esc_html_e( 'For rubrics, safeguarding and anything a learner should not read.', 'kasa-academy' ); ?></small>
					</span>
				</label>
			</fieldset>

			<p class="kd-field">
				<label for="kasa-doc-file"><?php esc_html_e( 'The file', 'kasa-academy' ); ?></label>
				<input type="file" id="kasa-doc-file" name="kasa_resource_file" required
				       accept="<?php echo esc_attr( '.' . implode( ',.', $types ) ); ?>" />
				<small>
					<?php
					printf(
						/* translators: 1: list of file types, 2: the largest allowed size. */
						esc_html__( '%1$s, up to %2$s.', 'kasa-academy' ),
						esc_html( strtoupper( implode( ', ', $types ) ) ),
						esc_html( size_format( Kasa_Resource_Store::max_bytes() ) )
					);
					?>
				</small>
			</p>

			<button type="submit" class="kd-btn kd-btn-solid">
				<?php esc_html_e( 'Add it to the dashboard', 'kasa-academy' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Where this person's application has got to.
	 *
	 * Shown to everyone who may see their own application, which is every role:
	 * partners apply on behalf of an organisation, and many facilitators came
	 * through the mentor pathway themselves.
	 *
	 * With no application system connected the fallback is shown instead. It
	 * deliberately does not say "you have not applied", because nobody has told
	 * us that — it says applications are coming and points at the programmes.
	 *
	 * @param int $user_id Whose dashboard this is.
	 * @return void
	 */
	private static function render_application( $user_id ) {
		if ( ! user_can( $user_id, 'kasa_view_own_application' ) ) {
			return;
		}

		$status = Kasa_Application_Status::for_user( $user_id );

		if ( $status ) {
			$wording = Kasa_Application_Status::wording();
			$card    = $wording[ $status['state'] ];
			$state   = $status['state'];
		} else {
			$card  = Kasa_Application_Status::fallback();
			$state = 'unknown';
		}
		?>
		<div class="kd-head">
			<h2><?php esc_html_e( 'My application', 'kasa-academy' ); ?></h2>
		</div>

		<div class="kd-appl kd-appl-<?php echo esc_attr( $card['tone'] ); ?>" data-state="<?php echo esc_attr( $state ); ?>">
			<div class="kd-appl-mark" aria-hidden="true"><?php echo wp_kses_post( $card['mark'] ); ?></div>

			<div class="kd-appl-say">
				<span class="kd-pill kd-appl-pill"><?php echo esc_html( $card['pill'] ); ?></span>
				<h3><?php echo esc_html( $card['head'] ); ?></h3>
				<p><?php echo esc_html( $card['body'] ); ?></p>

				<?php if ( $status && '' !== $status['note'] ) : ?>
					<p class="kd-appl-note"><?php echo esc_html( $status['note'] ); ?></p>
				<?php endif; ?>

				<?php if ( $status && $status['since'] ) : ?>
					<p class="kd-appl-when">
						<?php
						printf(
							/* translators: %s: a date. */
							esc_html__( 'Last changed %s', 'kasa-academy' ),
							esc_html( date_i18n( get_option( 'date_format' ), $status['since'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $card['cta'] ) ) : ?>
					<a class="kd-btn kd-btn-solid" href="<?php echo esc_url( Kasa_Application_Status::action_url() ); ?>">
						<?php echo esc_html( Kasa_Application_Status::action_label() ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A placeholder while the sections are being built.
	 *
	 * Says plainly that it is unfinished. A dashboard that looks empty and a
	 * dashboard that is not built yet are very different things, and only one
	 * of them is worth reporting as a bug.
	 *
	 * @param string $view Current view.
	 * @return void
	 */
	private static function render_placeholder( $view ) {
		$next = array();

		// Every section of every view is built, so the dashboard no longer
		// promises anything. Kept because the next milestone will have
		// something to say here again.
		if ( ! isset( $next[ $view ] ) ) {
			return;
		}
		?>
		<div class="kd-empty">
			<div class="kd-empty-mark" aria-hidden="true">&#128679;</div>
			<strong><?php esc_html_e( 'Being built', 'kasa-academy' ); ?></strong>
			<p><?php echo esc_html( isset( $next[ $view ] ) ? $next[ $view ] : '' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The administrator's view switch.
	 *
	 * @param string $view Current view.
	 * @return void
	 */
	private static function render_admin_bar( $current ) {
		$labels = array(
			'learner'     => __( 'View as learner', 'kasa-academy' ),
			'facilitator' => __( 'View as facilitator', 'kasa-academy' ),
			'partner'     => __( 'View as partner', 'kasa-academy' ),
		);
		?>
		<div class="kd-adminbar">
			<b><?php esc_html_e( 'Administrator', 'kasa-academy' ); ?></b>
			<span><?php esc_html_e( 'You are previewing what another role sees. Nobody else can change this.', 'kasa-academy' ); ?></span>
			<span class="kd-adminbar-spacer"></span>

			<?php foreach ( self::VIEWS as $view ) : ?>
				<a class="kd-adminbar-btn<?php echo $view === $current ? ' is-on' : ''; ?>"
				   href="<?php echo esc_url( add_query_arg( 'view', $view, self::url() ) ); ?>"
				   aria-current="<?php echo $view === $current ? 'page' : 'false'; ?>">
					<?php echo esc_html( $labels[ $view ] ); ?>
				</a>
			<?php endforeach; ?>

			<a class="kd-adminbar-btn" href="<?php echo esc_url( admin_url() ); ?>">
				<?php esc_html_e( 'Go to wp-admin', 'kasa-academy' ); ?> &rarr;
			</a>
		</div>
		<?php
	}

	/**
	 * What a signed-out visitor gets.
	 *
	 * Sent to Loginly's login page, which is where this site signs people in,
	 * rather than wp-login.php.
	 *
	 * @return string
	 */
	private static function render_signed_out() {
		$login = home_url( '/login/' );

		ob_start();
		?>
		<div class="kd">
			<div class="kd-empty">
				<div class="kd-empty-mark" aria-hidden="true">&#128274;</div>
				<strong><?php esc_html_e( 'Please sign in', 'kasa-academy' ); ?></strong>
				<p><?php esc_html_e( 'Your dashboard is waiting for you once you are signed in.', 'kasa-academy' ); ?></p>
				<a class="kd-btn kd-btn-solid" href="<?php echo esc_url( $login ); ?>">
					<?php esc_html_e( 'Sign in', 'kasa-academy' ); ?>
				</a>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * A signed-in user with no dashboard capability at all.
	 *
	 * Should not happen, since every Kasa role carries kasa_view_dashboard, but
	 * a WordPress site collects other roles over time and one of them turning
	 * up here should read as a tidy message rather than a blank page.
	 *
	 * @return string
	 */
	private static function render_no_access() {
		ob_start();
		?>
		<div class="kd">
			<div class="kd-empty">
				<div class="kd-empty-mark" aria-hidden="true">&#129300;</div>
				<strong><?php esc_html_e( 'Nothing here for this account', 'kasa-academy' ); ?></strong>
				<p><?php esc_html_e( 'This account is not set up as a learner, a facilitator or a partner. An administrator can put that right.', 'kasa-academy' ); ?></p>
				<a class="kd-btn kd-btn-ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Back to the Academy', 'kasa-academy' ); ?>
				</a>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}

add_action(
	'kasa_academy_register_modules',
	function ( $plugin ) {
		$plugin->register_module( new Kasa_Dashboard_Module() );
	}
);
