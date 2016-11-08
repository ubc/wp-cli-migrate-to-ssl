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
	 * [--site]
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
	 *     wp migrate-to-ssl --site="123"
	 *     wp migrate-to-ssl --site="subdomain.yoursite.com"
	 *     wp migrate-to-ssl --site="domainmappedsite.com"
	 *     wp migrate-to-ssl --site="123,456,789"
	 *     wp migrate-to-ssl --site="123" --dry-run
	 *
	 * @when after_wp_load
	 */

	public $verbose = false;

	function migrate( $args, $assoc_args ) {

		$this->set_verbosity( $assoc_args['verbose'] );

		// We need at least one site ID or domain
		$sites = $this->parse_sites( $assoc_args['site'] );

		WP_CLI::log( $sites );

		if ( ! $sites ) {
			WP_CLI::error( 'migrate-to-ssl requires at least one site ID or domain', true );
		}

		WP_CLI::line( $assoc_args['dry-run'] );
		WP_CLI::log( 'Test Log' );
		WP_CLI::success( "Hello world. Testing." . $assoc_args['site'] );

	}/* migrate() */


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
		}

	}/* set_verbosity() */


	function is_verbose() {
		return $this->verbose;
	}/* is_verbose() */


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

	function parse_csv_sites( $csv_string ){

	}/* parse_csv_sites() */

	/**
	 * We may have received either a domain or a site ID. Ultimately, we need site IDs
	 * so, first, check if it's a domain/path. If it is, we need to check if it exists in
	 * wp_blogs. If it doesn't, we look in the domain mapping table (if it exists) and,
	 * if found, return the site ID.
	 *
	 * @since 1.0.0
	 *
	 * @param null
	 * @return null
	 */

	function get_site_from_single_string( $site_string ){

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_from_single_string(): ' . $site_string );
		}

		// Test for a domain/path by looking to see if what's passed is numeric
		if ( is_numeric( $site_string ) ) {
			return intval( $site_string );
		}

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'get_site_from_single_string(): Not numeric' );
		}

		// OK, we have a domain/path. if it's in the wp_blogs table, we can grab the 'blog_id'
		return $this->get_site_id_from_domain_or_path( $site_string );

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


	function get_site_domain() {
		return constant( 'DOMAIN_CURRENT_SITE' );
	}/* get_site_domain() */

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
		$table_name = $wpdb->prefix . 'domain_mapping';

		$blog_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT blog_id FROM " . $wpdb->prefix . "domain_mapping WHERE domain = '%s'",
			$domain
		) );

		if ( $this->is_verbose() ) {
			WP_CLI::log( 'check_domain_mapped_sites(): $blog_id: ' . $blog_id );
		}

		return $blog_id;

	}/* check_domain_mapped_sites() */


}/* class UBC_Migrate_To_SSL */


// Register the command, only appropriate on a Multisite Install
WP_CLI::add_command( 'migrate-to-ssl', 'UBC_Migrate_To_SSL', array(
	'before_invoke' => function(){
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is not a multisite install.' );
		}
	},
) );
