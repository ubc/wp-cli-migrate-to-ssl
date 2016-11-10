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
	 *     wp migrate-to-ssl --sites="123"
	 *     wp migrate-to-ssl --sites="subdomain.yoursite.com"
	 *     wp migrate-to-ssl --sites="domainmappedsite.com"
	 *     wp migrate-to-ssl --sites="123,456,789"
	 *     wp migrate-to-ssl --sites="123" --dry-run
	 *
	 * @when after_wp_load
	 */

	public $verbose = false;

	function migrate( $args, $assoc_args ) {

		$this->set_verbosity( $assoc_args['verbose'] );

		// We need at least one site ID or domain
		$sites = (array) $this->parse_sites( $assoc_args['sites'] );

		if ( ! $sites ) {
			WP_CLI::error( 'migrate-to-ssl requires at least one site ID or domain', true );
		}

		if ( empty( $sites ) ) {
			WP_CLI::error( 'migrate-to-ssl requires at least one valid site ID or domain. None were found in the site list, or domain mapping tables (if active)', true );
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

			// Numeric, therefore we need the domain or path
			$domain_or_path = $this->get_domain_or_path_from_site_id( $site_string );

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
	 *
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function run_http_search_and_replace_for_sites() {

	}/* run_http_search_and_replace_for_sites() */

	/**
	 *
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function fix_custom_css_files_for_sites() {

	}/* fix_custom_css_files_for_sites() */

	/**
	 *
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function add_new_ssl_certs_for_sites() {

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


	function is_verbose() {
		return ( true === $this->verbose ) ? true : false;
	}/* is_verbose() */

}/* class UBC_Migrate_To_SSL */


// Register the command, only appropriate on a Multisite Install
WP_CLI::add_command( 'migrate-to-ssl', 'UBC_Migrate_To_SSL', array(
	'before_invoke' => function(){
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}
	},
) );
