<?php

/*
	Plugin Name: Alley Sitemaps
	Plugin URI: http://www.alleyinteractive.com/
	Description: Better XML Sitemaps than everything else
	Version: 0.1
	Author: Matthew Boynes
	Author URI: http://www.alleyinteractive.com/
*/
/*  This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( !class_exists( 'Alley_Sitemap' ) ) :

class Alley_Sitemap {

	private static $instance;

	private $cache_key = 'alley-sitemap-index';

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Alley_Sitemap;
			self::$instance->setup();
		}
		return self::$instance;
	}


	/**
	 * Hook in where we need
	 *
	 * @return void
	 */
	public function setup() {
		add_action( 'init', array( $this, 'rewrite_rules' ) );
		add_action( 'parse_request', array( $this, 'intercept_sitemap_request' ) );
		add_action( 'alley_sitemap_indices', array( $this, 'apply_lastmods_to_index' ) );
	}


	/**
	 * Add relevant rewrite rules and tags
	 *
	 * @return void
	 */
	public function rewrite_rules() {
		add_rewrite_tag( '%alleymap%', '[^/]+' );
		add_rewrite_rule( 'sitemap-index.xml', 'index.php?alleymap=index', 'top' );
		add_rewrite_rule( '([-_a-z0-9]+-sitemap-\d+).xml', 'index.php?alleymap=$matches[1]', 'top' );
	}


	/**
	 * Dispatch the appropriate sitemap for relevant requests
	 *
	 * @param object $wp
	 * @return void
	 */
	public function intercept_sitemap_request( $wp ) {
		if ( ! empty( $wp->query_vars['alleymap'] ) ) {
			$sitemap = $wp->query_vars['alleymap'];
			header( 'Content-Type: text/xml' );
			if ( 'index' == $sitemap ) {
				$this->start_index();
				$this->do_index();
				$this->end_index();
			} else {
				$this->start_sitemap();
				if ( preg_match( '/^(.*)-posts-sitemap-(\d+)$/', $sitemap, $matches ) )
					$this->do_posts_sitemap( $matches[1], $matches[2] );
				elseif ( preg_match( '/^(.*)-terms-sitemap-(\d+)$/', $sitemap, $matches ) )
					$this->do_terms_sitemap( $matches[1], $matches[2] );
				elseif ( preg_match( '/^authors-sitemap-(\d+)$/', $sitemap, $matches ) )
					$this->do_authors_sitemap( $matches[1] );
				$this->end_sitemap();
			}
			exit;
		}
	}


	/**
	 * Output the sitemap index
	 *
	 * @return void
	 */
	public function do_index() {
		$indices = array();

		$posts = $this->get_post_stats();
		foreach ( $posts as $row ) {
			$pages = ceil( $row->total / 1000 );
			for ( $i = 1; $i <= $pages; $i++ ) {
				$indices["{$row->post_type}-posts-sitemap-{$i}.xml"] = null;
			}
		}

		$taxonomies = $this->get_taxonomy_stats();
		foreach ( $taxonomies as $row ) {
			$pages = ceil( $row->total * $row->post_type_count / 1000 );
			if ( ! $pages )
				continue;
			for ( $i = 1; $i <= $pages; $i++ ) {
				$indices["{$row->taxonomy}-terms-sitemap-{$i}.xml"] = null;
			}
		}

		$author_count = $this->get_author_stats();
		$pages = ceil( $author_count / 1000 );
		for ( $i = 1; $i <= $pages; $i++ ) {
			$indices["authors-sitemap-{$i}.xml"] = null;
		}

		apply_filters( 'alley_sitemap_indices', $indices );

		foreach ( $indices as $index => $modified ) {
			echo '<sitemap><loc>', esc_url( home_url( $index ) ), '</loc>';
			if ( ! empty( $modified ) )
				echo '<lastmod>', esc_html( $modified ), '</lastmod>';
			echo "</sitemap>\n";
		}
	}


	/**
	 * Apply the lastmod dates to the sitemap index entries
	 *
	 * @param array $indices
	 * @return array
	 */
	public function apply_lastmods_to_index( $indices ) {
		# We cache the last modified dates on the files
		if ( false !== ( $cached = get_transient( $this->cache_key ) ) ) {
			$indices = array_merge( $indices, $cached );
		}
		set_transient( $this->cache_key, $indices, 0 );
		return $indices;
	}


	/**
	 * Get post counts by post type
	 *
	 * @return array
	 */
	private function get_post_stats() {
		global $wpdb;
		$post_types = get_post_types( array( 'public' => true ) );
		if ( empty( $post_types ) )
			return array();

		$esses = array_fill( 0, count( $post_types ), '%s' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT `post_type`, COUNT(*) AS `total` FROM {$wpdb->posts} WHERE `post_status` IN ('publish') AND `post_type` IN (" . implode( ',', $esses ) . ') GROUP BY `post_type`', $post_types ) );
	}


	/**
	 * Get term counts by taxonomy
	 *
	 * @return array
	 */
	private function get_taxonomy_stats() {
		global $wpdb;
		$tax_objects = get_taxonomies( array( 'public' => true ), 'objects' );
		$tax_names = array_keys( $tax_objects );
		if ( empty( $tax_names ) )
			return array();

		$esses = array_fill( 0, count( $tax_names ), '%s' );
		$term_counts = $wpdb->get_results( $wpdb->prepare( "SELECT tt.`taxonomy`, COUNT(*) AS `total` FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id WHERE tt.`taxonomy` IN (" . implode( ',', $esses ) . ') GROUP BY tt.`taxonomy`', $tax_names ) );

		# We need to account for the fact that terms might exist over multiple post types
		foreach ( $term_counts as &$row ) {
			$row->post_type_count = count( $tax_objects[ $row->taxonomy ]->object_type );
		}
		return $term_counts;
	}


	/**
	 * Get author counts
	 *
	 * @return int
	 */
	private function get_author_stats() {
		$user_query = new WP_User_Query( array(
			'who' => 'authors',
			'number' => 1
		) );
		return $user_query->get_total();
	}


	/**
	 * Output a sitemap for a given post type
	 *
	 * @param string $post_type
	 * @param int $page
	 * @return void
	 */
	public function do_posts_sitemap( $post_type, $page = 1 ) {
		$args = array(
			'post_type'              => $post_type,
			'posts_per_page'         => 1000,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'ASC',
			'suppress_filters'       => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false
		);

		# If the category is in the permalink structure, save some resources
		$permalink_structure = get_option( 'permalink_structure' );
		if ( 'post' == $post_type && false !== strpos( $permalink_structure, '%category%' ) ) {
			$args['update_post_term_cache'] = true;
		}

		apply_filters( 'alley_sitemap_post_args', $args );
		$posts = get_posts( $args );

		foreach ( $posts as $post ) {
			echo $this->post_sitemap_entry( $post );
		}

		# The last post should be the last modified
		$this->update_transient( "{$post_type}-posts-sitemap-{$page}.xml", mysql2date( 'c', $post->post_modified_gmt ) );
	}


	/**
	 * Output a sitemap for a given taxonomy
	 *
	 * @param string $taxonomy
	 * @param int $page
	 * @return void
	 */
	public function do_terms_sitemap( $taxonomy, $page = 1 ) {
		$tax_object = get_taxonomy( $taxonomy );
		if ( ! empty( $tax_object->object_types ) )
			$per_page = ceil( 1000 / count( $tax_object->object_types ) );
		else
			$per_page = 1000;
		$args = apply_filters( 'alley_sitemap_term_args', array(
			'number'  => $per_page,
			'offset'  => ( $page * $per_page ) - $per_page,
			'orderby' => 'id',
			'order'   => 'ASC',
		), $taxonomy );
		$terms = get_terms( $taxonomy, $args );

		foreach ( $terms as $term ) {
			echo $this->term_sitemap_entry( $term );
		}

		// $this->update_transient( "{$post_type}-posts-sitemap-{$page}.xml", mysql2date( 'c', $post->post_modified_gmt ) );
	}


	/**
	 * Output a sitemap for the site authors
	 *
	 * @param int $page
	 * @return void
	 */
	public function do_authors_sitemap( $page = 1 ) {
		$authors = get_users( apply_filters( 'alley_sitemap_user_args', array(
			'orderby' => 'ID',
			'order'   => 'ASC',
			'who'     => 'authors',
			'number'  => 1000,
			'offset'  => ( $page * 1000 ) - 1000
		) ) );

		foreach ( $authors as $author ) {
			echo $this->author_sitemap_entry( $author );
		}

		// $this->update_transient( "{$post_type}-posts-sitemap-{$page}.xml", mysql2date( 'c', $post->post_modified_gmt ) );
	}


	/**
	 * Update the transient for the lastmod dates for the sitemap index
	 *
	 * @param string $file The XML sitemap file, e.g. category-terms-sitemap-1.xml
	 * @param int $date Unix timestamp
	 * @return void
	 */
	private function update_transient( $file, $date ) {
		# We cache the last modified dates on the files
		if ( false === ( $indices = get_transient( $this->cache_key ) ) ) {
			$indices = array();
		}
		$indices[ $file ] = $date;
		set_transient( $this->cache_key, $indices, 0 );
	}


	/**
	 * Get a single <url/> entry for a sitemap file for a given post
	 *
	 * @param object $post
	 * @return string
	 */
	public function post_sitemap_entry( $post ) {
		$last_modified = mysql2date( 'U', $post->post_modified_gmt );
		$url = apply_filters( 'alley_sitemap_url_post', array(
			'loc'        => get_permalink( $post->ID ),
			'lastmod'    => date( 'c', $last_modified ),
			'changefreq' => $this->changefreq( $last_modified )
		), $post );

		if ( ! empty( $url ) )
			return $this->xmlify( $url, 'url' );
	}


	/**
	 * Get a single <url/> entry for a sitemap file for a given term
	 *
	 * @param object $term
	 * @return string
	 */
	public function term_sitemap_entry( $term ) {
		// $last_modified = mysql2date( 'U', $term->post_modified_gmt );
		$url = apply_filters( 'alley_sitemap_url_term', array(
			'loc' => get_term_link( $term )
		), $term );

		if ( ! empty( $url ) )
			return $this->xmlify( $url, 'url' );
	}


	/**
	 * Get a single <url/> entry for a sitemap for a given user
	 *
	 * @param object $author
	 * @return string
	 */
	public function author_sitemap_entry( $author ) {
		// $last_modified = mysql2date( 'U', $term->post_modified_gmt );
		$url = apply_filters( 'alley_sitemap_url_author', array(
			'loc' => esc_url( get_author_posts_url( $author->ID, $author->user_nicename ) )
		), $author );

		if ( ! empty( $url ) )
			return $this->xmlify( $url, 'url' );
	}


	/**
	 * Calculate a reasonable changefreq given the last mod time
	 *
	 * @param int $modified Unix timestamp
	 * @return string
	 */
	public function changefreq( $modified ) {
		$age = time() - $modified;
		if ( $age > 2 * YEAR_IN_SECONDS ) {
			return 'never';
		} elseif ( $age > 6 * MONTH_IN_SECONDS ) {
			return 'yearly';
		} elseif ( $age > MONTH_IN_SECONDS ) {
			return 'monthly';
		} elseif ( $age > WEEK_IN_SECONDS ) {
			return 'weekly';
		} elseif ( $age > DAY_IN_SECONDS ) {
			return 'daily';
		} else {
			return 'hourly';
		}
	}


	/**
	 * Convert a given array to an XML block
	 *
	 * @param array $a
	 * @param string $wrap The tag to wrap the XML in
	 * @return string
	 */
	public function xmlify( $a, $wrap = null ) {
		$xml = '';
		if ( ! empty( $wrap ) ) {
			$wrap = esc_attr( $wrap );
			$xml .= "<{$wrap}>";
		}

		foreach ( $a as $tag => $text ) {
			$tag = esc_attr( $tag );
			$text = esc_html( $text );
			$xml .= "<{$tag}>{$text}</{$tag}>";
		}

		if ( ! empty( $wrap ) )
			$xml .= "</{$wrap}>\n";
		return $xml;
	}


	/**
	 * Start a sitemap
	 *
	 * @return void
	 */
	public function start_sitemap() {
		echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
	}


	/**
	 * End a sitemap
	 *
	 * @return void
	 */
	public function end_sitemap() {
		echo "</urlset>";
		$this->debug();
	}


	/**
	 * Start a sitemap index
	 *
	 * @return void
	 */
	public function start_index() {
		echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<sitemapindex xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/siteindex.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
	}


	/**
	 * End a sitemap index
	 *
	 * @return void
	 */
	public function end_index() {
		echo "</sitemapindex>";
	}


	/**
	 * Output debugging info
	 *
	 * @return void
	 */
	public function debug() {
		echo "\n<!-- ";
		echo "Memory: " . number_format( memory_get_peak_usage() / 1024 / 1024, 3 ) . "M";
		echo " / Time: " . timer_stop() . "s";
		// global $wpdb;
		// echo "\n", print_r( $wpdb->queries, 1 ), "\n";
		echo " -->";
	}
}

function Alley_Sitemap() {
	return Alley_Sitemap::instance();
}
add_action( 'after_setup_theme', 'Alley_Sitemap' );

endif;