<?php
/**
 * Class for BackWPup logs display page
 */
class BackWPup_Page_Logs extends WP_List_Table {

	private static $listtable = NULL;
	private $job_types = NULL;

	/**
	 *
	 */
	function __construct() {

		parent::__construct( array(
								  'plural'   => 'logs',
								  'singular' => 'log',
								  'ajax'     => TRUE
							 ) );
	}

	/**
	 * @return bool
	 */
	function ajax_user_can() {

		return current_user_can( 'backwpup_logs' );
	}

	/**
	 *
	 */
	function prepare_items() {

		$this->job_types = BackWPup::get_job_types();

		$per_page = $this->get_items_per_page( 'backwpuplogs_per_page' );
		if ( empty( $per_page ) || $per_page < 1 )
			$per_page = 20;

		//load logs
		$logfiles = array();
		if ( $dir = @opendir( BackWPup_Option::get( 'cfg', 'logfolder' ) ) ) {
			while ( ( $file = readdir( $dir ) ) !== FALSE ) {
				if ( is_readable( BackWPup_Option::get( 'cfg', 'logfolder' ) . '/' . $file ) && ! is_link( BackWPup_Option::get( 'cfg', 'logfolder' ) . '/' . $file ) && ! is_dir( BackWPup_Option::get( 'cfg', 'logfolder' ) . '/' . $file ) && strstr( $file, 'backwpup_log_' ) && ( strstr( $file, '.html' ) || strstr( $file, '.html.gz' ) ) )
					$logfiles[ ] = $file;
			}
			closedir( $dir );
		}
		//ordering
		$order   = isset( $_GET[ 'order' ] ) ? $_GET[ 'order' ] : 'desc';
		$orderby = isset( $_GET[ 'orderby' ] ) ? $_GET[ 'orderby' ] : 'log';
		if ( $orderby == 'log' ) {
			if ( $order == 'asc' )
				sort( $logfiles );
			else
				rsort( $logfiles );
		}
		//by page
		$start = intval( ( $this->get_pagenum() - 1 ) * $per_page );
		$end   = $start + $per_page;
		if ( $end > count( $logfiles ) )
			$end = count( $logfiles );

		$this->items = array();
		$j = 0;
		for ( $i = $start; $i < $end; $i ++ ) {
			$this->items[$j] = BackWPup_Job::read_logheader( BackWPup_Option::get( 'cfg', 'logfolder' ) . '/' . $logfiles[ $i ] );
			$this->items[$j]['file'] = $logfiles[ $i ];
			$j++;
		}

		$this->set_pagination_args( array(
										 'total_items' => count( $logfiles ),
										 'per_page'    => $per_page,
										 'orderby'     => $orderby,
										 'order'       => $order
									) );

	}

	/**
	 * @return array
	 */
	function get_sortable_columns() {

		return array(
			'log' => array( 'log', FALSE ),
		);
	}

	/**
	 *
	 */
	function no_items() {

		_e( 'No Logs.', 'backwpup' );
	}

	/**
	 * @return array
	 */
	function get_bulk_actions() {

		if ( ! $this->has_items() )
			return array ();

		$actions             = array();
		$actions[ 'delete' ] = __( 'Delete', 'backwpup' );

		return $actions;
	}

	/**
	 * @return array
	 */
	function get_columns() {
		$posts_columns              = array();
		$posts_columns[ 'cb' ]      = '<input type="checkbox" />';
		$posts_columns[ 'id' ]      = __( 'Job', 'backwpup' );
		$posts_columns[ 'type' ]    = __( 'Type', 'backwpup' );
		$posts_columns[ 'log' ]     = __( 'Backup/Log Date/Time', 'backwpup' );
		$posts_columns[ 'status' ]  = __( 'Status', 'backwpup' );
		$posts_columns[ 'size' ]    = __( 'Size', 'backwpup' );
		$posts_columns[ 'runtime' ] = __( 'Runtime', 'backwpup' );

		return $posts_columns;
	}

	/**
	 * The cb Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_cb( $item ) {

		return '<input type="checkbox" name="logfiles[]" value="' .esc_attr( $item['file'] ) . '" />';
	}

	/**
	 * The job id Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_id( $item ) {

		return  esc_attr( $item[ 'jobid' ] );
	}

	/**
	 * The type Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_type( $item ) {

		$r = '';
		if ( $types = explode( '+', $item[ 'type' ] ) ) {
			foreach ( $types as $type ) {
				if ( isset( $this->job_types[ $type ] ) ) {
					$r .= $this->job_types[ $type ]->info[ 'name' ] . '<br />';
				}
				else {
					$r .= $type . '<br />';
				}
			}
		}

		return $r;
	}

	/**
	 * The log Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_log( $item ) {

		$r = "<strong><a class=\"thickbox\" href=\"" . admin_url( 'admin-ajax.php' ) . '?&action=backwpup_view_log&logfile=' . $item['file'] .'&_ajax_nonce=' . wp_create_nonce( 'view-logs' ) . "&height=440&width=630&TB_iframe=true\" title=\"" . $item['file'] . "\">" . sprintf( __( '%1$s at %2$s', 'backwpup' ), date_i18n( get_option( 'date_format' ) , $item[ 'logtime' ], TRUE ), date_i18n( get_option( 'time_format' ), $item[ 'logtime' ], TRUE ) ) . ": <i>" . $item[ 'name' ] . "</i></a></strong>";
		$actions               = array();
		$actions[ 'view' ]     = '<a class="thickbox" href="' . admin_url( 'admin-ajax.php' ) . '?&action=backwpup_view_log&logfile=' . $item['file'] .'&_ajax_nonce=' . wp_create_nonce( 'view-logs' ) . '&height=440&width=630&TB_iframe=true" title="' . $item['file'] . '">' . __( 'View', 'backwpup' ) . '</a>';
		if ( current_user_can( 'backwpup_logs_delete' ) )
			$actions[ 'delete' ]   = "<a class=\"submitdelete\" href=\"" . wp_nonce_url( network_admin_url( 'admin.php' ) . '?page=backwpuplogs&action=delete&paged=' . $this->get_pagenum() . '&logfiles[]=' . $item['file'], 'bulk-logs' ) . "\" onclick=\"return showNotice.warn();\">" . __( 'Delete', 'backwpup' ) . "</a>";
		$actions[ 'download' ] = "<a href=\"" . wp_nonce_url( network_admin_url( 'admin.php' ) . '?page=backwpuplogs&action=download&file=' . $item['file'], 'download-backup_' . $item['file'] ) . "\">" . __( 'Download', 'backwpup' ) . "</a>";
		$r .= $this->row_actions( $actions );

		return $r;
	}

	/**
	 * The status Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_status( $item ) {

		$r = '';
		if ( $item[ 'errors' ] > 0 )
			$r .= str_replace( '%d', $item[ 'errors' ], '<span style="color:red;font-weight:bold;">' . _n( "1 ERROR", "%d ERRORS", $item[ 'errors' ], 'backwpup' ) . '</span><br />' );
		if ( $item[ 'warnings' ] > 0 )
			$r .= str_replace( '%d', $item[ 'warnings' ], '<span style="color:#e66f00;font-weight:bold;">' . _n( "1 WARNING", "%d WARNINGS", $item[ 'warnings' ], 'backwpup' ) . '</span><br />' );
		if ( $item[ 'errors' ] == 0 && $item[ 'warnings' ] == 0 )
			$r .= '<span style="color:green;font-weight:bold;">' . __( 'O.K.', 'backwpup' ) . '</span>';

		return $r;
	}


	/**
	 * The size Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_size( $item ) {

		if ( ! empty( $item[ 'backupfilesize' ] ) )
			return size_format( $item[ 'backupfilesize' ], 2 );
		else
			return __( 'Log only', 'backwpup' );
	}

	/**
	 * The runtime Column
	 *
	 * @param $item
	 * @return string
	 */
	function column_runtime( $item ) {

		return $item[ 'runtime' ] . ' ' . __( 'seconds', 'backwpup' );
	}

	/**
	 *
	 */
	public static function load() {

		//Create Table
		self::$listtable = new BackWPup_Page_Logs;

		switch ( self::$listtable->current_action() ) {
			case 'delete':
				if ( ! current_user_can( 'backwpup_logs_delete' ) )
					break;
				if ( is_array( $_GET[ 'logfiles' ] ) ) {
					check_admin_referer( 'bulk-logs' );
					$num = 0;
					foreach ( $_GET[ 'logfiles' ] as $logfile ) {
						if ( is_writeable( BackWPup_Option::get( 'cfg', 'logfolder' ) . $logfile ) && ! is_dir( BackWPup_Option::get( 'cfg', 'logfolder' ) . $logfile ) && ! is_link( BackWPup_Option::get( 'cfg', 'logfolder' ) . $logfile ) )
							unlink( BackWPup_Option::get( 'cfg', 'logfolder' ) . $logfile );
						$num ++;
					}
				}
				break;
			case 'download': //Download Log
				if ( ! current_user_can( 'backwpup_logs' ) )
					break;
				check_admin_referer( 'download-backup_' . $_GET[ 'file' ] );
				if ( is_readable( BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'file' ] ) && ! is_dir( BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'file' ] ) && !is_link( BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'file' ] ) ) {
					header( "Pragma: public" );
					header( "Expires: 0" );
					header( "Cache-Control: must-revalidate, post-check=0, pre-check=0" );
					header( "Content-Type: application/force-download" );
					header( "Content-Disposition: attachment; filename=" . BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'file' ] . ";" );
					header( "Content-Transfer-Encoding: binary" );
					header( "Content-Length: " . filesize( BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'file' ] ) );
					@readfile( BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'file' ] );
					die();
				}
				else {
					header( 'HTTP/1.0 404 Not Found' );
					die();
				}
				break;
		}


		//Save per page
		if ( isset( $_POST[ 'screen-options-apply' ] ) && isset( $_POST[ 'wp_screen_options' ][ 'option' ] ) && isset( $_POST[ 'wp_screen_options' ][ 'value' ] ) && $_POST[ 'wp_screen_options' ][ 'option' ] == 'backwpuplogs_per_page' ) {
			check_admin_referer( 'screen-options-nonce', 'screenoptionnonce' );
			global $current_user;
			if ( $_POST[ 'wp_screen_options' ][ 'value' ] > 0 && $_POST[ 'wp_screen_options' ][ 'value' ] < 1000 ) {
				update_user_option( $current_user->ID, 'backwpuplogs_per_page', (int)$_POST[ 'wp_screen_options' ][ 'value' ] );
				wp_redirect( remove_query_arg( array( 'pagenum', 'apage', 'paged' ), wp_get_referer() ) );
				exit;
			}
		}

		add_screen_option( 'per_page', array(
											'label'   => __( 'Logs', 'backwpup' ),
											'default' => 20,
											'option'  => 'backwpuplogs_per_page'
									   ) );

		self::$listtable->prepare_items();
	}

	/**
	 *
	 * Output css
	 *
	 * @return void
	 */
	public static function admin_print_styles() {

		wp_enqueue_style('backwpupgeneral');

		?>
    <style type="text/css" media="screen">
        .column-id {
            width: 5%;
            text-align: center;
        }

        .column-runtime, .column-status, .column-size {
            width: 8%;
        }

        .column-type {
            width: 15%;
        }
    </style>
	<?php
	}

	/**
	 *
	 * Output js
	 *
	 * @return void
	 */
	public static function admin_print_scripts() {

		wp_enqueue_script( 'backwpupgeneral' );
	}

	/**
	 * Display the page content
	 */
	public static function page() {

		?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2><?php echo esc_html( sprintf( __( '%s Logs', 'backwpup' ), BackWPup::get_plugin_data( 'name' ) ) ); ?></h2>
			<?php BackWPup_Admin::display_messages(); ?>
			<form id="posts-filter" action="" method="get">
				<input type="hidden" name="page" value="backwpuplogs" />
				<?php self::$listtable->display(); ?>
				<div id="ajax-response"></div>
			</form>
		</div>
		<?php
	}

	/**
	 * For displaying log files with ajax
	 */
	public static function ajax_view_log() {

		if ( ! current_user_can( 'backwpup_logs' ) )
			die( -1 );
		check_ajax_referer( 'view-logs' );
		$log_file = BackWPup_Option::get( 'cfg', 'logfolder' ) . $_GET[ 'logfile' ];
		if ( ! is_readable( $log_file ) && ! is_readable( $log_file . '.gz' ) && ! is_readable( $log_file . '.bz2' ) )
			die( -1 );
		//change file end if not html helps if log file compression is on
		if ( ! file_exists( $log_file ) && file_exists( $log_file . '.gz' ) )
			$log_file = $log_file . '.gz';
		if ( ! file_exists( $log_file ) && file_exists( $log_file . '.bz2' ) )
			$log_file = $log_file . '.bz2';
		//output file
		if ( '.gz' == substr( $log_file, -3 ) )
			echo file_get_contents( 'compress.zlib://' .$log_file, FALSE );
		elseif ( '.bz2' == substr( $log_file, -4 ) )
			echo file_get_contents( 'compress.bzip2://' . $log_file, FALSE );
		else
			echo file_get_contents( $log_file, FALSE );
		die();
	}

}

