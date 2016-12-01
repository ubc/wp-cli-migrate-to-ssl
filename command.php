<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}


class UBC_Migrate_To_SSL {

	/**
	 * Migrate one or more individual sites in a multisite install to be served via SSL.
	 *
	 * ## OPTIONS
	 *
	 * [--sites]
	 * : Either a single site ID, a single domain name or a comma separated list of either
	 *
	 * [--dry-run]
	 * : Don't actually make the replacements, but print out what they would be.
	 *
	 * [--verbose]
	 * : I heard you like logs in your logs?
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp migrate-to-ssl migrate --sites="123"
	 *     wp migrate-to-ssl migrate --sites="subdomain.yoursite.com"
	 *     wp migrate-to-ssl migrate --sites="domainmappedsite.com"
	 *     wp migrate-to-ssl migrate --sites="123,456,789"
	 *     wp migrate-to-ssl migrate --sites="123" --dry-run
	 *
	 * @when after_wp_load
	 */

	public $verbose = false;
	public $dry_run = false;
	public $url = 'ubccms-local.dev';
	public $prefix = false;
	public $output_file = false;

	function migrate( $args, $assoc_args ) {

		$verbose = ( isset( $assoc_args['verbose'] ) ) ? $assoc_args['verbose'] : false;

		$dry_run = ( isset( $assoc_args['dry-run'] ) ) ? $assoc_args['dry-run'] : false;

		$url = ( isset( $assoc_args['url'] ) ) ? $assoc_args['url'] : false;
		$prefix = ( isset( $assoc_args['prefix'] ) ) ? $assoc_args['prefix'] : false;
		$output = ( isset( $assoc_args['output'] ) ) ? $assoc_args['output'] : false;

		$this->set_verbosity( $verbose );
		$this->set_dry_run( $dry_run );
		$this->set_url( $url );
		$this->set_prefix( $prefix );
		$this->set_output( $output );

		// We need at least one site ID or domain
		$sites = $this->parse_sites( $assoc_args['sites'] );

		if ( ! $sites ) {
			WP_CLI::error( 'migrate-to-ssl requires at least one site ID or domain', true );
		}

		if ( empty( $sites ) ) {
			WP_CLI::error( 'migrate-to-ssl requires at least one valid site ID or domain. None were found in the site list, or domain mapping tables (if active)', true );
		}

		// Normalize data
		if ( 1 === count( $sites ) ) {
			$sites = array( $sites );
		}

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'migrate(): $sites: ' . print_r( $sites, true ) );
		}

		// Now we have an array of arrays, i.e. array( array( 88 => circle.ubccms-local.dev ) )
		//										array( array( <site_id> => <domain> ) )
		// For each one, we need to do a few things;
		// 1) do a search and replace in the database for http://<domain> to https://<domain>
		// 2) check for custom CSS file for this domain and run s&r in that file if necessary
		// 3) run the letsencrypt-auto script to get the ssl cert

		$this->run_http_search_and_replace_for_sites( $sites );

		$this->fix_custom_css_files_for_sites( $sites );

		$this->add_new_ssl_certs_for_sites( $sites );

		WP_CLI::success( print_r( $sites, true ) );

	}/* migrate() */



	/**
	 * Parse the passed sites. We expect a string of site id(s) or domain(s)
	 *
	 * @since 1.0.0
	 *
	 * @param (string) $passed_sites - The --site passed when envoking wp migrate-to-ssl
	 * @return (mixed)
	 */

	function parse_sites( $passed_sites ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'parse_sites(): ' . $passed_sites );
		}

		// Empty? Bail
		if ( empty( $passed_sites ) ) {
			return false;
		}

		// If the string starts or finishes with a space or comma, strip them
		$passed_sites = trim( trim( $passed_sites, ',' ) );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'parse_sites(): Trimmed input: ' . $passed_sites );
		}

		// OK, we have at least one site, let's see if it's comma separated
		$what_to_find = ',';
		if ( preg_match( '/\b' . $what_to_find . '\b/', $passed_sites ) ) {
			return $this->parse_csv_sites( $passed_sites );
		}

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'parse_sites(): Not comma separated' );
		}

		// Not CSV, so let's check it's either an integer and valid site ID
		// or a valid domain
		return $this->get_site_from_single_string( $passed_sites );

	}/* parse_sites() */

	/**
	 * We have a CSV list of sites. Let's parse each one and make sure we have a list
	 * of site IDs
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function parse_csv_sites( $csv_string ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'parse_csv_sites(): Parsing comma separated list' );
		}

		// Explode the string by , so we can get an array of single sites
		$sites = explode( ',', $csv_string );

		// Don't have an array after exploding? That's problematic.
		if ( ! is_array( $sites ) ) {

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'parse_csv_sites(): $csv_string not an array: ' . print_r( $csv_string, true ) );
			}

			WP_CLI::error( 'Could not parse the CSV list of sites.' );
		}

		$parsed_sites = array();

		foreach ( $sites as $key => $site ) {

			// Remove whitespace
			$site = trim( $site );

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'parse_csv_sites(): single $site: ' . $site );
			}

			$this_site = $this->get_site_from_single_string( $site );
			if ( ! empty( $this_site ) ) {
				$parsed_sites[] = $this_site;
			}
		}

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'parse_csv_sites(): $parsed_sites: ' . print_r( $parsed_sites, true ) );
		}

		return $parsed_sites;

	}/* parse_csv_sites() */

	/**
	 * We may have received either a domain or a site ID. Ultimately, we need both
	 * so, first, check if it's a domain/path. If it is, we need to check if it exists in
	 * wp_blogs. If it doesn't, we look in the domain mapping table (if it exists) and,
	 * if found, return the site ID.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function get_site_from_single_string( $site_string ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_from_single_string(): ' . $site_string );
		}

		// Test for a domain/path by looking to see if what's passed is numeric
		if ( is_numeric( $site_string ) ) {

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'get_site_from_single_string(): Is numeric: ' . $site_string );
			}

			// Numeric, therefore we need the domain or path
			$domain_or_path = $this->get_domain_or_path_from_site_id( $site_string );

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'get_site_from_single_string(): Not numeric. $domain_or_path: ' . $domain_or_path );
			}

			// If we got it, ship it. If we don't, panic.
			if ( $domain_or_path ) {
				return array( $site_string => $domain_or_path );
			} else {
				WP_CLI::error( "get_site_from_single_string(): Unable to find path or domain for $site_string", true );
			}
		}

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_from_single_string(): Not numeric' );
		}

		// OK, we have a domain/path. if it's in the wp_blogs table, we can grab the 'blog_id'
		$site_id = $this->get_site_id_from_domain_or_path( $site_string );
		if ( ! empty( $site_id ) ) {
			return array( $site_id => $site_string );
		}

		return false;

	}/* get_site_from_single_string() */


	/**
	 * If this is a domain, we'll have a period in the URL, otherwise it's a path
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function get_site_id_from_domain_or_path( $string ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_id_from_domain_or_path(): ' . $string );
		}

		$what_to_find = '.';
		if ( preg_match( '/\b' . $what_to_find . '\b/', $string ) ) {
			return $this->get_site_id_from_domain( $string );
		}

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_id_from_domain_or_path(): Not domain' );
		}

		return $this->get_site_id_from_path( $string );

	}/* get_site_id_from_domain_or_path() */


	/**
	 * Fetch, from the database, the site ID for the given domain. If it's not in the wp_blogs
	 * table, we'll look in the domain mapping table (if it exists)
	 *
	 * @since 1.0.0
	 *
	 * @param (string) $domain - the domain we're looking up
	 * @return false|int false if domain doesn't exist, site ID otherwise
	 */

	function get_site_id_from_domain( $domain ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_id_from_domain(): ' . $domain );
		}

		$id = get_blog_id_from_url( $domain );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_id_from_domain(): $id: ' . $id );
		}

		// get_blog_id_from_url() returns 0 if domain isn't found, check domain mapped sites
		if ( 0 === $id ) {
			return $this->check_domain_mapped_sites( $domain );
		}

		// We gone done got an ID
		return $id;

	}/* get_site_id_from_domain() */


	function get_site_id_from_path( $path ) {

		$id = get_blog_id_from_url( $this->get_site_domain(), $path );

		if ( 0 === $id ) {
			return false;
		}

		return $id;

	}/* get_site_id_from_path() */


	/**
	 * Get the domain or path from the passed site ID
	 *
	 * @since 1.0.0
	 *
	 * @param $site_id
	 * @return null
	 */

	function get_domain_or_path_from_site_id( $site_id ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_domain_or_path_from_site_id(): $site_id: ' . $site_id );
		}

		global $wpdb;

		$what_to_select = $this->get_ms_install_type();

		$return = $wpdb->get_var( $wpdb->prepare(
			'SELECT ' . $what_to_select . ' FROM ' . $wpdb->prefix . "blogs WHERE blog_id = '%s'",
			$site_id
		) );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_domain_or_path_from_site_id(): $return: ' . $return );
		}

		return $return;

	}/* get_domain_or_path_from_site_id() */


	function get_site_domain() {
		return constant( 'DOMAIN_CURRENT_SITE' );
	}/* get_site_domain() */


	function get_ms_install_type() {
		return ( $this->is_subdomain_install() ) ? 'domain' : 'path';
	}/* get_ms_install_type() */

	function is_subdomain_install() {
		return constant( 'SUBDOMAIN_INSTALL' );
	}/* is_subdomain_install() */


	/**
	 * We've checked the normal domains for the site,
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function check_domain_mapped_sites( $domain ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'check_domain_mapped_sites(): Checking domain mapped sites for ' . $domain );
		}

		global $wpdb;

		$blog_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT blog_id FROM ' . $wpdb->prefix . "domain_mapping WHERE domain = '%s'",
			$domain
		) );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'check_domain_mapped_sites(): $blog_id: ' . $blog_id );
		}

		return $blog_id;

	}/* check_domain_mapped_sites() */


	/**
	 * Run and S&R on the passed sites.
	 *
	 * @since 1.0.0
	 *
	 * @param (array) $sites
	 * @return null
	 */

	function run_http_search_and_replace_for_sites( $sites ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'run_http_search_and_replace_for_sites(): ' . print_r( $sites, true ) );
		}

		// Use wp-cli internal s&r i.e. : wp search-replace 'foo' 'bar' wp_posts wp_postmeta wp_terms --dry-run
		// We have an array of arrays. Each key in the internal array is the site ID
		$number_of_sites_to_run_through = count( $sites );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'run_http_search_and_replace_for_sites() Number of sites ' . $number_of_sites_to_run_through );
		}

		foreach ( $sites as $key => $site_details ) {

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'run_http_search_and_replace_for_sites() foreach ' . print_r( array( $key, $site_details ), true ) );
			}

			reset( $site_details );
			$site_id = key( $site_details );
			$domain = $site_details[ $site_id ];

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'run_http_search_and_replace_for_sites() foreach $site_id ' . $site_id );
			}

			// $this_sites_tables = $this->get_tables_for_site_id( $site_id );
			//
			// if ( $this->is_verbose() ) {
			// 	WP_CLI::log( 'run_http_search_and_replace_for_sites() $this_sites_tables ' . print_r( $this_sites_tables, true ) );
			// }
			//
			// // We now have an array of tables, wp-cli s&r requires space-separated list
			// $tables_as_space_separated_string = implode( " ", $this_sites_tables );

			$search_for		= 'http://' . $domain;
			$replace_with	= 'https://' . $domain;

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'About to S&R for $site_id: ' . $site_id . '. Replacing ' . $search_for . ' with ' . $replace_with );
			}

			$s_and_r_result = WP_CLI::launch_self( 'search-replace', array( $search_for, $replace_with, "wp_{$site_id}_*" ), array( 'url' => $this->url, 'dry-run' => $this->dry_run ), true, true );

			if ( $this->is_verbose() ) {
				WP_CLI::log( '$s_and_r_result: ' . $s_and_r_result );
			}
		}

	}/* run_http_search_and_replace_for_sites() */


	/**
	 * Get the database tables for a specific site ID.
	 * If site_id is 123 then it runs a query similar to
	 * SHOW TABLES LIKE 'wp\_123\_%';
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function get_tables_for_site_id( $site_id ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_tables_for_site_id() for ' . $site_id );
		}

		$site_id = absint( $site_id );

		global $wpdb;

		$table_string = $wpdb->prefix . $site_id . '\\_%';
		$site_tables = $wpdb->get_results( $wpdb->prepare(
			"SHOW TABLES LIKE %s",
			$table_string
		), ARRAY_N );

		// This data is a bit of a mess. An array of arrays with the tables the values of the inner array
		// Tidy it up
		$final_site_tables = array();

		foreach ( $site_tables as $key => $table_array ) {
			$final_site_tables[] = $table_array[0];
		}

		return $final_site_tables;

	}/* get_tables_for_site_id() */


	/**
	 * Oftentimes, people hardcode URLs in CSS files. This isn't clever. But there you have it.
	 * We'll look for wp-content/blogs.dir/{$site_id}/file/custom-css/custom-css-*.css and
	 * run a sed within those files
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function fix_custom_css_files_for_sites( $sites ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'fix_custom_css_files_for_sites()' );
		}

		$content_dir = constant( 'WP_CONTENT_DIR' );

		foreach ( $sites as $key => $site_details ) {

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'fix_custom_css_files_for_sites() foreach ' . print_r( array( $key, $site_details ), true ) );
			}

			reset( $site_details );
			$site_id = key( $site_details );
			$domain = $site_details[ $site_id ];

			$custom_css_dir = trailingslashit( $content_dir ) . 'blogs.dir/' . $site_id . '/files/custom-css/';

			// Test if that directory exists
			if ( ! file_exists( $custom_css_dir ) ) {
				continue;
			}

			// Find the custom css files only (just in case there's other random stuff in here)
			$files = preg_grep( '~^custom-css-.*\.(css)$~', scandir( $custom_css_dir ) );

			if ( ! $files || ! is_array( $files ) || empty( $files ) ) {
				continue;
			}

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'fix_custom_css_files_for_sites() CSS Files ' . print_r( $files, true ) );
			}

			$search_for		= 'http://' . $domain;
			$replace_with	= 'https://' . $domain;

			foreach( $files as $key => $file_name ) {

				$file_path = $custom_css_dir . $file_name;

				if ( $this->is_verbose() ) {
					WP_CLI::log( 'In ' . $file_path . ' we are replacing ' . $search_for . ' with ' . $replace_with );
				}

				$file_contents = file_get_contents( $file_path );
				$file_contents = str_replace( $search_for, $replace_with, $file_contents );
				file_put_contents( $file_path, $file_contents );
			}

		}

	}/* fix_custom_css_files_for_sites() */

	/**
	 *
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function add_new_ssl_certs_for_sites( $sites ) {

		foreach ( $sites as $key => $site_details ) {

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'add_new_ssl_certs_for_sites() foreach ' . print_r( array( $key, $site_details ), true ) );
			}

			reset( $site_details );
			$site_id = key( $site_details );
			$domain = $site_details[ $site_id ];

			WP_CLI::confirm( 'Would you like to fetch a new SSL Certificate for ' . $domain . ' ?' );
		}

	}/* add_new_ssl_certs_for_sites() */


	/**
	 * Turn on verbosity if set in flags
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function set_verbosity( $verbose ) {

		if ( $verbose ) {
			$this->verbose = true;
		} else {
			$this->verbose = false;
		}

	}/* set_verbosity() */


	function set_dry_run( $dry_run ) {

		if ( $dry_run ) {
			$this->dry_run = true;
		} else {
			$this->dry_run = false;
		}

	}/* set_dry_run() */

	function set_url( $url ) {

		if ( $url ) {
			$this->url = $url;
		}

	}/* set_url() */

	function set_prefix( $prefix ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'set_prefix(): ' . print_r( $prefix, true ) );
		}

		if ( $prefix ) {
			$this->prefix = $prefix;
		} else {
			global $wpdb;
			$this->prefix = $wpdb->prefix;
		}

	}/* set_prefix() */

	function set_output( $output ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'set_output(): ' . print_r( $output, true ) );
		}

		if ( $output ) {
			$this->output = $output;
		}

	}/* set_output() */

	function is_verbose() {
		return ( true === $this->verbose ) ? true : false;
	}/* is_verbose() */


	/**
	 * Fetch a list of active sites that have at least 1  Password Protected Post
	 *
	 * ## OPTIONS
	 *
	 *
	 * [--dry-run]
	 * : Don't actually make the replacements, but print out what they would be.
	 *
	 * [--verbose]
	 * : I heard you like logs in your logs?
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp migrate-to-ssl ppplist
	 *     wp migrate-to-ssl ppplist --dry-run
	 *     wp migrate-to-ssl ppplist --verbose
	 *
	 * @when after_wp_load
	 */
	function ppplist( $args, $assoc_args ) {

		$verbose = ( isset( $assoc_args['verbose'] ) ) ? $assoc_args['verbose'] : false;
		$dry_run = ( isset( $assoc_args['dry-run'] ) ) ? $assoc_args['dry-run'] : false;
		$prefix = ( isset( $assoc_args['prefix'] ) ) ? $assoc_args['prefix'] : false;
		$output = ( isset( $assoc_args['output'] ) ) ? $assoc_args['output'] : false;

		$this->set_dry_run( $dry_run );
		$this->set_verbosity( $verbose );
		$this->set_prefix( $prefix );
		$this->set_output( $output );

		// See if we have this stored as a transient
		if ( false === ( $admin_emails_for_sites_with_ppps = get_transient( 'ubc_ppp_sites' ) ) ) {

			// Get a list of site IDs. We'll need these to form the table names, i.e. wp_1223_posts
			$all_site_ids = $this->gather_site_ids();

			// Now gather the tables that exist that match these site IDs
			$all_table_names = $this->gather_available_tables( $all_site_ids );

			// Now loop over these tables to find the sites with Password Protected Posts
			$sites_with_ppps = $this->find_sites_with_ppp( $all_table_names );

			// Now get the admin email address from each of these sites
			$admin_emails_for_sites_with_ppps = $this->get_admin_emails( $sites_with_ppps );

			set_transient( 'ubc_ppp_sites', $admin_emails_for_sites_with_ppps, 12 * HOUR_IN_SECONDS );

		}

		if ( 'file' === $this->output ) {
			$this->generate_output_file( $admin_emails_for_sites_with_ppps, '/home/sysadmin/', 'domain-mapped-sites-with-ppp.json' );
		}

		WP_CLI::success( print_r( count( $admin_emails_for_sites_with_ppps ), true ) );

	}/* ppplist */

	/**
	 * Get a list of all non-archived sites
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function gather_site_ids() {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'gather_site_ids()' );
		}

		global $wpdb;

		$site_ids = $wpdb->get_results( $wpdb->prepare(
			'SELECT blog_id FROM ' . $wpdb->blogs . " WHERE archived = '%s'",
			0
		), ARRAY_N );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'gather_site_ids(): $site_ids: ' . $site_ids );
		}

		return $site_ids;

	}/* gather_site_ids() */

	/**
	 * Gather a list of _options tables we have in the database for the passed in sites.
	 * i.e. if a site ID is 123 check if we have wp_123_options table.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function gather_available_tables( $all_site_ids ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'gather_available_tables()' );
		}

		if ( ! is_array( $all_site_ids ) || empty( $all_site_ids ) ) {
			WP_CLI::error( 'gather_available_tables() requires a non-empty array of Site IDs', true );
		}

		global $wpdb;

		// Start our output
		$available_tables = array();

		// Need this for our progress ticker
		$total_num_of_sites = count( $all_site_ids );

		// This is going to be a slow process, so let's keep us up-to-date
		$progress = \WP_CLI\Utils\make_progress_bar( 'Determining Available Tables', $total_num_of_sites );

		// Loop over each site ID and see if the table wp_<site_id>_posts exists
		foreach ( $all_site_ids as $key => $site_id_array ) {

			$site_id = $site_id_array[0];

			$table_name = $this->prefix . $site_id . '_posts';

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'Checking if ' . $table_name . ' exists' );
			}

			$table_exists = $wpdb->get_var( $wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table_name
			) );

			if ( $this->is_verbose() && $table_exists ) {
				WP_CLI::log( 'Table ' . $table_name . ' exists' );
			}

			if ( $table_exists ) {
				$available_tables[] = array( 'site_id' => $site_id, 'table_name' => $table_name );
			}

			$progress->tick();
		}

		// End the ticker
		$progress->finish();

		// Ship it
		return $available_tables;

	}/* gather_available_tables() */

	/**
	 * Look through the passed set of tables and then see if a post with a post_password that isn't empty
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function find_sites_with_ppp( $all_table_names ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'find_sites_with_ppp()' );
		}

		if ( ! is_array( $all_table_names ) || empty( $all_table_names ) ) {
			WP_CLI::error( 'find_sites_with_ppp() requires a non-empty array of Table Names', true );
		}

		global $wpdb;

		$sites_with_ppps = array();

		foreach ( $all_table_names as $key => $table_details ) {

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'Checking ' . print_r( $table_details['table_name'], true ) . ' for PPPs' );
			}

			$post_ids = $wpdb->get_results( $wpdb->prepare(
				'SELECT ID FROM ' . $table_details['table_name'] . " WHERE post_password != %s",
				''
			), ARRAY_N );

			// If $post_ids is empty, we have no ppps, so don't add it to the list
			if ( empty( $post_ids ) ) {
				continue;
			}

			// We need to know if this site is domain-mapped.
			$mapped = $wpdb->get_var( $wpdb->prepare(
				'SELECT blog_id FROM ' . $this->prefix . "domain_mapping WHERE blog_id = '%d'",
				$table_details['site_id']
			) );

			// Not mapped, don't add.
			if ( $mapped !== $table_details['site_id'] ) {
				continue;
			}

			if ( $this->is_verbose() ) {
				WP_CLI::log( $table_details['table_name'] . ' has at least one PPP and is domain mapped. Adding to list.' );
			}

			$sites_with_ppps[] = $table_details['site_id'];

		}

		return $sites_with_ppps;

	}/* find_sites_with_ppp() */


	/**
	 * Get the site_url and admin_email options for the passed site IDs
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function get_admin_emails( $sites_with_ppps ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_admin_emails()' );
		}

		if ( ! is_array( $sites_with_ppps ) || empty( $sites_with_ppps ) ) {
			WP_CLI::error( 'get_admin_emails() requires a non-empty array of Site IDs', true );
		}

		global $wpdb;

		$contact_details_for_sites_with_ppps = array();

		foreach ( $sites_with_ppps as $key => $site_id ) {

			$table_name = $this->prefix . $site_id . '_options';

			if ( $this->is_verbose() ) {
				WP_CLI::log( 'Collecting details for site ID: ' . $site_id . ' and table name ' . $table_name );
			}

			$url = $wpdb->get_var( $wpdb->prepare(
				'SELECT option_value FROM ' . $table_name . " WHERE option_name = %s",
				'siteurl'
			) );

			$admin_email = $wpdb->get_var( $wpdb->prepare(
				'SELECT option_value FROM ' . $table_name . " WHERE option_name = %s",
				'admin_email'
			) );

			$mapped_domain = $wpdb->get_var( $wpdb->prepare(
				'SELECT domain FROM ' . $this->prefix . "domain_mapping WHERE blog_id = %d",
				$site_id
			) );

			$contact_details_for_sites_with_ppps[] = array( 'url' => $url, 'mapped_domain' => $mapped_domain, 'admin_email' => $admin_email, 'ID' => $site_id );

		}

		return $contact_details_for_sites_with_ppps;

	}/* get_admin_emails() */


	function generate_output_file( $output, $path, $file_name ) {

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'generate_output_file(): ' . $path . $file_name );
		}

		$full_file_path = $path . $file_name;

		if ( is_array( $output ) ) {

			foreach ( $output as $id => $data ) {
				file_put_contents( $full_file_path, json_encode( $data, JSON_PRETTY_PRINT ), FILE_APPEND );
			}
		} else {
			file_put_contents( $full_file_path, print_r( $output, true ) );
		}


	}/* generate_output_file() */

}/* class UBC_Migrate_To_SSL */


// Register the command, only appropriate on a Multisite Install
WP_CLI::add_command( 'migrate-to-ssl', 'UBC_Migrate_To_SSL', array(
	'before_invoke' => function(){
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}
	},
) );
