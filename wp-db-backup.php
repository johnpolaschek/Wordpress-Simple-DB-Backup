<?php
/*
Plugin Name: Simple WordPress DB Backup
Description: A simple, robust WordPress database backup plugin with scheduling, editing/deletion capabilities, immediate backup, and ZIP-compressed backups. Backup files use the job label (for scheduled jobs) or a manual label in their filename, and logs are grouped by schedule type.
Version: 1.5
Author: John P. and ChatGPT
Author URI: paypal.me/jpolaschek
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Simple_DB_Backup {

	// Option name to store job configurations.
	private $jobs_option = 'db_backup_jobs';
	// Backup logs custom table name.
	private $logs_table;
	// Folder where backup files will be stored.
	private $backup_folder;

	public function __construct() {
		global $wpdb;
		$this->logs_table = $wpdb->prefix . 'db_backup_logs';
		$upload_dir = wp_upload_dir();
		$this->backup_folder = trailingslashit( $upload_dir['basedir'] ) . 'db-backups';

		// Hooks for admin menus and actions.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		// Cron callback – runs every minute.
		add_action( 'db_backup_run_jobs', array( $this, 'cron_run_jobs' ) );

		// Add custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		// Schedule our cron event if not already scheduled.
		if ( ! wp_next_scheduled( 'db_backup_run_jobs' ) ) {
			wp_schedule_event( time(), 'minute', 'db_backup_run_jobs' );
		}
	}

	/**
	 * Plugin activation: create custom table for backup logs.
	 */
	public static function activate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'db_backup_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			job_id varchar(100) NOT NULL,
			backup_time datetime NOT NULL,
			file_name varchar(255) NOT NULL,
			file_size bigint(20) NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Plugin deactivation: unschedule cron events.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'db_backup_run_jobs' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'db_backup_run_jobs' );
		}
	}

	/**
	 * Add custom cron intervals including a minute interval.
	 */
	public function add_cron_intervals( $schedules ) {
		$schedules['minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute' )
		);
		$schedules['weekly'] = array(
			'interval' => 604800, // 7 days
			'display'  => __( 'Once Weekly' )
		);
		$schedules['monthly'] = array(
			'interval' => 2592000, // 30 days (approx.)
			'display'  => __( 'Once Monthly' )
		);
		return $schedules;
	}

	/**
	 * Add a menu page for the backup plugin.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'DB Backup', 'db-backup' ),
			__( 'DB Backup', 'db-backup' ),
			'manage_options',
			'db-backup',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Handle form submissions and actions:
	 * - Add new backup jobs
	 * - Update existing backup jobs
	 * - Delete backup jobs
	 * - Delete backup files/logs and download backups
	 * - Immediate backup
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Immediate Backup action.
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'backup_now' && check_admin_referer( 'db_backup_backup_now' ) ) {
			$this->immediate_backup();
			wp_redirect( admin_url( 'admin.php?page=db-backup' ) );
			exit;
		}

		// Delete a backup file (log).
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_backup' && isset( $_GET['backup_id'] ) && check_admin_referer( 'db_backup_delete_backup_' . $_GET['backup_id'] ) ) {
			global $wpdb;
			$backup_id = intval( $_GET['backup_id'] );
			$backup    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->logs_table} WHERE id = %d", $backup_id ) );
			if ( $backup ) {
				$file = trailingslashit( $this->backup_folder ) . $backup->file_name;
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
				$wpdb->delete( $this->logs_table, array( 'id' => $backup->id ), array( '%d' ) );
			}
			wp_redirect( admin_url( 'admin.php?page=db-backup' ) );
			exit;
		}

		// Download a backup file.
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'download_backup' && isset( $_GET['backup_id'] ) && check_admin_referer( 'db_backup_download_backup_' . $_GET['backup_id'] ) ) {
			global $wpdb;
			$backup_id = intval( $_GET['backup_id'] );
			$backup    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->logs_table} WHERE id = %d", $backup_id ) );
			if ( $backup ) {
				$file = trailingslashit( $this->backup_folder ) . $backup->file_name;
				if ( file_exists( $file ) ) {
					header( 'Content-Description: File Transfer' );
					header( 'Content-Type: application/zip' );
					header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
					header( 'Content-Length: ' . filesize( $file ) );
					readfile( $file );
					exit;
				}
			}
			wp_die( __( 'File not found.', 'db-backup' ) );
		}

		// Delete a backup job.
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_job' && isset( $_GET['job_id'] ) && check_admin_referer( 'db_backup_delete_job_' . $_GET['job_id'] ) ) {
			$job_id = sanitize_text_field( $_GET['job_id'] );
			$jobs = get_option( $this->jobs_option, array() );
			if ( isset( $jobs[ $job_id ] ) ) {
				unset( $jobs[ $job_id ] );
				update_option( $this->jobs_option, $jobs );
			}
			wp_redirect( admin_url( 'admin.php?page=db-backup' ) );
			exit;
		}

		// Update (edit) an existing backup job.
		if ( isset( $_POST['db_backup_update_job'] ) && check_admin_referer( 'db_backup_update_job_nonce' ) ) {
			$job_id = sanitize_text_field( $_POST['job_id'] );
			$jobs = get_option( $this->jobs_option, array() );
			if ( isset( $jobs[ $job_id ] ) ) {
				$schedule    = sanitize_text_field( $_POST['schedule'] );
				$max_backups = intval( $_POST['max_backups'] );
				if ( $schedule === 'daily' && $max_backups > 30 ) {
					$max_backups = 30;
				} elseif ( $schedule !== 'daily' && $max_backups > 12 ) {
					$max_backups = 12;
				}
				$custom_time = sanitize_text_field( $_POST['custom_time'] );
				$custom_weekday = '';
				$custom_day_of_month = '';
				if ( $schedule === 'weekly' ) {
					$custom_weekday = sanitize_text_field( $_POST['custom_weekday'] );
				} elseif ( $schedule === 'monthly' ) {
					$custom_day_of_month = intval( $_POST['custom_day_of_month'] );
				}
				$jobs[ $job_id ] = array(
					'schedule'            => $schedule,
					'max_backups'         => $max_backups,
					'label'               => sanitize_text_field( $_POST['label'] ),
					'custom_time'         => $custom_time,
					'custom_weekday'      => $custom_weekday,
					'custom_day_of_month' => $custom_day_of_month,
				);
				$jobs[ $job_id ]['next_run'] = $this->calculate_next_run( $jobs[ $job_id ] );
				update_option( $this->jobs_option, $jobs );
			}
			wp_redirect( admin_url( 'admin.php?page=db-backup' ) );
			exit;
		}

		// Add a new backup job.
		if ( isset( $_POST['db_backup_add_job'] ) && check_admin_referer( 'db_backup_add_job_nonce' ) ) {
			$schedule    = sanitize_text_field( $_POST['schedule'] );
			$max_backups = intval( $_POST['max_backups'] );
			if ( $schedule === 'daily' && $max_backups > 30 ) {
				$max_backups = 30;
			} elseif ( $schedule !== 'daily' && $max_backups > 12 ) {
				$max_backups = 12;
			}
			$custom_time = sanitize_text_field( $_POST['custom_time'] );
			$custom_weekday = '';
			$custom_day_of_month = '';
			if ( $schedule === 'weekly' ) {
				$custom_weekday = sanitize_text_field( $_POST['custom_weekday'] );
			} elseif ( $schedule === 'monthly' ) {
				$custom_day_of_month = intval( $_POST['custom_day_of_month'] );
			}
			$job_id   = uniqid( 'job_' );
			$job_data = array(
				'schedule'            => $schedule,
				'max_backups'         => $max_backups,
				'label'               => sanitize_text_field( $_POST['label'] ),
				'custom_time'         => $custom_time,
				'custom_weekday'      => $custom_weekday,
				'custom_day_of_month' => $custom_day_of_month,
			);
			$job_data['next_run'] = $this->calculate_next_run( $job_data );
			$jobs = get_option( $this->jobs_option, array() );
			$jobs[ $job_id ] = $job_data;
			update_option( $this->jobs_option, $jobs );
			wp_redirect( admin_url( 'admin.php?page=db-backup' ) );
			exit;
		}
	}

	/**
	 * Output the admin page.
	 */
	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Button URL for immediate backup.
		$backup_now_url = wp_nonce_url( admin_url( 'admin.php?page=db-backup&action=backup_now' ), 'db_backup_backup_now' );
		?>
		<div class="wrap">
			<h1><?php _e( 'Database Backup Jobs', 'db-backup' ); ?></h1>

			<!-- Immediate Backup Button -->
			<p>
				<a href="<?php echo esc_url( $backup_now_url ); ?>" class="button button-primary">
					<?php _e( 'Backup Now', 'db-backup' ); ?>
				</a>
			</p>

			<?php
			// If editing a job, show the edit form.
			if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit_job' && isset( $_GET['job_id'] ) ) {
				$job_id = sanitize_text_field( $_GET['job_id'] );
				$jobs   = get_option( $this->jobs_option, array() );
				if ( ! isset( $jobs[ $job_id ] ) ) {
					echo '<p>' . __( 'Job not found.', 'db-backup' ) . '</p>';
				} else {
					$job = $jobs[ $job_id ];
					?>
					<h2><?php _e( 'Edit Backup Job', 'db-backup' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'db_backup_update_job_nonce' ); ?>
						<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>" />
						<table class="form-table">
							<tr>
								<th><label for="label"><?php _e( 'Job Label', 'db-backup' ); ?></label></th>
								<td><input type="text" name="label" id="label" value="<?php echo esc_attr( $job['label'] ); ?>" required /></td>
							</tr>
							<tr>
								<th><label for="schedule"><?php _e( 'Schedule', 'db-backup' ); ?></label></th>
								<td>
									<select name="schedule" id="schedule">
										<option value="hourly" <?php selected( $job['schedule'], 'hourly' ); ?>><?php _e( 'Hourly', 'db-backup' ); ?></option>
										<option value="daily" <?php selected( $job['schedule'], 'daily' ); ?>><?php _e( 'Daily', 'db-backup' ); ?></option>
										<option value="weekly" <?php selected( $job['schedule'], 'weekly' ); ?>><?php _e( 'Weekly', 'db-backup' ); ?></option>
										<option value="monthly" <?php selected( $job['schedule'], 'monthly' ); ?>><?php _e( 'Monthly', 'db-backup' ); ?></option>
									</select>
								</td>
							</tr>
							<tr id="time_row">
								<th><label for="custom_time"><?php _e( 'Run Time (HH:MM)', 'db-backup' ); ?></label></th>
								<td><input type="time" name="custom_time" id="custom_time" value="<?php echo esc_attr( $job['custom_time'] ); ?>" required /></td>
							</tr>
							<tr id="weekday_row">
								<th><label for="custom_weekday"><?php _e( 'Run Day (Weekly)', 'db-backup' ); ?></label></th>
								<td>
									<select name="custom_weekday" id="custom_weekday">
										<option value="0" <?php selected( $job['custom_weekday'], '0' ); ?>><?php _e( 'Sunday', 'db-backup' ); ?></option>
										<option value="1" <?php selected( $job['custom_weekday'], '1' ); ?>><?php _e( 'Monday', 'db-backup' ); ?></option>
										<option value="2" <?php selected( $job['custom_weekday'], '2' ); ?>><?php _e( 'Tuesday', 'db-backup' ); ?></option>
										<option value="3" <?php selected( $job['custom_weekday'], '3' ); ?>><?php _e( 'Wednesday', 'db-backup' ); ?></option>
										<option value="4" <?php selected( $job['custom_weekday'], '4' ); ?>><?php _e( 'Thursday', 'db-backup' ); ?></option>
										<option value="5" <?php selected( $job['custom_weekday'], '5' ); ?>><?php _e( 'Friday', 'db-backup' ); ?></option>
										<option value="6" <?php selected( $job['custom_weekday'], '6' ); ?>><?php _e( 'Saturday', 'db-backup' ); ?></option>
									</select>
								</td>
							</tr>
							<tr id="dayofmonth_row">
								<th><label for="custom_day_of_month"><?php _e( 'Run Day (Monthly)', 'db-backup' ); ?></label></th>
								<td><input type="number" name="custom_day_of_month" id="custom_day_of_month" min="1" max="31" value="<?php echo isset( $job['custom_day_of_month'] ) ? esc_attr( $job['custom_day_of_month'] ) : '1'; ?>" /></td>
							</tr>
							<tr>
								<th><label for="max_backups"><?php _e( 'Max Backups to Store', 'db-backup' ); ?></label></th>
								<td>
									<input type="number" name="max_backups" id="max_backups" value="<?php echo esc_attr( $job['max_backups'] ); ?>" min="1" required />
									<p class="description">
										<?php _e( 'Daily backups max 30; hourly, weekly and monthly max 12.', 'db-backup' ); ?>
									</p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Update Backup Job', 'db-backup' ), 'primary', 'db_backup_update_job' ); ?>
					</form>
					<p>
						<a href="<?php echo admin_url( 'admin.php?page=db-backup' ); ?>"><?php _e( 'Cancel Editing', 'db-backup' ); ?></a>
					</p>
					<?php
				}
			} else {
				// Add New Job form.
				?>
				<h2><?php _e( 'Add New Backup Job', 'db-backup' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'db_backup_add_job_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="label"><?php _e( 'Job Label', 'db-backup' ); ?></label></th>
							<td><input type="text" name="label" id="label" required /></td>
						</tr>
						<tr>
							<th><label for="schedule"><?php _e( 'Schedule', 'db-backup' ); ?></label></th>
							<td>
								<select name="schedule" id="schedule">
									<option value="hourly"><?php _e( 'Hourly', 'db-backup' ); ?></option>
									<option value="daily"><?php _e( 'Daily', 'db-backup' ); ?></option>
									<option value="weekly"><?php _e( 'Weekly', 'db-backup' ); ?></option>
									<option value="monthly"><?php _e( 'Monthly', 'db-backup' ); ?></option>
								</select>
							</td>
						</tr>
						<tr id="time_row">
							<th><label for="custom_time"><?php _e( 'Run Time (HH:MM)', 'db-backup' ); ?></label></th>
							<td><input type="time" name="custom_time" id="custom_time" required /></td>
						</tr>
						<tr id="weekday_row">
							<th><label for="custom_weekday"><?php _e( 'Run Day (Weekly)', 'db-backup' ); ?></label></th>
							<td>
								<select name="custom_weekday" id="custom_weekday">
									<option value="0"><?php _e( 'Sunday', 'db-backup' ); ?></option>
									<option value="1"><?php _e( 'Monday', 'db-backup' ); ?></option>
									<option value="2"><?php _e( 'Tuesday', 'db-backup' ); ?></option>
									<option value="3"><?php _e( 'Wednesday', 'db-backup' ); ?></option>
									<option value="4"><?php _e( 'Thursday', 'db-backup' ); ?></option>
									<option value="5"><?php _e( 'Friday', 'db-backup' ); ?></option>
									<option value="6"><?php _e( 'Saturday', 'db-backup' ); ?></option>
								</select>
							</td>
						</tr>
						<tr id="dayofmonth_row">
							<th><label for="custom_day_of_month"><?php _e( 'Run Day (Monthly)', 'db-backup' ); ?></label></th>
							<td><input type="number" name="custom_day_of_month" id="custom_day_of_month" min="1" max="31" value="1" /></td>
						</tr>
						<tr>
							<th><label for="max_backups"><?php _e( 'Max Backups to Store', 'db-backup' ); ?></label></th>
							<td>
								<input type="number" name="max_backups" id="max_backups" value="5" min="1" required />
								<p class="description">
									<?php _e( 'Daily backups max 30; hourly, weekly and monthly max 12.', 'db-backup' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Add Backup Job', 'db-backup' ), 'primary', 'db_backup_add_job' ); ?>
				</form>
				<?php
			}
			?>

			<h2><?php _e( 'Existing Backup Jobs', 'db-backup' ); ?></h2>
			<?php 
			$jobs = get_option( $this->jobs_option, array() );
			if ( ! empty( $jobs ) ) {
				echo '<table class="wp-list-table widefat fixed striped">';
				echo '<thead><tr>
						<th>' . __( 'Job Label', 'db-backup' ) . '</th>
						<th>' . __( 'Schedule', 'db-backup' ) . '</th>
						<th>' . __( 'Next Run', 'db-backup' ) . '</th>
						<th>' . __( 'Max Backups', 'db-backup' ) . '</th>
						<th>' . __( 'Actions', 'db-backup' ) . '</th>
					  </tr></thead><tbody>';
				foreach ( $jobs as $job_id => $job ) {
					echo '<tr>';
					echo '<td>' . esc_html( $job['label'] ) . '</td>';
					echo '<td>' . esc_html( ucfirst( $job['schedule'] ) ) . '</td>';
					echo '<td>' . date( 'Y-m-d H:i:s', $job['next_run'] ) . '</td>';
					echo '<td>' . intval( $job['max_backups'] ) . '</td>';
					echo '<td>';
					$edit_url = wp_nonce_url( admin_url( 'admin.php?page=db-backup&action=edit_job&job_id=' . $job_id ), 'db_backup_edit_job_' . $job_id );
					$delete_url = wp_nonce_url( admin_url( 'admin.php?page=db-backup&action=delete_job&job_id=' . $job_id ), 'db_backup_delete_job_' . $job_id );
					echo '<a href="' . esc_url( $edit_url ) . '">' . __( 'Edit', 'db-backup' ) . '</a> | ';
					echo '<a href="' . esc_url( $delete_url ) . '">' . __( 'Delete', 'db-backup' ) . '</a>';
					echo '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . __( 'No backup jobs found.', 'db-backup' ) . '</p>';
			}
			?>

			<h2><?php _e( 'Backup Logs', 'db-backup' ); ?></h2>
			<?php
			global $wpdb;
			$backups = $wpdb->get_results( "SELECT * FROM {$this->logs_table} ORDER BY backup_time DESC" );
			// Group backups by schedule type.
			$jobs = get_option( $this->jobs_option, array() );
			$grouped = array(
				'hourly'  => array(),
				'daily'   => array(),
				'weekly'  => array(),
				'monthly' => array(),
				'manual'  => array(),
				'unknown' => array(),
			);
			if ( $backups ) {
				foreach ( $backups as $backup ) {
					if ( $backup->job_id === 'manual' ) {
						$grouped['manual'][] = $backup;
					} elseif ( isset( $jobs[ $backup->job_id ] ) ) {
						$schedule = $jobs[ $backup->job_id ]['schedule'];
						$grouped[ $schedule ][] = $backup;
					} else {
						$grouped['unknown'][] = $backup;
					}
				}
			}

			// Define labels for groups.
			$group_labels = array(
				'hourly'  => __( 'Hourly Backups', 'db-backup' ),
				'daily'   => __( 'Daily Backups', 'db-backup' ),
				'weekly'  => __( 'Weekly Backups', 'db-backup' ),
				'monthly' => __( 'Monthly Backups', 'db-backup' ),
				'manual'  => __( 'Manual Backups', 'db-backup' ),
				'unknown' => __( 'Unknown Backups', 'db-backup' ),
			);

			// Display each group if not empty.
			foreach ( $grouped as $group => $logs ) {
				if ( ! empty( $logs ) ) {
					echo '<h3>' . esc_html( $group_labels[ $group ] ) . '</h3>';
					echo '<table class="wp-list-table widefat fixed striped">';
					echo '<thead><tr>
							<th>' . __( 'Backup Time', 'db-backup' ) . '</th>
							<th>' . __( 'File Name', 'db-backup' ) . '</th>
							<th>' . __( 'File Size (bytes)', 'db-backup' ) . '</th>
							<th>' . __( 'Actions', 'db-backup' ) . '</th>
						  </tr></thead><tbody>';
					foreach ( $logs as $backup ) {
						echo '<tr>';
						echo '<td>' . esc_html( $backup->backup_time ) . '</td>';
						echo '<td>' . esc_html( $backup->file_name ) . '</td>';
						echo '<td>' . esc_html( $backup->file_size ) . '</td>';
						echo '<td>';
						$download_url = wp_nonce_url( admin_url( 'admin.php?page=db-backup&action=download_backup&backup_id=' . $backup->id ), 'db_backup_download_backup_' . $backup->id );
						$delete_url   = wp_nonce_url( admin_url( 'admin.php?page=db-backup&action=delete_backup&backup_id=' . $backup->id ), 'db_backup_delete_backup_' . $backup->id );
						echo '<a href="' . esc_url( $download_url ) . '">' . __( 'Download', 'db-backup' ) . '</a> | ';
						echo '<a href="' . esc_url( $delete_url ) . '">' . __( 'Delete', 'db-backup' ) . '</a>';
						echo '</td>';
						echo '</tr>';
					}
					echo '</tbody></table>';
				}
			}
			?>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function(){
			const scheduleSelect = document.getElementById('schedule');
			const weekdayRow = document.getElementById('weekday_row');
			const dayofmonthRow = document.getElementById('dayofmonth_row');

			function updateFields(){
				const schedule = scheduleSelect.value;
				if(schedule === 'weekly'){
					weekdayRow.style.display = '';
					dayofmonthRow.style.display = 'none';
				} else if(schedule === 'monthly'){
					weekdayRow.style.display = 'none';
					dayofmonthRow.style.display = '';
				} else {
					weekdayRow.style.display = 'none';
					dayofmonthRow.style.display = 'none';
				}
			}
			if(scheduleSelect){
				scheduleSelect.addEventListener('change', updateFields);
				updateFields();
			}
		});
		</script>
		<?php
	}

	/**
	 * Cron callback – loops through backup jobs and runs any due jobs.
	 */
	public function cron_run_jobs() {
		$jobs = get_option( $this->jobs_option, array() );
		if ( empty( $jobs ) ) {
			return;
		}
		// Use WordPress local time for consistency.
		$current_time = current_time('timestamp');
		foreach ( $jobs as $job_id => $job ) {
			if ( $current_time >= $job['next_run'] ) {
				$this->run_backup( $job_id, $job );
			}
		}
	}

	/**
	 * Execute a backup for a given job.
	 */
	public function run_backup( $job_id, $job ) {
		global $wpdb;
		// Ensure backup folder exists.
		if ( ! file_exists( $this->backup_folder ) ) {
			wp_mkdir_p( $this->backup_folder );
		}
		$backup_time = current_time( 'mysql' );
		$datetime = date('Y-m-d_H-i-s', current_time('timestamp'));
		// For scheduled backups, use the job label (sanitized) instead of job id.
		$label = sanitize_title( $job['label'] );
		$file_name = 'backup-' . $label . '-' . $datetime . '.zip';
		$file_path = trailingslashit( $this->backup_folder ) . $file_name;

		// Create SQL dump.
		$dump = $this->create_database_dump();
		if ( ! $dump ) {
			return;
		}
		
		// Compress the dump into a ZIP file.
		$zip = new ZipArchive();
		if ( $zip->open( $file_path, ZipArchive::CREATE ) !== true ) {
			return;
		}
		// Save the dump inside the zip as a .sql file.
		$zip->addFromString( 'backup-' . $label . '-' . $datetime . '.sql', $dump );
		$zip->close();
		
		$file_size = filesize( $file_path );

		// Record backup details in the logs table.
		$wpdb->insert(
			$this->logs_table,
			array(
				'job_id'      => $job_id,
				'backup_time' => $backup_time,
				'file_name'   => $file_name,
				'file_size'   => $file_size,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		// Update job's next run timestamp.
		$new_next_run = $this->calculate_next_run( $job );
		$jobs = get_option( $this->jobs_option, array() );
		if ( isset( $jobs[ $job_id ] ) ) {
			$jobs[ $job_id ]['next_run'] = $new_next_run;
			update_option( $this->jobs_option, $jobs );
		}

		// Cleanup old backups if they exceed the job's maximum allowed.
		$this->cleanup_old_backups( $job_id, $job['max_backups'] );
	}

	/**
	 * Calculate the next run timestamp based on the job's schedule and custom settings.
	 * This version adds a safety margin of 5 minutes.
	 */
	private function calculate_next_run( $job ) {
		$now = current_time('timestamp');
		switch ( $job['schedule'] ) {
			case 'hourly':
				$custom_minute = (int) substr( $job['custom_time'], 3, 2 );
				$current_minute = (int) date( 'i', $now );
				if ( $current_minute < $custom_minute ) {
					$next = strtotime( date( 'Y-m-d H', $now ) . ':' . sprintf('%02d', $custom_minute) . ':00' );
				} else {
					$next = strtotime( date( 'Y-m-d H', $now ) . ':00:00' ) + 3600;
					$next = strtotime( date( 'Y-m-d H', $next ) . ':' . sprintf('%02d', $custom_minute) . ':00' );
				}
				break;
			case 'daily':
				list($hour, $minute) = explode(':', $job['custom_time']);
				$today_run = mktime( (int)$hour, (int)$minute, 0, date('n', $now), date('j', $now), date('Y', $now) );
				$next = ($today_run > $now) ? $today_run : $today_run + 86400;
				break;
			case 'weekly':
				list($hour, $minute) = explode(':', $job['custom_time']);
				$target_weekday = intval( $job['custom_weekday'] );
				$current_weekday = date( 'w', $now );
				$today_run = mktime( (int)$hour, (int)$minute, 0, date('n', $now), date('j', $now), date('Y', $now) );
				$days_ahead = $target_weekday - $current_weekday;
				if ( $days_ahead < 0 || ( $days_ahead === 0 && $today_run <= $now ) ) {
					$days_ahead += 7;
				}
				$next = $today_run + ( $days_ahead * 86400 );
				break;
			case 'monthly':
				list($hour, $minute) = explode(':', $job['custom_time']);
				$day = intval( $job['custom_day_of_month'] );
				$current_month_run = mktime( (int)$hour, (int)$minute, 0, date('n', $now), $day, date('Y', $now) );
				if ( $current_month_run > $now ) {
					$next = $current_month_run;
				} else {
					$next_month = strtotime('+1 month', $now);
					$year = date('Y', $next_month);
					$month = date('n', $next_month);
					$next = mktime( (int)$hour, (int)$minute, 0, $month, $day, $year );
				}
				break;
			default:
				$next = $now + 86400;
		}
		// Safety margin: ensure next run is at least 5 minutes (300 seconds) in the future.
		if ( $next < $now + 300 ) {
			$next = $now + 300;
		}
		return $next;
	}

	/**
	 * Remove the oldest backups for a job if the total exceeds the maximum allowed.
	 */
	private function cleanup_old_backups( $job_id, $max_backups ) {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->logs_table} WHERE job_id = %s ORDER BY backup_time ASC", $job_id ) );
		if ( count( $results ) > $max_backups ) {
			$to_delete = count( $results ) - $max_backups;
			for ( $i = 0; $i < $to_delete; $i++ ) {
				$backup = $results[ $i ];
				$file   = trailingslashit( $this->backup_folder ) . $backup->file_name;
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
				$wpdb->delete( $this->logs_table, array( 'id' => $backup->id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Create a basic SQL dump of the entire database.
	 * Note: This version iterates through each table, writing its CREATE statement and data as INSERT statements.
	 */
	private function create_database_dump() {
		global $wpdb;
		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
		if ( empty( $tables ) ) {
			return false;
		}
		$dump = '';
		foreach ( $tables as $table ) {
			$table_name = $table[0];
			// Get the CREATE TABLE statement.
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_N );
			if ( $create_table ) {
				$dump .= "\n\n" . $create_table[1] . ";\n\n";
			}
			// Get table data.
			$rows = $wpdb->get_results( "SELECT * FROM `$table_name`", ARRAY_A );
			if ( $rows ) {
				foreach ( $rows as $row ) {
					$vals = array();
					foreach ( $row as $value ) {
						$vals[] = isset( $value ) ? "'" . esc_sql( $value ) . "'" : "NULL";
					}
					$dump .= "INSERT INTO `$table_name` VALUES (" . implode( ',', $vals ) . ");\n";
				}
			}
		}
		return $dump;
	}

	/**
	 * Perform an immediate backup not tied to any scheduled job.
	 */
	private function immediate_backup() {
		global $wpdb;
		if ( ! file_exists( $this->backup_folder ) ) {
			wp_mkdir_p( $this->backup_folder );
		}
		$backup_time = current_time( 'mysql' );
		$job_id = 'manual';
		$datetime = date('Y-m-d_H-i-s', current_time('timestamp'));
		// For manual backups, use filename: "backup-{YYYY-MM-DD_HH-MM-SS}.zip"
		$file_name = 'backup-' . $datetime . '.zip';
		$file_path = trailingslashit( $this->backup_folder ) . $file_name;
		
		$dump = $this->create_database_dump();
		if ( ! $dump ) {
			return;
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path, ZipArchive::CREATE ) !== true ) {
			return;
		}
		$zip->addFromString( 'backup-' . $datetime . '.sql', $dump );
		$zip->close();
		
		$file_size = filesize( $file_path );
		
		$wpdb->insert(
			$this->logs_table,
			array(
				'job_id'      => $job_id,
				'backup_time' => $backup_time,
				'file_name'   => $file_name,
				'file_size'   => $file_size,
			),
			array( '%s', '%s', '%s', '%d' )
		);
	}
}

if ( class_exists( 'Simple_DB_Backup' ) ) {
	$simple_db_backup = new Simple_DB_Backup();
}

register_activation_hook( __FILE__, array( 'Simple_DB_Backup', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Simple_DB_Backup', 'deactivate' ) );
?>
