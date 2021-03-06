<?php
/**
 * Class for BackWPup cron methods
 */
class BackWPup_Cron {

	/**
	 * @static
	 *
	 * @param $arg
	 * @internal param $args
	 */
	public static function run( $arg ) {

		$job_array = BackWPup_Job::get_working_data( 'ARRAY' );
		if ( is_array( $job_array ) ) {
			//reschedule restart
			wp_schedule_single_event( time() + 60, 'backwpup_cron', array( 'id' => 'restart' ) );
			//restart job if not working
			$not_worked_time = microtime( TRUE ) - $job_array[ 'timestamp_last_update' ];
			if ( $not_worked_time > 300 )
				BackWPup_Job::start_wp_cron( 0 );
		}
		elseif ( $arg != 'restart' ) {
			//check that job exits
			$jobids = BackWPup_Option::get_job_ids( 'activetype', 'wpcron' );
			if ( ! in_array( $arg, $jobids) )
				return;
			//reschedule job for next run
			$cron_next = self::cron_next( BackWPup_Option::get( $arg, 'cron' ) );
			wp_schedule_single_event( $cron_next, 'backwpup_cron', array( 'id' => $arg ) );
			//start job
			BackWPup_Job::start_wp_cron( $arg );
		}
	}


	/**
	 * Check Jobs worked and Cleanup logs and so on
	 */
	public static function check_cleanup() {

		$job_object = BackWPup_Job::get_working_data( 'OBJECT' );
		$jobids = BackWPup_Option::get_job_ids( );

		// check aborted jobs for longer than a tow hours, abort them courtly and send mail
		if ( is_object( $job_object ) && ! empty( $job_object->logfile ) ) {
			$not_worked_time = microtime( TRUE ) - $job_object->timestamp_last_update;
			if ( $not_worked_time > 7200 ) {
				$job_object->log( E_USER_ERROR, __( 'Aborted, because no progress for 2 hours!', 'backwpup' ), __FILE__, __LINE__ );
				unlink( BackWPup::get_plugin_data( 'running_file' ) );
			}
		}

		//Compress not compressed logs
		if ( function_exists( 'gzopen' ) && ! is_object( $job_object ) && BackWPup_Option::get(  'cfg', 'gzlogs' ) ) {
			//Compress logs from last Jobs
			foreach ( $jobids as $jobid ) {
				$log_file = BackWPup_Option::get( $jobid, 'logfile' );
				//compress uncompressed
				if ( is_writeable( $log_file ) && '.html' == substr( $log_file, -5 ) ) {
					$compress = new BackWPup_Create_Archive( $log_file . '.gz' );
					if ( $compress->add_file( $log_file ) ) {
						BackWPup_Option::update( $jobid, 'logfile', $log_file. '.gz' );
						unlink( $log_file );
					}
					unset( $compress );
				}

			}
			//Compress old not compressed logs
			if ( $dir = opendir( BackWPup_Option::get( 'cfg', 'logfolder' ) ) ) {
				while ( FALSE !== ( $file = readdir( $dir ) ) ) {
					if ( is_writeable( BackWPup_Option::get( 'cfg', 'logfolder' ) . $file ) && '.html' == substr( $file, -5 ) ) {
						$compress = new BackWPup_Create_Archive(  BackWPup_Option::get( 'cfg', 'logfolder' ) . $file . '.gz' );
						if ( $compress->add_file( BackWPup_Option::get( 'cfg', 'logfolder' ) . $file ) ) {
							unlink( BackWPup_Option::get( 'cfg', 'logfolder' ) . $file );
						}
						unset( $compress );
					}
				}
				closedir( $dir );
			}
		}

		//Jobs cleanings
		if ( ! is_object( $job_object ) ) {
			//remove restart cron
			wp_clear_scheduled_hook( 'backwpup_cron', array( 'id' => 'restart' ) );
			//temp cleanup
			BackWPup_Job::clean_temp_folder();
		}
	}


	/**
	 * Start job if in cron and run query args are set.
	 */
	public static function cron_active() {

		//only if cron active
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON )
			return;

		//only work if backwpup_run as query var ist set and nothing else and the value ist right
		if ( empty( $_GET[ 'backwpup_run' ] ) || ! in_array( $_GET[ 'backwpup_run' ], array( 'test','restart', 'runnow', 'runnowalt', 'runext', 'cronrun' ) ) )
			return;

		//special header
		@session_write_close();
		@header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), TRUE );
		@header( 'X-Robots-Tag: noindex, nofollow', TRUE );
		@header( 'x-backwpup-ver: ' . BackWPup::get_plugin_data( 'version' ), TRUE );
		nocache_headers();

		//on test die for fast feedback
		if ( $_GET[ 'backwpup_run' ] == 'test' )
			die();

		// generate normal nonce
		$nonce = substr( wp_hash( wp_nonce_tick() . 'backwup_job_run-' . $_GET[ 'backwpup_run' ], 'nonce' ), - 12, 10 );
		//special nonce on external start
		if ( $_GET[ 'backwpup_run' ] == 'runext' )
			$nonce = BackWPup_Option::get( 'cfg', 'jobrunauthkey' );
		// check nonce
		if ( empty( $_GET['_nonce'] ) || $nonce != $_GET['_nonce'] )
			return;

		//check runext is allowed for job
		if ( $_GET[ 'backwpup_run' ] == 'runext' ) {
			$jobids_external = BackWPup_Option::get_job_ids( 'activetype', 'link' );
			if ( !isset( $_GET[ 'jobid' ] ) || ! in_array( $_GET[ 'jobid' ], $jobids_external ) )
				return;
		}

		//run BackWPup job
		BackWPup_Job::start_http( $_GET[ 'backwpup_run' ] );
		die();
	}


	/**
	 *
	 * Get the local time timestamp of the next cron execution
	 *
	 * @param string $cronstring  cron (* * * * *)
	 * @return int timestamp
	 */
	public static function cron_next( $cronstring ) {

		$cron      = array();
		$cronarray = array();
		//Cron string
		list( $cronstr[ 'minutes' ], $cronstr[ 'hours' ], $cronstr[ 'mday' ], $cronstr[ 'mon' ], $cronstr[ 'wday' ] ) = explode( ' ', $cronstring, 5 );

		//make arrays form string
		foreach ( $cronstr as $key => $value ) {
			if ( strstr( $value, ',' ) )
				$cronarray[ $key ] = explode( ',', $value );
			else
				$cronarray[ $key ] = array( 0 => $value );
		}

		//make arrays complete with ranges and steps
		foreach ( $cronarray as $cronarraykey => $cronarrayvalue ) {
			$cron[ $cronarraykey ] = array();
			foreach ( $cronarrayvalue as $value ) {
				//steps
				$step = 1;
				if ( strstr( $value, '/' ) )
					list( $value, $step ) = explode( '/', $value, 2 );
				//replace weekday 7 with 0 for sundays
				if ( $cronarraykey == 'wday' )
					$value = str_replace( '7', '0', $value );
				//ranges
				if ( strstr( $value, '-' ) ) {
					list( $first, $last ) = explode( '-', $value, 2 );
					if ( ! is_numeric( $first ) || ! is_numeric( $last ) || $last > 60 || $first > 60 ) //check
						return 2147483647;
					if ( $cronarraykey == 'minutes' && $step < 5 ) //set step minimum to 5 min.
						$step = 5;
					$range = array();
					for ( $i = $first; $i <= $last; $i = $i + $step ) {
						$range[ ] = $i;
					}
					$cron[ $cronarraykey ] = array_merge( $cron[ $cronarraykey ], $range );
				}
				elseif ( $value == '*' ) {
					$range = array();
					if ( $cronarraykey == 'minutes' ) {
						if ( $step < 10 ) //set step minimum to 5 min.
							$step = 10;
						for ( $i = 0; $i <= 59; $i = $i + $step ) {
							$range[ ] = $i;
						}
					}
					if ( $cronarraykey == 'hours' ) {
						for ( $i = 0; $i <= 23; $i = $i + $step ) {
							$range[ ] = $i;
						}
					}
					if ( $cronarraykey == 'mday' ) {
						for ( $i = $step; $i <= 31; $i = $i + $step ) {
							$range[ ] = $i;
						}
					}
					if ( $cronarraykey == 'mon' ) {
						for ( $i = $step; $i <= 12; $i = $i + $step ) {
							$range[ ] = $i;
						}
					}
					if ( $cronarraykey == 'wday' ) {
						for ( $i = 0; $i <= 6; $i = $i + $step ) {
							$range[ ] = $i;
						}
					}
					$cron[ $cronarraykey ] = array_merge( $cron[ $cronarraykey ], $range );
				}
				else {
					//Month names
					if ( strtolower( $value ) == 'jan' )
						$value = 1;
					if ( strtolower( $value ) == 'feb' )
						$value = 2;
					if ( strtolower( $value ) == 'mar' )
						$value = 3;
					if ( strtolower( $value ) == 'apr' )
						$value = 4;
					if ( strtolower( $value ) == 'may' )
						$value = 5;
					if ( strtolower( $value ) == 'jun' )
						$value = 6;
					if ( strtolower( $value ) == 'jul' )
						$value = 7;
					if ( strtolower( $value ) == 'aug' )
						$value = 8;
					if ( strtolower( $value ) == 'sep' )
						$value = 9;
					if ( strtolower( $value ) == 'oct' )
						$value = 10;
					if ( strtolower( $value ) == 'nov' )
						$value = 11;
					if ( strtolower( $value ) == 'dec' )
						$value = 12;
					//Week Day names
					if ( strtolower( $value ) == 'sun' )
						$value = 0;
					if ( strtolower( $value ) == 'sat' )
						$value = 6;
					if ( strtolower( $value ) == 'mon' )
						$value = 1;
					if ( strtolower( $value ) == 'tue' )
						$value = 2;
					if ( strtolower( $value ) == 'wed' )
						$value = 3;
					if ( strtolower( $value ) == 'thu' )
						$value = 4;
					if ( strtolower( $value ) == 'fri' )
						$value = 5;
					if ( ! is_numeric( $value ) || $value > 60 ) //check
						return 2147483647;
					$cron[ $cronarraykey ] = array_merge( $cron[ $cronarraykey ], array( 0 => $value ) );
				}
			}
		}

		//generate years
		for ( $i = gmdate( 'Y' ); $i < gmdate( 'Y', 2147483647 ); $i ++ ) {
			$cron[ 'year' ][ ] = $i;
		}

		//calc next timestamp
		$current_timestamp = current_time( 'timestamp' );
		foreach ( $cron[ 'year' ] as $year ) {
			foreach ( $cron[ 'mon' ] as $mon ) {
				foreach ( $cron[ 'mday' ] as $mday ) {
					if ( ! checkdate( $mon, $mday, $year ) )
						continue;
					foreach ( $cron[ 'hours' ] as $hours ) {
						foreach ( $cron[ 'minutes' ] as $minutes ) {
							$timestamp = gmmktime( $hours, $minutes, 0, $mon, $mday, $year );
							if ( $timestamp && in_array( gmdate( 'j', $timestamp ), $cron[ 'mday' ] ) && in_array( gmdate( 'w', $timestamp ), $cron[ 'wday' ] ) && $timestamp > $current_timestamp )
								return $timestamp - ( get_option( 'gmt_offset' ) * 3600 );
						}
					}
				}
			}
		}

		return 2147483647;
	}

}
