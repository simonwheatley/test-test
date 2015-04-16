<?php

/**
 * Search and replace strings in the database.
 *
 * @package wp-cli
 */
class Search_Replace_Command extends WP_CLI_Command {

	/**
	 * Search/replace strings in the database.
	 *
	 * ## DESCRIPTION
	 *
	 * This command will go through all rows in all tables and will replace all
	 * appearances of the old string with the new one.
	 *
	 * It will correctly handle serialized values, and will not change primary key values.
	 *
	 * ## OPTIONS
	 *
	 * <old>
	 * : The old string.
	 *
	 * <new>
	 * : The new string.
	 *
	 * [<table>...]
	 * : List of database tables to restrict the replacement to.
	 *
	 * [--network]
	 * : Search/replace through all the tables in a multisite install.
	 *
	 * [--skip-columns=<columns>]
	 * : Do not perform the replacement in the comma-separated columns.
	 *
	 * [--dry-run]
	 * : Show report, but don't perform the changes.
	 *
	 * [--precise]
	 * : Force the use of PHP (instead of SQL) which is more thorough, but slower. Use if you see issues with serialized data.
	 *
	 * [--recurse-objects]
	 * : Enable recursing into objects to replace strings
	 *
	 * [--all-tables-with-prefix]
	 * : Enable replacement on any tables that match the table prefix even if not registered on wpdb
	 *
	 * [--all-tables]
	 * : Enable replacement on ALL tables in the database, regardless of the prefix. Overrides --network and --all-tables-with-prefix.
	 *
	 * ## EXAMPLES
	 *
	 *     wp search-replace 'http://example.dev' 'http://example.com' --skip-columns=guid
	 *
	 *     wp search-replace 'foo' 'bar' wp_posts wp_postmeta wp_terms --dry-run
	 *
	 *     # Turn your production database into a local database
	 *     wp search-replace --url=example.com example.com example.dev
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;
		$old             = array_shift( $args );
		$new             = array_shift( $args );
		$total           = 0;
		$report          = array();
		$dry_run         = self::check_flag( $assoc_args, 'dry-run' );
		$php_only        = self::check_flag( $assoc_args, 'precise' );
		$recurse_objects = self::check_flag( $assoc_args, 'recurse-objects' );

		if ( isset( $assoc_args['skip-columns'] ) ) {
			$skip_columns = explode( ',', $assoc_args['skip-columns'] );
		} else {
			$skip_columns = array();
		}

		// never mess with hashed passwords
		$skip_columns[] = 'user_pass';

		// Determine how to limit the list of tables. Defaults to 'wordpress'
		$table_type = 'wordpress';
		if ( self::check_flag( $assoc_args, 'network' ) ) {
			$table_type = 'network';
		}
		if ( self::check_flag( $assoc_args, 'all-tables-with-prefix' ) ) {
			$table_type = 'all-tables-with-prefix';
		}
		if ( self::check_flag( $assoc_args, 'all-tables' ) ) {
			$table_type = 'all-tables';
		}

		// Get the array of tables to work with. If there is anything left in $args, assume those are table names to use
		$tables = empty( $args ) ? self::get_table_list( $table_type ) : $args;
		foreach ( $tables as $table ) {
			list( $primary_keys, $columns ) = self::get_columns( $table );

			// since we'll be updating one row at a time,
			// we need a primary key to identify the row
			if ( empty( $primary_keys ) ) {
				$report[] = array( $table, '', 'skipped' );
				continue;
			}

			foreach ( $columns as $col ) {
				if ( in_array( $col, $skip_columns ) ) {
					continue;
				}

				if ( ! $php_only ) {
					$serialRow = $wpdb->get_row( "SELECT * FROM `$table` WHERE `$col` REGEXP '^[aiO]:[1-9]' LIMIT 1" );
				}

				if ( $php_only || NULL !== $serialRow ) {
					$type = 'PHP';
					$count = self::php_handle_col( $col, $primary_keys, $table, $old, $new, $dry_run, $recurse_objects );
				} else {
					$type = 'SQL';
					$count = self::sql_handle_col( $col, $table, $old, $new, $dry_run );
				}

				$report[] = array( $table, $col, $count, $type );

				$total += $count;
			}
		}

		if ( ! WP_CLI::get_config( 'quiet' ) ) {

			$table = new \cli\Table();
			$table->setHeaders( array( 'Table', 'Column', 'Replacements', 'Type' ) );
			$table->setRows( $report );
			$table->display();

			if ( ! $dry_run ) {
				WP_CLI::success( "Made $total replacements." );
			}

		}
	}

	/**
	 * Retrieve a list of tables from the database.
	 *
	 * @global wpdb $wpdb
	 *
	 * @param string $limit_to Sting defining how to limit the list of tables to retrieve. Acceptable vales are:
	 *                         - 'wordpress' for default WordPress tables only
	 *                         - 'network' for default Multisite tables only
	 *                         - 'all-tables-with-prefix' for all tables using the WordPress DB prefix
	 *                         - 'all-tables' for all tables in the DB
	 *
	 * @return array The array of table names.
	 */
	private static function get_table_list( $limit_to ) {
		global $wpdb;

		$network = 'network' == $limit_to;

		if ( 'all-tables' == $limit_to ) {
			return $wpdb->get_col( 'SHOW TABLES' );
		}

		$prefix = $network ? $wpdb->base_prefix : $wpdb->prefix;
		$matching_tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $prefix . '%' ) );

		if ( 'all-tables-with-prefix' == $limit_to ) {
			return $matching_tables;
		}

		$allowed_tables = array();
		$allowed_table_types = array( 'tables', 'global_tables' );
		if ( $network ) {
			$allowed_table_types[] = 'ms_global_tables';
		}
		foreach( $allowed_table_types as $table_type ) {
			foreach( $wpdb->$table_type as $table ) {
				$allowed_tables[] = $prefix . $table;
			}
		}

		// Given our matching tables, also allow site-specific tables on the network
		foreach( $matching_tables as $key => $matched_table ) {

			if ( in_array( $matched_table, $allowed_tables ) ) {
				continue;
			}

			if ( $network ) {
				$valid_table = false;
				foreach( array_merge( $wpdb->tables, $wpdb->old_tables ) as $maybe_site_table ) {
					if ( preg_match( "#{$prefix}([\d]+)_{$maybe_site_table}#", $matched_table ) ) {
						$valid_table = true;
					}
				}
				if ( $valid_table ) {
					continue;
				}
			}

			unset( $matching_tables[ $key ] );

		}

		return array_values( $matching_tables );

	}

	private static function sql_handle_col( $col, $table, $old, $new, $dry_run ) {
		global $wpdb;

		if ( $dry_run ) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(`$col`) FROM `$table` WHERE `$col` LIKE %s;", '%' . self::esc_like( $old ) . '%' ) );
		} else {
			return $wpdb->query( $wpdb->prepare( "UPDATE `$table` SET `$col` = REPLACE(`$col`, %s, %s);", $old, $new ) );
		}
	}

	private static function php_handle_col( $col, $primary_keys, $table, $old, $new, $dry_run, $recurse_objects ) {
		global $wpdb;

		// We don't want to have to generate thousands of rows when running the test suite
		$chunk_size = getenv( 'BEHAT_RUN' ) ? 10 : 1000;

		$fields = $primary_keys;
		$fields[] = $col;

		$args = array(
			'table' => $table,
			'fields' => $fields,
			'where' => "`$col`" . ' LIKE "%' . self::esc_like( $old ) . '%"',
			'chunk_size' => $chunk_size
		);

		$it = new \WP_CLI\Iterators\Table( $args );

		$count = 0;

		$replacer = new \WP_CLI\SearchReplacer( $old, $new, $recurse_objects );

		foreach ( $it as $row ) {
			if ( '' === $row->$col )
				continue;

			$value = $replacer->run( $row->$col );

			if ( $dry_run ) {
				if ( $value != $row->$col )
					$count++;
			} else {
				$where = array();
				foreach ( $primary_keys as $primary_key ) {
					$where[ $primary_key ] = $row->$primary_key;
				}

				$count += $wpdb->update( $table, array( $col => $value ), $where );
			}
		}

		return $count;
	}

	private static function get_columns( $table ) {
		global $wpdb;

		$primary_keys = array();

		$columns = array();

		foreach ( $wpdb->get_results( "DESCRIBE $table" ) as $col ) {
			if ( 'PRI' === $col->Key ) {
				$primary_keys[] = $col->Field;
				continue;
			}

			if ( !self::is_text_col( $col->Type ) )
				continue;

			$columns[] = $col->Field;
		}

		return array( $primary_keys, $columns );
	}

	private static function is_text_col( $type ) {
		foreach ( array( 'text', 'varchar' ) as $token ) {
			if ( false !== strpos( $type, $token ) )
				return true;
		}

		return false;
	}

	private static function esc_like( $old ) {
		global $wpdb;

		// Remove notices in 4.0 and support backwards compatibility
		if( method_exists( $wpdb, 'esc_like' ) ) {
			// 4.0
			$old = $wpdb->esc_like( $old );
		} else {
			// 3.9 or less
			$old = like_escape( esc_sql( $old ) );
		}

		return $old;
	}

	/**
	 * Determine the boolean value of a flag in an array.
	 *
	 * Primarily useful for determining the status of a boolean flag for a command. This is needed because a flag can be
	 * prefixed with --no- to set it to false. Therefore it is not sufficient to only check whether a flag is set.
	 *
	 * @param array  $array The array to check.
	 * @param string $key   The key to check for.
	 *
	 * @return bool True if the key is set in the array and is truthy, false otherwise.
	 */
	private static function check_flag( $array, $key ) {
		return isset( $array[ $key ] ) && $array[ $key ];
	}
}

WP_CLI::add_command( 'search-replace', 'Search_Replace_Command' );

