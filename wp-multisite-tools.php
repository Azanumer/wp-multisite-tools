<?php
/**
 * Plugin Name: WP Multisite Tools
 * Plugin URI:  https://github.com/Azanumer/wp-multisite-tools
 * Description: Network-admin toolkit for WordPress multisite — a per-site health table (users, content, theme, plugins, disk usage), bulk plugin activate/deactivate across all sites, and CSV export.
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Network:     true
 * Author:      Azan Umer
 * Author URI:  https://azanumer.cv
 * License:     MIT
 * Text Domain: wp-multisite-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMT_VERSION', '1.0.0' );

/* ------------------------------------------------------------------ */
/* Multisite-only guard                                                */
/* ------------------------------------------------------------------ */

function wmt_activate() {
	if ( ! is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'WP Multisite Tools only works on a WordPress multisite network. Activate it from the Network Admin of a multisite install.', 'wp-multisite-tools' )
		);
	}
}
register_activation_hook( __FILE__, 'wmt_activate' );

/* ------------------------------------------------------------------ */
/* Site stats                                                          */
/* ------------------------------------------------------------------ */

/**
 * How many sites the table lists (the per-site stats need a blog switch
 * each, so very large networks are capped).
 */
function wmt_sites_limit() {
	/**
	 * Filter the maximum number of sites shown in the table.
	 *
	 * @param int $limit Default 200.
	 */
	return (int) apply_filters( 'wmt_sites_limit', 200 );
}

/**
 * Collect one row of stats per site.
 *
 * @return array<int, array{blog_id:int, domain:string, path:string, registered:string, users:int, posts:int, pages:int, theme:string, plugins:int, disk_mb:float|null}>
 */
function wmt_collect_site_rows() {
	$sites = get_sites( array( 'number' => wmt_sites_limit() ) );
	$rows  = array();

	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );

		$users  = count_users();
		$posts  = wp_count_posts( 'post' );
		$pages  = wp_count_posts( 'page' );
		$active = get_option( 'active_plugins', array() );

		/**
		 * Filter whether disk usage is measured. Measuring walks the site's
		 * uploads folder, which can be slow on very large sites.
		 *
		 * @param bool $show Default true.
		 */
		$show_disk = apply_filters( 'wmt_show_disk_usage', true );
		$disk_mb   = null;
		if ( $show_disk && function_exists( 'get_space_used' ) ) {
			$disk_mb = (float) get_space_used();
		}

		$rows[] = array(
			'blog_id'    => (int) $site->blog_id,
			'domain'     => $site->domain,
			'path'       => $site->path,
			'registered' => $site->registered,
			'users'      => (int) $users['total_users'],
			'posts'      => (int) $posts->publish,
			'pages'      => (int) $pages->publish,
			'theme'      => get_stylesheet(),
			'plugins'    => count( $active ),
			'disk_mb'    => $disk_mb,
		);

		restore_current_blog();
	}

	return $rows;
}

/* ------------------------------------------------------------------ */
/* Bulk plugin actions                                                 */
/* ------------------------------------------------------------------ */

/**
 * Activate or deactivate one plugin on every site in the network.
 *
 * @param string $plugin_file Plugin path, e.g. 'akismet/akismet.php'.
 * @param string $action      'activate' or 'deactivate'.
 * @return array{ok: int, failed: string[]} Sites processed / failures.
 */
function wmt_bulk_plugin_action( $plugin_file, $action ) {
	$sites  = get_sites( array( 'number' => 0 ) );
	$result = array( 'ok' => 0, 'failed' => array() );

	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		$done = false;
		if ( 'activate' === $action ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$done = true;
			} else {
				$err  = activate_plugin( $plugin_file, '', false, true );
				$done = ! is_wp_error( $err );
			}
		} else {
			if ( ! is_plugin_active( $plugin_file ) ) {
				$done = true;
			} else {
				deactivate_plugins( $plugin_file, true );
				$done = ! is_plugin_active( $plugin_file );
			}
		}
		if ( $done ) {
			$result['ok']++;
		} else {
			$result['failed'][] = $site->domain . $site->path;
		}
		restore_current_blog();
	}

	return $result;
}

/* ------------------------------------------------------------------ */
/* Network admin page                                                  */
/* ------------------------------------------------------------------ */

function wmt_register_network_page() {
	add_menu_page(
		__( 'Multisite Tools', 'wp-multisite-tools' ),
		__( 'Multisite Tools', 'wp-multisite-tools' ),
		'manage_network_plugins',
		'wp-multisite-tools',
		'wmt_render_network_page',
		'dashicons-networking',
		30
	);
}
add_action( 'network_admin_menu', 'wmt_register_network_page' );

function wmt_render_network_page() {
	if ( ! current_user_can( 'manage_network_plugins' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-multisite-tools' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$notice = '';
	$ok     = false;

	// Bulk plugin action.
	if ( isset( $_POST['wmt_bulk'] ) && check_admin_referer( 'wmt_bulk' ) ) {
		$plugin_file = isset( $_POST['wmt_plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['wmt_plugin'] ) ) : '';
		$action      = isset( $_POST['wmt_action'] ) ? sanitize_text_field( wp_unslash( $_POST['wmt_action'] ) ) : '';
		$plugins     = get_plugins();
		if ( isset( $plugins[ $plugin_file ] ) && in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
			$result = wmt_bulk_plugin_action( $plugin_file, $action );
			$ok     = empty( $result['failed'] );
			$notice = sprintf(
				/* translators: 1: activate/deactivate, 2: plugin name, 3: site count, 4: failure count. */
				__( '%1$s %2$s on %3$d site(s). Failures: %4$d.', 'wp-multisite-tools' ),
				'activate' === $action ? __( 'Activated', 'wp-multisite-tools' ) : __( 'Deactivated', 'wp-multisite-tools' ),
				$plugins[ $plugin_file ]['Name'],
				$result['ok'],
				count( $result['failed'] )
			);
			if ( ! empty( $result['failed'] ) ) {
				$notice .= ' ' . __( 'Failed on:', 'wp-multisite-tools' ) . ' ' . implode( ', ', array_slice( $result['failed'], 0, 10 ) );
			}
		} else {
			$notice = __( 'Select a valid plugin and action.', 'wp-multisite-tools' );
		}
	}

	// CSV export.
	if ( isset( $_GET['wmt_export'] ) && '1' === $_GET['wmt_export'] && check_admin_referer( 'wmt_export' ) ) {
		wmt_export_csv( wmt_collect_site_rows() );
	}

	$rows    = wmt_collect_site_rows();
	$plugins = get_plugins();
	$total   = (int) get_blog_count();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Multisite Tools', 'wp-multisite-tools' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: total site count, 2: shown site count. */
				esc_html__( 'Network of %1$d site(s) — showing %2$d (raise the limit with the wmt_sites_limit filter).', 'wp-multisite-tools' ),
				$total,
				count( $rows )
			);
			?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( network_admin_url( 'admin.php?page=wp-multisite-tools&wmt_export=1' ), 'wmt_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'wp-multisite-tools' ); ?></a>
		</p>

		<?php if ( $notice ) : ?>
			<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Bulk plugin action', 'wp-multisite-tools' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'wmt_bulk' ); ?>
			<select name="wmt_plugin">
				<option value=""><?php esc_html_e( '— Select a plugin —', 'wp-multisite-tools' ); ?></option>
				<?php foreach ( $plugins as $file => $data ) : ?>
					<option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $data['Name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="wmt_action">
				<option value="activate"><?php esc_html_e( 'Activate on all sites', 'wp-multisite-tools' ); ?></option>
				<option value="deactivate"><?php esc_html_e( 'Deactivate on all sites', 'wp-multisite-tools' ); ?></option>
			</select>
			<input type="submit" name="wmt_bulk" class="button button-primary" value="<?php esc_attr_e( 'Apply to all sites', 'wp-multisite-tools' ); ?>"
				onclick="return confirm('<?php esc_attr_e( 'Apply this to every site in the network?', 'wp-multisite-tools' ); ?>');">
		</form>

		<h2><?php esc_html_e( 'Sites', 'wp-multisite-tools' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Created', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Users', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Posts', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Pages', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Theme', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Active plugins', 'wp-multisite-tools' ); ?></th>
					<th><?php esc_html_e( 'Disk (MB)', 'wp-multisite-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( 'https://' . $row['domain'] . $row['path'] ); ?>"><?php echo esc_html( $row['domain'] . $row['path'] ); ?></a></strong>
						<br><span style="color:#666;">ID <?php echo (int) $row['blog_id']; ?></span>
					</td>
					<td><?php echo esc_html( gmdate( 'Y-m-d', strtotime( $row['registered'] ) ) ); ?></td>
					<td><?php echo (int) $row['users']; ?></td>
					<td><?php echo (int) $row['posts']; ?></td>
					<td><?php echo (int) $row['pages']; ?></td>
					<td><?php echo esc_html( $row['theme'] ); ?></td>
					<td><?php echo (int) $row['plugins']; ?></td>
					<td><?php echo null === $row['disk_mb'] ? '—' : esc_html( number_format_i18n( $row['disk_mb'], 1 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Stream the site table as CSV and exit.
 */
function wmt_export_csv( $rows ) {
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="multisite-sites-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'blog_id', 'site', 'created', 'users', 'posts', 'pages', 'theme', 'active_plugins', 'disk_mb' ) );
	foreach ( $rows as $row ) {
		fputcsv( $out, array(
			$row['blog_id'],
			$row['domain'] . $row['path'],
			$row['registered'],
			$row['users'],
			$row['posts'],
			$row['pages'],
			$row['theme'],
			$row['plugins'],
			$row['disk_mb'],
		) );
	}
	fclose( $out );
	exit;
}
