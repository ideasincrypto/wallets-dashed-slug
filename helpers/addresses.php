<?php

/**
 * Helper functions that retrieve addresses.
 *
 * @since 6.0.0 Introduced.
 * @author Alexandros Georgiou <info@dashed-slug.net>
 */

namespace DSWallets;


defined( 'ABSPATH' ) || die( -1 );

/**
 * Retrieves a depost address by its address string.
 *
 * Useful for performing deposits. If the extra field is specified,
 * it must also match.
 *
 * @param string $address The address string.
 * @param ?string $extra Optionally a Payment ID, Memo, Destination tag, etc.
 *
 * return Address|null The address found, or null if no address was found.
 */
function get_deposit_address_by_strings( string $address, string $extra = null ): ?Address {
	return get_address_by_strings( $address, $extra, 'deposit' );
}

/**
 * Retrieves a withdrawal address by its address string.
 *
 * Useful for editing withdrawals. If the extra field is specified,
 * it must also match.
 *
 * @param string $address The address string.
 * @param ?string $extra Optionally a Payment ID, Memo, Destination tag, etc.
 *
 * return Address|null The address found, or null if no address was found.
 */
function get_withdrawal_address_by_strings( string $address, string $extra = null ): ?Address {
	return get_address_by_strings( $address, $extra, 'withdrawal' );
}

/**
 * Retrieves a depost address by its address string. This can be a deposit or withdrawal address.
 *
 * Useful for performing deposits. If the extra field is specified,
 * it must also match.
 *
 * @param string $address The address string.
 * @param ?string $extra Optionally a Payment ID, Memo, Destination tag, etc. If not applicable, should be falsy.
 * @param string $type One of deposit, withdrawal.
 *
 * return Address|null The address found, or null if no address was found.
 */
function get_address_by_strings( string $address, string $extra = null, string $type = '' ): ?Address {
	if ( 'withdrawal' != $type && 'deposit' != $type ) {
		throw new \InvalidArgumentException( 'Type can only be "deposit" or "withdrawal".' );
	}

	maybe_switch_blog();

	$query_args = [
		'fields'      => 'ids',
		'post_type'   => 'wallets_address',
		'post_status' => 'publish',
		'meta_query'  => [
			'relation' => 'AND',
			[
				'key'   => 'wallets_address',
				'value' => $address,
			],
			[
				'key'   => 'wallets_type',
				'value' => $type,
			]
		]
	];

	if ( $extra ) {
		$query_args['meta_query'][] = [
			'key'   => 'wallets_extra',
			'value' => $extra,
		];
	}

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	if ( ! $post_ids ) {
		return null;
	}

	$address = null;
	try {
		$address = Address::load( $post_ids[ 0 ] );

	} catch ( \Exception $e ) {
		error_log(
			sprintf(
				'%s: Could not load address with post_id: %d',
				__FUNCTION__,
				$post_ids[ 0 ]
			)
		);
	}

	maybe_restore_blog();

	return $address;
}

function get_all_addresses_for_user_id( int $user_id, int $page = null, int $rows = null ): array {
	maybe_switch_blog();

	$query_args = [
		'fields'      => 'ids',
		'post_type'   => 'wallets_address',
		'post_status' => 'publish',
		'meta_query'  => [
			'relation' => 'AND',
			[
				'key'   => 'wallets_user',
				'value' => $user_id,
				'type'  => 'numeric',
			],
			[
				'key'     => 'wallets_currency_id',
				'compare' => 'EXISTS',
			]
		]
	];

	if ( $page && $rows ) {
		$query_args['nopaging']       = false;
		$query_args['posts_per_page'] = max( 1, absint( $rows ) );
		$query_args['paged']          = max( 1, absint( $page ) );

	} else {
		$query_args['nopaging'] = true;
	}

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	$addresses = array_map(
		function( $post_id ) {
			try {

				return Address::load( $post_id );

			} catch ( \Exception $e ) {
				error_log(
					sprintf(
						'get_all_addresses_for_user_id: Could not instantiate address %d due to: %s',
						$post_id,
						$e->getMessage()
					)
				);
			}
			return null;
		},
		$post_ids
	);
	maybe_restore_blog();

	return array_values(
		array_filter(
			$addresses,
			function( $address ) {
				return $address && $address->currency;

			}
		)
	);
}

function count_all_addresses_for_user_id( int $user_id ): int {
	return count( get_all_addresses_for_user_id( $user_id ) );
}


function user_and_currency_have_label( int $user_id, int $currency_id, string $label ): bool {

	maybe_switch_blog();

	$query_args = [
		'fields'      => 'ids',
		'title'       => $label,
		'post_type'   => 'wallets_address',
		'post_status' => 'publish',
		'nopaging'    => true,
		'meta_query'  => [
			'relation' => 'AND',
			[
				'key'   => 'wallets_user',
				'value' => $user_id,
				'type'  => 'numeric',
			],
			[
				'key'   => 'wallets_currency_id',
				'value' => $currency_id,
			],
			[
				'key'   => 'wallets_type',
				'value' => 'deposit',
			]
		]
	];

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	maybe_restore_blog();

	return ! empty( $post_ids );
}

/**
 * Check an address object to see if it already exists on the db as a post and if not, create it.
 *
 * The post_id and label are not compared. Everything else is. Trashed addresses are not counted.
 *
 * @param Address $address An address to look for.
 * @return Address The existing address or newly created address.
 * @throws \Exception If saving the address fails.
 */
function get_or_make_address( Address $address ): Address {

	maybe_switch_blog();

	$query_args = [
		'fields'      => 'ids',
		'ID'          => $address->post_id ?? null,
		'post_type'   => 'wallets_address',
		'post_status' => ['publish', 'draft', 'pending' ], // not trash
		'nopaging'    => true,
		'meta_query'  => [
			'relation' => 'AND',
			[
				'key'   => 'wallets_address',
				'value' => $address->address,
			],
			[
				'key'   => 'wallets_currency_id',
				'value' => $address->currency->post_id,
			],
		]
	];

	if ( $address->user ) {
		$query_args['meta_query'][] = [
			'key'   => 'wallets_user',
			'value' => $address->user->ID,
			'type'  => 'numeric',
		];
	}

	if ( $address->type ) {
		$query_args['meta_query'][] = [
			'key'   => 'wallets_type',
			'value' => $address->type,
		];
	}

	if ( $address->extra ) {
		$query_args['meta_query'][] = [
			'key'   => 'wallets_extra',
			'value' => $address->extra,
		];
	}

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	if ( $post_ids ) {

		$found = Address::load( $post_ids[ 0 ] );

		if (
			$address->address           == $found->address &&
			$address->extra             == $found->extra &&
			$address->type              == $found->type &&
			$address->currency->post_id == $found->currency->post_id
		) {
			return $found;
		}
	}

	$address->save();

	maybe_restore_blog();

	if ( $address->post_id ) {
		return $address;
	} else {
		throw new \Exception( 'No post_id for address' );
	}
}

/**
 * Retrieves the latest saved address for the specified currency and user, if any.
 *
 * @param int $user_id The user id.
 * @param Currency $currency The currency.
 * @param string $type One of 'deposit', 'withdrawal'.
 * @return Address|NULL The address found, or null.
 */
function get_latest_address_for_user_id_and_currency( int $user_id, Currency $currency, string $type = 'deposit' ): ?Address {
	maybe_switch_blog();

	$query_args = [
		'fields'         => 'ids',
		'post_type'      => 'wallets_address',
		'post_status'    => 'publish',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'posts_per_page' => 1,

		'meta_query'  => [
			[
				'key'     => 'wallets_currency_id',
				'value'   => $currency->post_id,
			],
			[
				'key'     => 'wallets_type',
				'value'   => $type,
			],
			[
				'key'   => 'wallets_user',
				'value' => $user_id,
				'type'  => 'numeric',
			],
		]
	];

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	$address = null;

	if ( $post_ids ) {
		try {
			$address = Address::load( $post_ids[ 0 ] );
		} catch ( \Exception $e ) {
			error_log(
				sprintf(
					'get_latest_address_for_user_id_and_currency: Could not instantiate address %d due to: %s',
					$post_ids[ 0 ],
					$e->getMessage()
				)
			);
		}
	}

	maybe_restore_blog();

	return $address;
}

function get_latest_address_per_currency_for_user_id( int $user_id, string $type = 'deposit' ): array {
	maybe_switch_blog();

	$query_args = [
		'fields'         => 'ids',
		'post_type'      => 'wallets_address',
		'post_status'    => 'publish',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'nopaging'       => true,

		'meta_query'  => [
			'relation' => 'AND',
			[
				'key'     => 'wallets_type',
				'value'   => $type,
			],
			[
				'key'   => 'wallets_user',
				'value' => $user_id,
				'type'  => 'numeric',
			],
		]
	];

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	$addresses = [];

	foreach ( $post_ids as $post_id ) {
		try {
			$address = Address::load( $post_id );
		} catch ( \Exception $e ) {
			error_log(
				sprintf(
					'%s: Could not instantiate address %d due to: %s',
					__FUNCTION__,
					$post_id,
					$e->getMessage()
				)
			);
		}

		if ( ! isset( $addresses[ $address->currency->post_id ] ) ) {
			$addresses[ $address->currency->post_id ] = $address;
		} else {
			if ( get_post_time( 'U', true, $address->post_id ) > get_post_time( 'U', true, $addresses[ $address->currency->post_id ]->post_id ) ) {
				$addresses[ $address->currency->post_id ] = $address;
			}
		}
	}

	maybe_restore_blog();

	return array_values( $addresses );
}


/**
 * Retrieves all the addresses for the specified currency and user, and possibly type.
 *
 * @param int $user_id The user id.
 * @param int $currency_id The currency_id.
 * @param string $type One of 'deposit', 'withdrawal'.
 * @return Address[] The addresses found.
 */
function get_all_addresses_for_user_id_and_currency_id( int $user_id, int $currency_id, string $type = 'deposit' ): array {
	maybe_switch_blog();

	$query_args = [
		'fields'         => 'ids',
		'post_type'      => 'wallets_address',
		'post_status'    => 'publish',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'nopaging'       => true,

		'meta_query'  => [
			'relation' => 'AND',
			[
				'key'   => 'wallets_user',
				'value' => $user_id,
				'type'  => 'numeric',
			],
			[
				'key'     => 'wallets_currency_id',
				'value'   => $currency_id,
			],
			[
				'key'     => 'wallets_type',
				'value'   => $type,
			]
		]
	];

	$query = new \WP_Query( $query_args );

	$post_ids = array_values( $query->posts );

	$addresses = array_map(
		function( int $post_id ): ?Address {
			try {

				return Address::load( $post_id );

			} catch ( \Exception $e ) {
				error_log(
					sprintf(
						'get_all_addresses_for_user_id_and_currency_id: Could not instantiate address %d due to: %s',
						$post_id,
						$e->getMessage()
					)
				);
			}
			return null;
		},
		$post_ids
	);

	maybe_restore_blog();

	return array_filter( $addresses );
}


/**
 * Get all address IDs for a currency ID.
 *
 * Retrieves all the ids of the deposit (or withdrawal) addresses for the specified currency and type.
 *
 * @param int $currency_id The ID of the currency whose addresses to retrieve..
 * @param string $type Type of addresses to retrieve. One of 'deposit', 'withdrawal'. Default is deposit addresses.
 * @return int[] The IDs of the addresses found.
 */
function get_address_ids_for_currency_id( int $currency_id, string $type = 'deposit' ): array {
	maybe_switch_blog();

	$query_args = [
		'fields'         => 'ids',
		'post_type'      => 'wallets_address',
		'post_status'    => 'publish',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'nopaging'       => true,

		'meta_query'  => [
			[
				'key'     => 'wallets_currency_id',
				'value'   => $currency_id,
			],
			[
				'key'     => 'wallets_type',
				'value'   => $type,
			]
		]
	];

	$query = new \WP_Query( $query_args );


	maybe_restore_blog();

	return $query->posts;
}


/**
 * Get all the IDs for addresses of a specified wallet and having the specified tags.
 *
 * Retrieves all the ids of the deposit (or withdrawal) addresses for the specified wallet and tags.
 *
 * @param int $currency_id The ID of the currency whose addresses to retrieve.
 * @param string $type Type of addresses to retrieve. One of 'deposit', 'withdrawal'. Default is deposit addresses.
 * @param string[] $tags The addresses found must have all the specified tags.
 * @return int[] The IDs of the addresses found.
 */
function get_address_ids_by_currency_id_and_type_and_tags( int $currency_id, string $type = 'deposit', array $tags = [] ): array {
	maybe_switch_blog();

	$query_args = [
		'fields'         => 'ids',
		'post_type'      => 'wallets_address',
		'post_status'    => 'publish',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'nopaging'       => true,

		'meta_query' => [
			'relation' => 'AND',
			[
				'key'     => 'wallets_currency_id',
				'value'   => $currency_id,
			],
			[
				'key'     => 'wallets_type',
				'value'   => $type,
			]
		],
	];

	if ( $tags ) {
		$query_args['tax_query'] = [
			'relation' => 'AND',
		];

		foreach ( $tags as $tag ) {
			$query_args['tax_query'][] = [
				'taxonomy' => 'wallets_address_tags',
				'field'    => 'slug',
				'terms'    => $tag,
			];
		}
	}

	$query = new \WP_Query( $query_args );

	maybe_restore_blog();

	return $query->posts;
}


/**
 * Get IDs of deposit addresses by tags.
 *
 * Get the IDs of deposit addresses with the specified tags.
 *
 * @param array $tags The addresses found must have all these tags.
 * @return array The IDs of the matching addresses.
 */
function get_deposit_address_ids_by_tags( array $tags = [] ): array {
	maybe_switch_blog();

	$query_args = [
		'fields'         => 'ids',
		'post_type'      => 'wallets_address',
		'post_status'    => 'publish',
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'nopaging'       => true,

		'meta_query' => [
			[
				'key'     => 'wallets_type',
				'value'   => 'deposit',
			]
		],
	];

	if ( $tags ) {
		$query_args['tax_query'] = [
			'relation' => 'AND',
		];

		foreach ( $tags as $tag ) {
			$query_args['tax_query'][] = [
				'taxonomy' => 'wallets_address_tags',
				'field'    => 'slug',
				'terms'    => $tag,
			];
		}
	}

	$query = new \WP_Query( $query_args );

	maybe_restore_blog();

	return $query->posts;
}
