<?php
/**
 * Segmentation — decides which recovery sequence an abandoned cart enters, by
 * cart value, products/categories in the cart, and country. This is what lets
 * one store run several targeted flows ("high-value carts", "EU shoppers", …)
 * instead of a single blast.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Segment matching.
 */
class CRW_Segments {

	/**
	 * Whether a cart row matches a segment's conditions. An empty segment
	 * matches everything (the catch-all "Default" flow).
	 *
	 * @param array $row     Cart row.
	 * @param array $segment Conditions: min_total, max_total (dollars),
	 *                       products (ids), categories (slugs/ids), countries.
	 */
	public static function matches( array $row, array $segment ) {
		$total = (int) ( $row['total_cents'] ?? 0 );

		if ( isset( $segment['min_total'] ) && '' !== $segment['min_total'] && $total < (int) round( (float) $segment['min_total'] * 100 ) ) {
			return false;
		}
		if ( isset( $segment['max_total'] ) && '' !== $segment['max_total'] && $total > (int) round( (float) $segment['max_total'] * 100 ) ) {
			return false;
		}

		$countries = self::list( $segment['countries'] ?? '' );
		if ( ! empty( $countries ) ) {
			$region = strtoupper( (string) ( $row['region'] ?? '' ) );
			if ( '' === $region || ! in_array( $region, $countries, true ) ) {
				return false;
			}
		}

		$want_products = array_map( 'intval', self::list( $segment['products'] ?? '' ) );
		$want_cats     = self::list( $segment['categories'] ?? '' );
		if ( ! empty( $want_products ) || ! empty( $want_cats ) ) {
			$product_ids = array();
			foreach ( CRW_Carts_Store::items( $row ) as $item ) {
				$product_ids[] = (int) ( $item['product_id'] ?? 0 );
			}
			$product_ids = array_filter( array_unique( $product_ids ) );

			if ( ! empty( $want_products ) && ! array_intersect( $want_products, $product_ids ) ) {
				return false;
			}
			if ( ! empty( $want_cats ) && ! self::has_category( $product_ids, $want_cats ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The id of the first enabled sequence whose segment matches, or '' if none.
	 *
	 * @param array $row Cart row.
	 */
	public static function first_matching( array $row ) {
		foreach ( CRW_Options::sequences() as $seq ) {
			if ( empty( $seq['enabled'] ) ) {
				continue;
			}
			// Skip an enabled-but-stepless sequence: it has nothing to send, so
			// treat it as a non-match and let the cart fall through to a later
			// matching sequence that actually has steps (rather than dead-ending
			// the cart as 'lost').
			if ( empty( $seq['steps'] ) || ! is_array( $seq['steps'] ) ) {
				continue;
			}
			if ( self::matches( $row, (array) ( $seq['segment'] ?? array() ) ) ) {
				return (string) $seq['id'];
			}
		}
		return '';
	}

	/**
	 * Do any of the given products belong to any of the wanted categories?
	 *
	 * @param int[] $product_ids Product ids.
	 * @param array $want_cats   Category slugs or ids.
	 */
	protected static function has_category( array $product_ids, array $want_cats ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$want = array_map( 'strval', $want_cats );
		foreach ( $product_ids as $pid ) {
			$terms = get_the_terms( $pid, 'product_cat' );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $t ) {
				if ( in_array( (string) $t->term_id, $want, true ) || in_array( $t->slug, $want, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Parse a comma/space/newline list into trimmed values.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function list( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', $raw ) ) );
		}
		$parts = preg_split( '/[\s,]+/', (string) $raw );
		return array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
	}
}
