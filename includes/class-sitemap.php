<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** `wp-sitemap-lingua-1.xml`: the list pages of each language (Archives::language_lists()). */
final class Sitemap extends \WP_Sitemaps_Provider {
	const NAME = 'lingua';

	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = self::NAME;
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( 1 !== (int) $page_num ) {
			return array();
		}
		$list = array();
		foreach ( Archives::language_lists() as $url ) {
			$list[] = array( 'loc' => $url );
			Archives::remember_list( $url );
		}
		return $list;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return empty( Archives::language_lists() ) ? 0 : 1;
	}
}
