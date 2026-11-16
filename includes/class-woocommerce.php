<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce (docs/WOOCOMMERCE.md): a product in another language is a product of its own (its texts, its
 * address), but it sells the same thing.
 * - A new translation takes the product data (prices, stock, sizes, attributes, downloads, upsells…) and its
 *   variations, each linked to the original variation.
 * - Prices, stock and the other shared data stay the same in every language: saved in one, copied to the others;
 *   a sale in one language lowers the stock of all.
 * - Shop, cart, checkout, account and terms open in the page of the visitor's language; a cart shows its items
 *   in that language.
 * The SKU stays on the original only: WooCommerce wants it unique.
 */
final class WooCommerce {
	/** Product data that is the same thing in every language. */
	const SHARED = array(
		'_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_manage_stock', '_stock',
		'_stock_status', '_backorders', '_low_stock_amount', '_sold_individually', '_weight', '_length', '_width', '_height',
		'_tax_status', '_tax_class', '_virtual', '_downloadable', '_download_limit', '_download_expiry', '_downloadable_files',
		'_purchase_note', '_global_unique_id',
	);
	/** Never copied to a translation. */
	const PRIVATE = array( '_sku', '_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id', 'total_sales', '_wc_average_rating', '_wc_rating_count', '_wc_review_count', '_lingua_bridge_source_id' );
	const PAGES = array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' );

	private static $syncing = false;

	/** C19: the language of an order, saved at checkout (HPOS: order meta, through the order object). */
	const ORDER_META = '_lingua_bridge_language';
	/** The language of the visitor's cart and checkout pages, for an order made through the Store API. */
	const SESSION_KEY = 'lingua_bridge_language';
	/** The order notifications of WooCommerce (WC_Emails, woocommerce_email_actions) that carry an order. */
	const ORDER_EMAIL_ACTIONS = array(
		'woocommerce_order_status_pending_to_processing', 'woocommerce_order_status_pending_to_completed', 'woocommerce_order_status_processing_to_cancelled',
		'woocommerce_order_status_pending_to_cancelled', 'woocommerce_order_status_pending_to_failed', 'woocommerce_order_status_pending_to_on-hold',
		'woocommerce_order_status_failed_to_processing', 'woocommerce_order_status_failed_to_completed', 'woocommerce_order_status_failed_to_on-hold',
		'woocommerce_order_status_cancelled_to_processing', 'woocommerce_order_status_cancelled_to_completed', 'woocommerce_order_status_cancelled_to_on-hold',
		'woocommerce_order_status_on-hold_to_processing', 'woocommerce_order_status_on-hold_to_cancelled', 'woocommerce_order_status_on-hold_to_failed',
		'woocommerce_order_status_completed', 'woocommerce_order_status_failed', 'woocommerce_order_fully_refunded', 'woocommerce_order_partially_refunded',
		'woocommerce_new_customer_note',
	);
	/** @var list<bool> One entry per email being sent in an order's language: whether the locale was switched. */
	private static $email_locales = array();

	public static function init() {
		if ( ! class_exists( '\\WooCommerce' ) ) {
			return;
		}
		add_action( 'lingua_bridge_translation_created', array( __CLASS__, 'translation_created' ), 10, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'sync_variation' ), 20 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'sync_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'sync_stock' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'publish_variations' ), 10, 3 );
		// C20: after the terms of the translation (Terms::map_post_terms at 20), its variations take the translated ones.
		add_action( 'lingua_bridge_translation_created', array( __CLASS__, 'localize_attributes' ), 30, 3 );
		// C19: the language of the order, and its emails in that language whoever sends them.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_language' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'order_language' ) );
		add_action( 'template_redirect', array( __CLASS__, 'remember_language' ) );
		foreach ( self::ORDER_EMAIL_ACTIONS as $action ) {
			add_action( $action . '_notification', array( __CLASS__, 'email_language' ), 1 );
			add_action( $action . '_notification', array( __CLASS__, 'email_restore' ), PHP_INT_MAX );
		}
		add_action( 'woocommerce_before_resend_order_emails', array( __CLASS__, 'email_language' ), 1 );
		add_action( 'woocommerce_after_resend_order_email', array( __CLASS__, 'email_restore' ), PHP_INT_MAX );
		add_filter( 'woocommerce_allow_switching_email_locale', array( __CLASS__, 'allow_wc_locale' ) );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( __CLASS__, 'allow_wc_locale' ) );
		if ( ! is_admin() ) {
			foreach ( self::PAGES as $page ) {
				add_filter( 'woocommerce_get_' . $page . '_page_id', array( __CLASS__, 'page' ) );
			}
			add_filter( 'woocommerce_cart_item_product', array( __CLASS__, 'cart_item' ) );
		}
	}

	/** A product translated: its data and its variations, each variation linked to the original one. */
	public static function translation_created( $target_id, $source_id, $language ) {
		if ( 'product' !== get_post_type( $source_id ) ) {
			return;
		}
		self::copy_meta( $source_id, $target_id );
		foreach ( self::variations( $source_id ) as $variation ) {
			// Private until the translated product is published (publish_variations): nobody buys what is not online.
			$copy = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'product_variation',
						'post_parent'  => absint( $target_id ),
						'post_status'  => 'publish' === get_post_status( $target_id ) ? $variation->post_status : 'private',
						'post_title'   => $variation->post_title,
						'post_excerpt' => $variation->post_excerpt,
						'menu_order'   => $variation->menu_order,
						'post_author'  => get_current_user_id(),
					)
				),
				true
			);
			if ( is_wp_error( $copy ) ) {
				continue;
			}
			self::copy_meta( $variation->ID, $copy );
			if ( 'publish' === $variation->post_status && 'publish' !== get_post_status( $target_id ) ) {
				update_post_meta( $copy, '_lingua_bridge_publish_with_parent', 1 );
			}
			$row = Storage::ensure_source( $variation->ID, Languages::default_code(), '' );
			if ( $row ) {
				Storage::insert( array( 'object_id' => $copy, 'group_uuid' => $row['group_uuid'], 'language' => $language, 'source_id' => $variation->ID, 'source_hash' => '', 'review_state' => 'needs_review' ) );
			}
		}
		self::clear( $target_id );
	}

	/**
	 * C20: the variations of a translated product name the translated terms of the global attributes
	 * (`attribute_pa_*` holds a term's slug, and a translated term has a slug of its own); its default attributes too.
	 * An attribute without a term in that language keeps the original's.
	 */
	public static function localize_attributes( $target_id, $source_id, $language ) {
		if ( 'product' !== get_post_type( $source_id ) || ! class_exists( __NAMESPACE__ . '\\Terms' ) || ! Terms::available() ) {
			return;
		}
		$map = static function ( $taxonomy, $slug ) use ( $language ) {
			$term = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) $slug, $taxonomy ) : null;
			$translated = $term instanceof \WP_Term ? Terms::translation( $term->term_id, $language ) : 0;
			$other = $translated ? get_term( $translated, $taxonomy ) : null;
			return $other instanceof \WP_Term ? $other->slug : (string) $slug;
		};
		foreach ( self::variations( $target_id ) as $variation ) {
			foreach ( get_post_meta( $variation->ID ) as $key => $values ) {
				if ( 0 === strpos( (string) $key, 'attribute_pa_' ) && isset( $values[0] ) && '' !== (string) $values[0] ) {
					update_post_meta( $variation->ID, $key, wp_slash( $map( substr( (string) $key, 10 ), $values[0] ) ) );
				}
			}
		}
		$defaults = get_post_meta( $target_id, '_default_attributes', true );
		if ( is_array( $defaults ) && $defaults ) {
			foreach ( $defaults as $taxonomy => $slug ) {
				$defaults[ $taxonomy ] = 0 === strpos( (string) $taxonomy, 'pa_' ) ? $map( (string) $taxonomy, $slug ) : $slug;
			}
			update_post_meta( $target_id, '_default_attributes', wp_slash( $defaults ) );
		}
		self::clear( $target_id );
	}

	/** C19: the language of the pages the order was made from (checkout, or the cart for the Store API). */
	public static function order_language( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$language = '' !== Routing::request_language() ? Routing::request_language() : '';
		if ( '' === $language && function_exists( 'WC' ) && WC()->session ) {
			$language = (string) WC()->session->get( self::SESSION_KEY, '' );
		}
		$language = isset( Languages::all()[ $language ] ) ? $language : Frontend::current_language();
		$order->update_meta_data( self::ORDER_META, $language );
	}

	/** The cart and checkout pages keep their language in the visitor's WooCommerce session. */
	public static function remember_language() {
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			if ( function_exists( 'WC' ) && WC()->session && WC()->session->get( self::SESSION_KEY ) !== Frontend::current_language() ) {
				WC()->session->set( self::SESSION_KEY, Frontend::current_language() );
			}
		}
	}

	/**
	 * The language of an order: the one saved at checkout, else the customer's profile language when it is a
	 * language of the site, else ''.
	 */
	public static function language_of_order( $order ) {
		$order = is_numeric( $order ) && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order ) ) : $order;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$language = (string) $order->get_meta( self::ORDER_META );
		if ( isset( Languages::all()[ $language ] ) ) {
			return $language;
		}
		$user = method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : 0;
		$locale = $user ? (string) get_user_meta( $user, 'locale', true ) : '';
		foreach ( '' !== $locale ? array_keys( Languages::all() ) : array() as $code ) {
			if ( Locale::for_language( $code ) === $locale ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Before an order's emails: WordPress and WooCommerce in the order's language (its locale), whoever sends them —
	 * the shop from the administration, a scheduled action. The original's language changes nothing: WooCommerce
	 * uses the site's, as always.
	 */
	public static function email_language( $argument = null ) {
		$order = $argument;
		if ( is_array( $argument ) ) {
			$order = $argument['order_id'] ?? 0;
		}
		$language = self::language_of_order( $order );
		$locale = '' !== $language && Languages::default_code() !== $language ? Locale::for_language( $language ) : '';
		if ( '' === $locale || ! function_exists( 'switch_to_locale' ) || ! switch_to_locale( $locale ) ) {
			self::$email_locales[] = false;
			return;
		}
		add_filter( 'plugin_locale', array( __CLASS__, 'email_plugin_locale' ) );
		if ( function_exists( 'WC' ) ) {
			WC()->load_plugin_textdomain();
		}
		self::$email_locales[] = true;
	}

	public static function email_plugin_locale() {
		return determine_locale();
	}

	/** After the emails: the locale as before. */
	public static function email_restore() {
		if ( ! array_pop( self::$email_locales ) ) {
			return;
		}
		restore_previous_locale();
		remove_filter( 'plugin_locale', array( __CLASS__, 'email_plugin_locale' ) );
		if ( function_exists( 'WC' ) ) {
			WC()->load_plugin_textdomain();
		}
	}

	/** WooCommerce switches customer emails to the site's locale: not while they are in an order's language. */
	public static function allow_wc_locale( $allow ) {
		return in_array( true, self::$email_locales, true ) ? false : $allow;
	}

	/**
	 * The shared data comes from the original: saved there, it goes to every translation; saved in a translation,
	 * the translation takes it back from the original. Whoever may edit only a translation never changes the
	 * price or the files of the product everyone buys.
	 */
	public static function sync( $product_id ) {
		if ( self::$syncing ) {
			return;
		}
		$original = self::original( $product_id );
		if ( ! $original ) {
			return;
		}
		self::$syncing = true;
		if ( $original === absint( $product_id ) ) {
			foreach ( self::members( $product_id ) as $member ) {
				self::copy_shared( $product_id, $member );
				self::clear( $member );
			}
		} else {
			// A published translation keeps its stock: sales of it move the stock of all (sync_stock), and
			// WooCommerce saves the product just before telling the new quantity.
			$parent = wp_get_post_parent_id( $product_id );
			$published = 'publish' === get_post_status( $parent ? $parent : $product_id );
			self::copy_shared( $original, $product_id, $published ? array( '_stock', '_stock_status' ) : array() );
			self::clear( $product_id );
		}
		self::$syncing = false;
	}

	/** The original-language product (or variation) of a group, or 0. */
	private static function original( $post_id ) {
		$row = Storage::find( absint( $post_id ) );
		if ( ! $row ) {
			return 0;
		}
		if ( Languages::default_code() === $row['language'] ) {
			return absint( $post_id );
		}
		$source = Storage::find_group_language( $row['group_uuid'], Languages::default_code() );
		return $source ? absint( $source['object_id'] ) : 0;
	}

	public static function sync_variation( $variation_id ) {
		self::sync( $variation_id );
	}

	/**
	 * A sale (or a stock change) of a product people can buy: the same stock in all languages. A change on a
	 * product that is not published (a draft translation) takes the stock back from the original instead.
	 */
	public static function sync_stock( $product ) {
		if ( self::$syncing || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$id = $product->get_id();
		$parent = wp_get_post_parent_id( $id );
		if ( 'publish' !== get_post_status( $parent ? $parent : $id ) ) {
			self::sync( $id );
			return;
		}
		// Whoever edits this translation but may not edit the original (a translator) never moves the shop's
		// stock: the translation takes it back. A sale (the buyer cannot edit products) moves it for all.
		$original = self::original( $id );
		if ( $original && $original !== absint( $id ) && current_user_can( 'edit_post', $id ) && ! current_user_can( 'edit_post', $original ) ) {
			self::$syncing = true;
			self::copy_shared( $original, $id );
			self::clear( $id );
			self::$syncing = false;
			return;
		}
		self::$syncing = true;
		// From the product WooCommerce hands over: its meta may still be cached with the stock before the sale.
		$stock = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$status = method_exists( $product, 'get_stock_status' ) ? $product->get_stock_status() : '';
		foreach ( self::members( $id ) as $member ) {
			if ( null !== $stock ) {
				update_post_meta( $member, '_stock', wc_stock_amount( $stock ) );
			}
			if ( '' !== $status ) {
				update_post_meta( $member, '_stock_status', $status );
			}
			self::clear( $member );
		}
		self::$syncing = false;
	}

	/** A translated product goes online: its variations too (those that were online in the original). */
	public static function publish_variations( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $post->ID, 'post_status' => 'private', 'numberposts' => 200, 'meta_key' => '_lingua_bridge_publish_with_parent', 'fields' => 'ids', 'suppress_filters' => true ) ) as $variation ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_update_post( array( 'ID' => $variation, 'post_status' => 'publish' ) );
			delete_post_meta( $variation, '_lingua_bridge_publish_with_parent' );
		}
		self::clear( $post->ID );
	}

	/** The shop page, the cart… of the visitor's language, when translated and published. */
	public static function page( $page_id ) {
		$language = Strings::language();
		$translation = '' !== $language && $page_id > 0 ? Menus::translation( $page_id, $language ) : 0;
		return $translation ? $translation : $page_id;
	}

	public static function cart_item( $product ) {
		$language = Strings::language();
		if ( '' === $language || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $product;
		}
		$translation = Menus::translation( $product->get_id(), $language );
		$translated = $translation && $translation !== $product->get_id() && function_exists( 'wc_get_product' ) ? wc_get_product( $translation ) : null;
		return $translated ? $translated : $product;
	}

	/** The other products (or variations) of the same group. */
	private static function members( $post_id ) {
		$row = Storage::find( absint( $post_id ) );
		$ids = array();
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$id = absint( $member['object_id'] );
			if ( $id !== absint( $post_id ) && in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private static function variations( $product_id ) {
		return get_posts( array( 'post_type' => 'product_variation', 'post_parent' => absint( $product_id ), 'post_status' => array( 'publish', 'private' ), 'numberposts' => 200, 'orderby' => 'menu_order ID', 'order' => 'ASC', 'suppress_filters' => true ) );
	}

	private static function copy_meta( $from, $to ) {
		foreach ( get_post_meta( $from ) as $key => $values ) {
			// Histeon's per-post markers (_xb_: profile, script, review…) belong to one post: never to its translation.
			if ( in_array( $key, self::PRIVATE, true ) || 0 === strpos( $key, '_lingua_bridge' ) || 0 === strpos( $key, '_xb_' ) ) {
				continue;
			}
			delete_post_meta( $to, $key );
			foreach ( $values as $value ) {
				// add_post_meta() unslashes: slashed here, a backslash in a value survives the copy.
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	private static function copy_shared( $from, $to, $except = array() ) {
		foreach ( array_diff( self::SHARED, $except ) as $key ) {
			if ( metadata_exists( 'post', $from, $key ) ) {
				update_post_meta( $to, $key, wp_slash( get_post_meta( $from, $key, true ) ) );
			} else {
				delete_post_meta( $to, $key );
			}
		}
	}

	private static function clear( $product_id ) {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );
	}
}
