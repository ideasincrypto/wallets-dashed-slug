<?php

// don't load directly
defined( 'ABSPATH' ) || die( '-1' );

if ( ! class_exists( 'Dashed_Slug_Wallets_Admin_Menu') ) {
	class Dashed_Slug_Wallets_Admin_Menu {

		private static $_instance;
		private static $tx_columns = 'category,account,other_account,address,txid,symbol,amount,fee,comment,created_time,updated_time,confirmations';

		private function __construct() {
			add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
			add_action( 'admin_init', array( &$this, 'admin_init' ) );
		}

		public static function get_instance() {
			if ( ! ( self::$_instance instanceof self ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		public static function action_activate() {
			add_option( 'wallets_cron_interval', 'wallets_five_minutes' );
		}

		public function admin_init() {
			$action = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_STRING );

			$core = Dashed_Slug_Wallets::get_instance();
			$symbol = filter_input( INPUT_GET, 'symbol', FILTER_SANITIZE_STRING );
			$adapter = $core->get_coin_adapters( $symbol );

			switch ( $action ) {

				case 'settings':
					if ( ! current_user_can( 'manage_wallets' ) )  {
						wp_die( __( 'You do not have sufficient permissions to access this page.', 'wallets' ) );
					}

					if ( is_object( $adapter ) ) {
						$url = admin_url( 'admin.php?page=wallets-menu-' . sanitize_title_with_dashes( $adapter->get_adapter_name(), null, 'save' ) );
						Header( "Location: $url" );
						exit;
					}
					break;

				case 'export':
					if ( ! current_user_can( 'manage_wallets' ) )  {
						wp_die( __( 'You do not have sufficient permissions to access this page.', 'wallets' ) );
					}

					$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_STRING );

					if ( ! wp_verify_nonce( $nonce, "wallets-export-tx-$symbol" ) ) {
						wp_die( __( 'Possible request forgery detected. Please reload and try again.', 'wallets' ) );
					}

					if ( is_object( $adapter ) ) {
						$this->csv_export( array( $adapter->get_symbol() ) );
						exit;
					}
					break;
			}

			$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_STRING );

			if ( 'import' == $action && isset( $_FILES['txfile'] ) ) {
				if ( ! current_user_can( 'manage_wallets' ) )  {
					wp_die( __( 'You do not have sufficient permissions to access this page.', 'wallets' ) );
				}

				$nonce = filter_input( INPUT_POST, '_wpnonce', FILTER_SANITIZE_STRING );

				if ( ! wp_verify_nonce( $nonce, "wallets-import" ) ) {
					wp_die( __( 'Possible request forgery detected. Please reload and try again.', 'wallets' ) );
				}


				$notices = Dashed_Slug_Wallets_Admin_Notices::get_instance();

				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once( ABSPATH . 'wp-admin/includes/file.php' );
				}

				$uploaded_file = $_FILES[ 'txfile' ];
				$upload_overrides = array( 'action' => 'import' );
				$moved_file = wp_handle_upload( $uploaded_file, $upload_overrides );
				if ( $moved_file && ! isset( $moved_file['error'] ) ) {
					$moved_file_name = $moved_file['file'];

					$result = $this->csv_import( $moved_file_name );

					if ( false !== $result ) {
						$notices->success(
							sprintf(
								__( '<code>%d</code> transactions from <code>%s</code> imported successfully', 'wallets' ),
								$result, $moved_file_name ) );
					}

				} else {

					 // Error generated by _wp_handle_upload()
					$notices->error( sprintf(
							__( 'Failed to import file %s : %s', 'wallets' ),
							$_FILES['txfile'], $moved_file['error'] ) );
				}

				// Finally delete the uploaded .csv file
				unlink( $moved_file_name );
			}

			// bind settings subpage

			add_settings_section(
				'wallets_cron_settings_section',
				__( 'Cron settings', '/* @echo slug' ),
				array( &$this, 'wallets_settings_cron_section_cb' ),
				'wallets-menu-settings'
			);

			add_settings_field(
				"wallets_cron_interval",
				__( 'Double-check for missing deposits', 'wallets' ),
				array( &$this, 'settings_interval_cb'),
				'wallets-menu-settings',
				'wallets_cron_settings_section',
				array( 'label_for' => "wallets_cron_interval" )
			);

			register_setting(
				'wallets-menu-settings',
				"wallets_cron_interval"
			);

		}

		public function admin_menu() {

			if ( current_user_can( 'manage_wallets' ) ) {

				add_menu_page(
					'Bitcoin and Altcoin Wallets',
					__( 'Wallets' ),
					'manage_wallets',
					'wallets-menu-wallets',
					array( &$this, 'wallets_page_cb' ),
					plugins_url( 'assets/sprites/wallet-icon.png', DSWALLETS_PATH . '/wallets.php' )
				);

				add_submenu_page(
					'wallets-menu-wallets',
					'Bitcoin and Altcoin Wallets: Settings',
					'Settings',
					'manage_wallets',
					'wallets-menu-settings',
					array( &$this, "admin_menu_wallets_settings_cb" )
				);

				do_action( 'wallets_admin_menu' );

			}
		}

		public function admin_menu_wallets_settings_cb() {
			if ( ! current_user_can( 'manage_wallets' ) )  {
				wp_die( __( 'You do not have sufficient permissions to access this page.', 'wallets' ) );
			}

			?><h1>Bitcoin and Altcoin Wallets settings</h1>
			<p><?php esc_html_e( 'General settings that apply to the plugin, not any particular coin adapter.', 'wallets' ); ?></p>

			<form method="post" action="options.php" class="card"><?php
			settings_fields( 'wallets-menu-settings' );
			do_settings_sections( 'wallets-menu-settings' );
			submit_button();
			?></form><?php
		}

		public function wallets_settings_cron_section_cb() {
			?><p><?php
			esc_html_e( 'A cron mechanism goes through the various enabled coin adapters '.
				'and ensures every now and then that users\' deposits will eventually be processed, ' .
				'even if they have been overlooked. Deposits may be overlooked if the notification mechanism ' .
				'fails or if it is not correctly set up.', 'Bitcoin and Altcoin Wallets');
			?></p><?php
		}

		public function wallets_page_cb() {
			if ( ! current_user_can( 'manage_wallets' ) )  {
				wp_die( __( 'You do not have sufficient permissions to access this page.', 'wallets' ) );
			}

			$admin_adapter_list = new DSWallets_Admin_Menu_Adapter_List();

			?><h1><?php echo 'Bitcoin and Altcoin Wallets' ?></h1>

			<div class="notice notice-warning"><h2 style="color: red"><?php
			esc_html_e( 'IMPORTANT SECURITY DISCLAIMER:', 'wallets' ); ?></h2>

			<p><?php esc_html_e( 'By using this free plugin you assume all responsibility for handling ' .
			'the account balances for all your users. Under no circumstances is dashed-slug.net ' .
			'or any of its affiliates responsible for any damages incurred by the use of this plugin. ' .
			'Every effort has been made to harden the security of this plugin, ' .
			'but its safe operation is your responsibility and depends on your site being secure overall. ' .
			'You, the administrator, must take all necessary precautions to secure your WordPress installation ' .
			'before you connect it to any live wallets. ' .
			'You are strongly recommended to take the following actions (at a minimum):', 'wallets'); ?></p>
			<ol><li><a href="https://codex.wordpress.org/Hardening_WordPress" target="_blank"><?php
			esc_html_e( 'educate yourself about hardening WordPress security', 'wallets' ); ?></a></li>
			<li><a href="https://infinitewp.com/addons/wordfence/?ref=260" target="_blank"
			title="This affiliate link supports the development of dashed-slug.net plugins. Thanks for clicking."><?php
			esc_html_e( 'install a security plugin such as Wordfence', 'wallets' ); ?></a></li></ol><p><?php
			esc_html_e( 'By continuing to use the Bitcoin and Altcoin Wallets plugin, ' .
			'you agree that you have read and understood this disclaimer.', 'wallets' );
			?></p></div><?php

			?><div class="notice notice-info"><h2><?php
			esc_html_e( 'Wallet plugin extensions', 'wallets' ); ?></h2><p><?php esc_html_e(
			'Bitcoin and Altcoin Wallets is a plugin that offers basic deposit-transfer-withdraw functionality. ', 'wallets' );
			esc_html_e( 'You can install', 'wallets' ); ?></p><ol>
				<li><?php esc_html_e( '"coin adapters" to make the plugin talk with other cryptocurrencies. ', 'wallets' ); ?></li>
				<li><?php esc_html_e( '"app extensions". App extensions are plugins that utilize the core API ' .
								'to supply some user functionality. ', '/& @echo slug */' ); ?></li>
			</ol>
			<p><a href="<?php echo 'https://www.dashed-slug.net/bitcoin-altcoin-wallets-wordpress-plugin'; ?>" target="_blank">
				<?php esc_html_e( 'Visit the dashed-slug to see what\'s available', 'wallets' ); ?>
			</a></p></div>

			<h2><?php esc_html_e( 'Coin Adapters', 'wallets' ); ?></h2>
			<div class="wrap"><?php
				$admin_adapter_list->prepare_items();
				$admin_adapter_list->display();
			?></div>

			<h2><?php echo esc_html_e( 'Import transactions from csv', 'wallets' ) ?></h2>
			<form class="card" method="post" enctype="multipart/form-data">
				<p><?php esc_html_e( 'You can use this form to upload transactions that you have exported previously. Existing transactions will be skipped.', 'wallets' ); ?>
				<input type="hidden" name="action" value="import" />
				<input type="file" name="txfile" />
				<input type="submit" value="<?php esc_attr_e( 'Import', 'wallets' ) ?>" />
				<?php wp_nonce_field( 'wallets-import' ); ?>
			</form><?php
		}

		/** @internal */
		public function settings_interval_cb( $arg ) {
			$cron_intervals = apply_filters( 'cron_schedules', array() );
			$selected_value = get_option( $arg['label_for'] ); ?>

			<select name="<?php echo esc_attr( $arg['label_for'] ) ?>" id="<?php echo esc_attr( $arg['label_for'] ); ?>" ><?php

					foreach ( $cron_intervals as $cron_interval_slug => $cron_interval ):
						if ( ( strlen( $cron_interval_slug ) > 7 ) && ( 'wallets' == substr( $cron_interval_slug, 0, 7 ) ) ) :
							?><option value="<?php echo esc_attr( $cron_interval_slug ) ?>"<?php if ( $cron_interval_slug == $selected_value ) { echo ' selected="selected" '; }; ?>><?php echo $cron_interval['display']; ?></option><?php
						endif;
					endforeach;

			?></select><?php
		}

		private function csv_export( $symbols ) {
			sort( $symbols );

			$filename = 'wallet-transactions-' . implode(',', $symbols ) . '-' . date( DATE_RFC3339 ) . '.csv';
			header( 'Content-Type: application/csv; charset=UTF-8' );
			header( "Content-Disposition: attachment; filename=\"$filename\";" );

			global $wpdb;
			$table_name_txs = "{$wpdb->prefix}wallets_txs";
			$fh = fopen('php://output', 'w');

			$symbols_set = array();
			foreach ( $symbols as $symbol ) {
				$symbols_set[] = "'$symbol'";
			}
			$symbols_set = implode(',', $symbols_set );

			$tx_columns = self::$tx_columns;

			$rows = $wpdb->get_results(
				"
					SELECT
						$tx_columns
					FROM
						$table_name_txs
					WHERE
						symbol IN ( $symbols_set )
				", ARRAY_N
			);

			echo self::$tx_columns . "\n";
			foreach ( $rows as &$row ) {
				fputcsv( $fh, $row, ',' );
			}
		}

		private function csv_import( $filename ) {
			try {
				$rows_read = 0;
				$rows_written = 0;

				// see http://php.net/manual/en/function.fgetcsv.php
				if ( version_compare( PHP_VERSION, '5.1.0' ) >= 0 ) {
					$len = 0;
				} else {
					$len = 2048;
				}

				// read file
				if ( ( $fh = fopen( $filename, 'r' ) ) !== false ) {
					global $wpdb;
					$table_name_txs = "{$wpdb->prefix}wallets_txs";
					$headers = fgetcsv( $fh, $len );

					while (( $data = fgetcsv( $fh, $len )) !== false ) {

						$rows_read++;
						$rows_affected = $wpdb->query( $wpdb->prepare(
							"
								INSERT INTO
									$table_name_txs(" . self::$tx_columns . ")
								VALUES
									( %s, %d, NULLIF(%d, ''), %s, %s, %s, %20.10f, %20.10f, NULLIF(%s, ''), %s, %s, %d )
							",
							$data[0],
							$data[1],
							$data[2],
							$data[3],
							$data[4],
							$data[5],
							$data[6],
							$data[7],
							$data[8],
							$data[9],
							$data[10],
							$data[11]
						) );

						if ( false !== $rows_affected ) {
							$rows_written += $rows_affected;
						}
					}
					return $rows_written;
				}
			} catch ( Exception $e ) {
				fclose( $fh );
				throw $e;
			}
			fclose( $fh );
		} // end function csv_import()
	}
}