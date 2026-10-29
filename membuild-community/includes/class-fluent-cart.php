<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Memberships sold with FluentCart, when it's active.
 *
 * A membership can be linked to one FluentCart product. Its "Get access"
 * button then opens that product's checkout; a paid order adds the buyer to
 * the membership, and a refund or a cancelled subscription takes them out
 * again (only people the purchase added, never someone added by hand).
 *
 * The checkout and receipt pages for a linked product use the community's
 * own simple page (templates/checkout.php) instead of the theme.
 */
class Fluent_Cart {

	const LINKS  = 'mbcom_fluentcart'; // option: { membership id: FluentCart variation id }
	const BOUGHT = 'mbcom_bought';     // user meta: { membership id: order id } that a purchase added

	public static function active() {
		return class_exists( '\FluentCart\App\Models\ProductVariation' ) && class_exists( '\FluentCart\App\Models\Order' );
	}

	public static function register_hooks() {
		if ( ! self::active() ) {
			return;
		}
		add_action( 'fluent_cart/order_paid', array( __CLASS__, 'on_paid' ) );
		add_action( 'fluent_cart/order_paid_done', array( __CLASS__, 'on_paid' ) ); // the same, later; adding twice is harmless
		add_action( 'fluent_cart/payment_status_changed_to_refunded', array( __CLASS__, 'on_refunded' ) );
		add_action( 'fluent_cart/payments/subscription_status_changed', array( __CLASS__, 'on_subscription' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( self::END_HOOK, array( __CLASS__, 'on_paid_period_end' ), 10, 2 );
		add_action(
			'rest_api_init',
			function () {
				$member = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
				register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/me/billing', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_billing' ), 'permission_callback' => $member ) );
				register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/me/billing/(?P<uuid>[A-Za-z0-9]+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_subscription' ), 'permission_callback' => $member ) );
				register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/me/billing/(?P<uuid>[A-Za-z0-9]+)/receipt/(?P<trx>[A-Za-z0-9]+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_receipt' ), 'permission_callback' => $member ) );
				register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/me/billing/(?P<uuid>[A-Za-z0-9]+)/(?P<do>cancel|pause|resume)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_subscription_do' ), 'permission_callback' => $member ) );
			}
		);
	}

	const END_HOOK = 'mbcom_fluentcart_paid_period_end'; // a cancelled subscription's access ends when what they paid for runs out

	/* ---------- Links ---------- */

	/** @return array<int,int> membership id => variation id */
	public static function links() {
		$links = get_option( self::LINKS, array() );
		return is_array( $links ) ? array_map( 'intval', $links ) : array();
	}

	public static function link_of( $group_id ) {
		return self::links()[ (int) $group_id ] ?? 0;
	}

	/** @return int[] the memberships a FluentCart variation opens */
	public static function groups_for( $variation_id ) {
		return array_keys( array_filter( self::links(), fn( $v ) => (int) $v === (int) $variation_id ) );
	}

	/** Links a membership to a variation (0 unlinks), and points its button at the checkout. */
	public static function set_link( $group_id, $variation_id ) {
		global $wpdb;
		$links = self::links();
		$item  = $variation_id ? self::product( $variation_id ) : null;
		if ( ! $item ) {
			unset( $links[ (int) $group_id ] );
			update_option( self::LINKS, $links, false );
			return null;
		}
		$links[ (int) $group_id ] = (int) $item['id'];
		update_option( self::LINKS, $links, false );
		// The button opens this product's checkout, at its price.
		$wpdb->update(
			Install::table( 'groups' ),
			array(
				'cta_url' => $item['checkout'],
				'price'   => $item['price_text'],
				'period'  => $item['period'],
			),
			array( 'id' => (int) $group_id )
		);
		return $item;
	}

	/**
	 * Whether FluentCart's checkout still asks for a billing address. A membership needs none,
	 * and the community's checkout hides that section, so a required field there would stop the
	 * order: Settings › Memberships shows a tip with a link to switch it off.
	 */
	public static function asks_address() {
		if ( ! class_exists( '\FluentCart\App\Services\Renderer\CheckoutFieldsSchema' ) ) {
			return false;
		}
		foreach ( (array) ( \FluentCart\App\Services\Renderer\CheckoutFieldsSchema::getFieldsSettings()['billing_address'] ?? array() ) as $field ) {
			if ( 'yes' === ( $field['enabled'] ?? 'no' ) && 'yes' === ( $field['required'] ?? 'no' ) ) {
				return true;
			}
		}
		return false;
	}

	/** FluentCart's own square logo, from its plugin folder (never copied into ours); '' if it moved. */
	public static function logo() {
		$file = defined( 'FLUENTCART_PLUGIN_PATH' ) ? FLUENTCART_PLUGIN_PATH . 'assets/images/logo/logo.svg' : '';
		return $file && is_readable( $file ) && defined( 'FLUENTCART_URL' ) ? FLUENTCART_URL . 'assets/images/logo/logo.svg' : '';
	}

	/** FluentCart's own Checkout fields settings page. */
	public static function fields_url() {
		return admin_url( 'admin.php?page=fluent-cart#/settings/checkout_fields' );
	}

	/** Published FluentCart product variations: every one for the picker (up to 500), or the given ones. */
	public static function products( array $ids = array() ) {
		global $wpdb;
		$only = $ids ? ' AND v.id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $only is a list of ints.
				"SELECT v.id, v.variation_title, v.item_price, v.payment_type, v.other_info, p.post_title FROM %i v JOIN %i p ON p.ID = v.post_id WHERE p.post_type = 'fluent-products' AND p.post_status = 'publish' AND v.item_status = 'active'{$only} ORDER BY p.post_title ASC, v.serial_index ASC LIMIT 500",
				$wpdb->prefix . 'fct_product_variations',
				$wpdb->posts
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_product' ), $rows );
	}

	/** One variation, looked up by itself (so a big store's 501st product still works). */
	public static function product( $variation_id ) {
		return $variation_id ? ( self::products( array( (int) $variation_id ) )[0] ?? null ) : null;
	}

	private static function format_product( array $r ) {
		$info     = json_decode( (string) $r['other_info'], true ) ?: array();
		$interval = 'subscription' === $r['payment_type'] ? ( $info['repeat_interval'] ?? 'monthly' ) : '';
		$period   = array( 'monthly' => 'month', 'yearly' => 'year' )[ $interval ] ?? 'once';
		$amount   = self::money( (int) $r['item_price'] );
		$title    = $r['variation_title'] && $r['variation_title'] !== $r['post_title'] ? $r['post_title'] . ' – ' . $r['variation_title'] : $r['post_title'];
		$label    = array(
			'month' => sprintf( /* translators: %s: a price */ __( '%s a month', 'membuild-community' ), $amount ),
			'year'  => sprintf( /* translators: %s: a price */ __( '%s a year', 'membuild-community' ), $amount ),
			'once'  => $amount,
		)[ $period ];
		return array(
			'id'         => (int) $r['id'],
			'title'      => $title,
			'label'      => $title . ' · ' . $label,
			'amount'     => $amount,
			'price_text' => preg_replace( '/\.00$/', '', $amount ), // "$49" on the button
			'period'     => $period,
			'checkout'   => site_url( '?fluent-cart=instant_checkout&item_id=' . (int) $r['id'] . '&quantity=1' ),
		);
	}

	/**
	 * The button of a membership sold with FluentCart, from the product as it is now
	 * (a price changed in FluentCart shows at once). Null when FluentCart is off or the
	 * product is gone: the membership reads as invite only rather than a broken link.
	 */
	public static function live_offer( $group_id, $label ) {
		if ( ! self::active() ) {
			return null;
		}
		$item = self::product( self::link_of( $group_id ) );
		return $item ? array( 'label' => $label, 'url' => $item['checkout'], 'price' => $item['price_text'], 'period' => $item['period'] ) : null;
	}

	/** "$49.00" in the store's currency, from cents. */
	public static function money( $cents ) {
		if ( method_exists( '\FluentCart\App\Helpers\Helper', 'toDecimal' ) ) {
			return html_entity_decode( wp_strip_all_tags( (string) \FluentCart\App\Helpers\Helper::toDecimal( $cents ) ), ENT_QUOTES, 'UTF-8' );
		}
		return '$' . number_format( $cents / 100, 2 );
	}

	/* ---------- Paid, refunded, cancelled ---------- */

	public static function on_paid( $data ) {
		$order = $data['order'] ?? null;
		if ( ! $order ) {
			return;
		}
		$user_id = self::buyer( $order, $data['customer'] ?? null );
		if ( ! $user_id ) {
			return;
		}
		foreach ( self::order_groups( $order ) as $group_id ) {
			$was = in_array( $group_id, Access::manual_groups( $user_id ), true );
			if ( Access::add( $group_id, $user_id ) || ! $was ) {
				$bought              = (array) get_user_meta( $user_id, self::BOUGHT, true );
				$bought[ $group_id ] = (int) $order->id;
				update_user_meta( $user_id, self::BOUGHT, $bought );
			}
		}
	}

	public static function on_refunded( $data ) {
		$order = $data['order'] ?? null;
		if ( $order ) {
			self::take_back( $order, self::order_groups( $order ) );
		}
	}

	public static function on_subscription( $data ) {
		$sub   = $data['subscription'] ?? null;
		$order = $data['order'] ?? null;
		if ( ! $sub || ! $order ) {
			return;
		}
		$groups = self::groups_for( (int) $sub->variation_id );
		$status = $data['new_status'] ?? '';
		$args   = array( (int) $order->id, (int) $sub->variation_id );
		if ( in_array( $status, array( 'canceled', 'cancelled' ), true ) ) {
			// They keep what they paid for: access ends at the end of the paid period, not today.
			$until = ! empty( $sub->next_billing_date ) ? strtotime( $sub->next_billing_date . ' UTC' ) : 0;
			if ( $until > time() ) {
				wp_clear_scheduled_hook( self::END_HOOK, $args );
				wp_schedule_single_event( $until, self::END_HOOK, $args );
			} else {
				self::take_back( $order, $groups );
			}
		} elseif ( in_array( $status, array( 'expired', 'paused' ), true ) ) {
			self::take_back( $order, $groups ); // paused: no payments, so no access until it's resumed
		} elseif ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			wp_clear_scheduled_hook( self::END_HOOK, $args ); // restarted before it ran out
			self::on_paid( array( 'order' => $order, 'customer' => $data['customer'] ?? null ) );
		}
		// Past due and failing keep access: the member is asked to update their card.
	}

	/** A cancelled subscription's paid period is over: its memberships go (unless it was restarted). */
	public static function on_paid_period_end( $order_id, $variation_id ) {
		$sub = \FluentCart\App\Models\Subscription::query()->where( 'parent_order_id', (int) $order_id )->first();
		if ( $sub && in_array( $sub->status, array( 'active', 'trialing', 'past_due', 'failing' ), true ) ) {
			return;
		}
		$order = \FluentCart\App\Models\Order::query()->find( (int) $order_id );
		if ( $order ) {
			self::take_back( $order, self::groups_for( (int) $variation_id ) );
		}
	}

	/* ---------- Billing (the member's own page) ---------- */

	/** The member's subscriptions to memberships, newest first, for the Billing page. */
	public static function billing( $user_id ) {
		global $wpdb;
		$customers = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d', $wpdb->prefix . 'fct_customers', $user_id ) );
		if ( ! $customers ) {
			return array();
		}
		$subs   = \FluentCart\App\Models\Subscription::query()->whereIn( 'customer_id', array_map( 'intval', $customers ) )->orderBy( 'id', 'DESC' )->limit( 50 )->get();
		$groups = Access::groups();
		$out    = array();
		foreach ( $subs as $sub ) {
			$ids = self::groups_for( (int) $sub->variation_id );
			if ( ! $ids || in_array( $sub->status, array( 'pending', 'intended', 'created', 'expired', 'completed' ), true ) ) {
				continue; // not a membership, never started, or over (its receipts stay under Receipts)
			}
			$group = $groups[ $ids[0] ] ?? null;
			if ( ! $group ) {
				continue;
			}
			$interval = array( 'monthly' => 'month', 'yearly' => 'year', 'weekly' => 'week', 'daily' => 'day' )[ $sub->billing_interval ] ?? 'month';
			$state    = array(
				'active'   => 'active',
				'trialing' => 'active',
				'past_due' => 'failed',
				'failing'  => 'failed',
				'paused'   => 'paused',
				'canceled' => 'cancelled',
				'expiring' => 'cancelled',
			)[ $sub->status ] ?? 'ended';
			$card = '';
			try {
				$card = (string) $sub->getPaymentMethodText();
			} catch ( \Throwable $e ) {
				$card = '';
			}
			$card = preg_replace( '/^(\w+) \*{3}(\d{4})$/', '$1 ending $2', html_entity_decode( wp_strip_all_tags( $card ), ENT_QUOTES, 'UTF-8' ) );
			$product = self::product( (int) $sub->variation_id );
			$out[]   = array(
				'id'       => (string) $sub->uuid,
				'name'     => $group['name'],
				'courses'  => array_map( fn( $c ) => preg_replace( '/: .*$/', '', $c['text'] ), self::perks( $group['id'] ) ),
				'amount'   => self::money( (int) $sub->recurring_total ),
				'interval' => $interval,
				'state'    => $state,
				'renews'   => 'active' === $state && $sub->next_billing_date ? Helpers::iso( $sub->next_billing_date ) : null,
				'until'    => 'cancelled' === $state && $sub->next_billing_date ? Helpers::iso( $sub->next_billing_date ) : null,
				'pay_by'   => 'failed' === $state && $sub->next_billing_date ? Helpers::iso( $sub->next_billing_date ) : null,
				'card'     => $card,
				'manage'   => \FluentCart\App\Services\URL::getCustomerDashboardUrl( 'subscription/' . $sub->uuid ),
				'restart'  => in_array( $state, array( 'cancelled', 'ended' ), true ) && $product ? $product['checkout'] : null,
			);
		}
		return $out;
	}

	public static function rest_billing() {
		if ( ! self::active() ) {
			return array( 'items' => array(), 'receipts' => null );
		}
		return array(
			'items'    => self::billing( get_current_user_id() ),
			'receipts' => \FluentCart\App\Services\URL::getCustomerDashboardUrl( 'purchase-history' ),
		);
	}

	/**
	 * One subscription for Billing › membership. FluentCart's own customer API answers
	 * (as the signed-in member, with its own ownership checks): what can be done, and
	 * every payment with its receipt. Only subscriptions to memberships are shown here.
	 */
	public static function rest_subscription( \WP_REST_Request $req ) {
		$item = self::billing_item( (string) $req['uuid'] );
		return $item ?: new \WP_Error( 'mbcom_not_found', __( 'That membership wasn’t found.', 'membuild-community' ), array( 'status' => 404 ) );
	}

	/** Cancel, pause or resume, through FluentCart's own customer actions (they tell the payment provider). */
	public static function rest_subscription_do( \WP_REST_Request $req ) {
		$uuid = (string) $req['uuid'];
		if ( ! self::billing_item( $uuid ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'That membership wasn’t found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$path = array( 'cancel' => 'cancel-auto-renew', 'pause' => 'pause', 'resume' => 'resume' )[ $req['do'] ];
		$res  = rest_do_request( new \WP_REST_Request( 'POST', '/fluent-cart/v2/customer-profile/subscriptions/' . $uuid . '/' . $path ) );
		if ( $res->is_error() || $res->get_status() >= 400 ) {
			$data = $res->get_data();
			$msg  = is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '';
			return new \WP_Error( 'mbcom_billing', $msg ?: __( 'That didn’t work just now. Please try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return self::billing_item( $uuid );
	}

	/**
	 * One payment's receipt, for the box on Billing › membership (no trip to another page).
	 * Only a payment listed on the member's own subscription can be opened.
	 */
	public static function rest_receipt( \WP_REST_Request $req ) {
		$item = self::billing_item( (string) $req['uuid'] );
		$trx  = (string) $req['trx'];
		if ( ! $item || ! in_array( $trx, array_column( $item['payments'], 'id' ), true ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'That receipt wasn’t found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$t     = \FluentCart\App\Models\OrderTransaction::query()->where( 'uuid', $trx )->first();
		$order = $t ? \FluentCart\App\Models\Order::query()->find( $t->order_id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'mbcom_not_found', __( 'That receipt wasn’t found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$user  = wp_get_current_user();
		$card  = $t->card_brand && $t->card_last_4 ? sprintf( /* translators: 1: card brand, 2: last four digits */ __( '%1$s ending %2$s', 'membuild-community' ), ucfirst( $t->card_brand ), $t->card_last_4 ) : html_entity_decode( (string) $order->payment_method_title, ENT_QUOTES, 'UTF-8' );
		$lines = array();
		foreach ( $order->order_items()->get() as $line ) {
			$lines[] = array(
				'title'  => (string) ( $line->post_title ?: $line->title ),
				'detail' => $item['name'] === $line->post_title ? '' : (string) $line->title,
				'amount' => self::money( (int) $line->line_total ),
			);
		}
		$discount = (int) $order->coupon_discount_total + (int) $order->manual_discount_total;
		return array(
			'number'   => (string) ( $order->invoice_no ?: $order->receipt_number ?: $order->id ),
			'date'     => Helpers::iso( $t->created_at ),
			'state'    => $item['payments'][ array_search( $trx, array_column( $item['payments'], 'id' ), true ) ]['state'],
			'name'     => $user->display_name,
			'email'    => $user->user_email,
			'price'    => $item['amount'],
			'interval' => $item['interval'],
			'lines'    => $lines,
			'subtotal' => self::money( (int) $order->subtotal ),
			'discount' => $discount ? self::money( $discount ) : null,
			'tax'      => (int) $order->tax_total ? self::money( (int) $order->tax_total ) : null,
			'total'    => self::money( (int) $t->total ),
			'paid'     => $card,
			'store'    => Settings::name(),
		);
	}

	/** The shape Billing › membership shows, or null when it isn't the member's or isn't a membership. */
	private static function billing_item( $uuid ) {
		if ( ! self::active() || ! $uuid ) {
			return null;
		}
		$row = null;
		foreach ( self::billing( get_current_user_id() ) as $one ) {
			if ( $one['id'] === $uuid ) {
				$row = $one;
			}
		}
		if ( ! $row ) {
			return null;
		}
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/fluent-cart/v2/customer-profile/subscriptions/' . $uuid ) );
		$sub = $res->is_error() ? array() : (array) ( json_decode( wp_json_encode( $res->get_data() ), true )['subscription'] ?? array() );
		$db  = \FluentCart\App\Models\Subscription::query()->where( 'uuid', $uuid )->first();
		$row['since']    = $db ? Helpers::iso( $db->created_at ) : null;
		$row['can']      = array(
			'pause'  => ! empty( $sub['can_pause'] ) && 'active' === $row['state'],
			'resume' => ! empty( $sub['can_resume'] ) || 'paused' === $row['state'],
			'card'   => ! empty( $sub['can_update_payment_method'] ) || ! empty( $sub['card_update_url'] ),
			'cancel' => in_array( $row['state'], array( 'active', 'failed', 'paused' ), true ),
		);
		$row['card_url'] = (string) ( $sub['card_update_url'] ?? '' ) ?: $row['manage'];
		$row['payments'] = array_map(
			fn( $t ) => array(
				'id'      => (string) ( $t['uuid'] ?? '' ),
				'date'    => Helpers::iso( $t['created_at'] ?? '' ),
				'amount'  => self::money( (int) ( $t['total'] ?? 0 ) ),
				'state'   => array( 'succeeded' => 'paid', 'refunded' => 'refunded', 'failed' => 'failed', 'pending' => 'pending' )[ $t['status'] ?? '' ] ?? 'pending',
				'receipt' => (string) ( $t['receipt_download_url'] ?? '' ),
			),
			(array) ( $sub['transactions'] ?? array() )
		);
		return $row;
	}

	/** How many paid memberships the member has, for the menu's "Billing · 1 membership" (null when FluentCart is off). */
	public static function billing_count( $user_id ) {
		global $wpdb;
		if ( ! self::active() || ! $user_id || ! self::links() ) {
			return null;
		}
		$vars = array_map( 'intval', array_unique( array_values( self::links() ) ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the ids are ints.
				"SELECT COUNT(*) FROM %i s JOIN %i c ON c.id = s.customer_id WHERE c.user_id = %d AND s.status IN ('active','trialing','past_due','failing','paused','canceled','expiring') AND s.variation_id IN (" . implode( ',', $vars ) . ')',
				$wpdb->prefix . 'fct_subscriptions',
				$wpdb->prefix . 'fct_customers',
				$user_id
			)
		);
	}

	/**
	 * Removes the memberships a purchase gave (people added by hand keep theirs). Only when this
	 * order is the one that gave it: a member who bought again (a new subscription after
	 * cancelling, a second one-time order) keeps the access the newer order pays for.
	 */
	private static function take_back( $order, array $groups ) {
		$user_id = self::buyer( $order, null );
		if ( ! $user_id ) {
			return;
		}
		$bought = (array) get_user_meta( $user_id, self::BOUGHT, true );
		foreach ( $groups as $group_id ) {
			if ( isset( $bought[ $group_id ] ) && (int) $bought[ $group_id ] === (int) $order->id ) {
				Access::remove( $group_id, $user_id );
				unset( $bought[ $group_id ] );
			}
		}
		update_user_meta( $user_id, self::BOUGHT, $bought );
	}

	/** The memberships an order's items open. */
	public static function order_groups( $order ) {
		$items = $order->order_items ?? $order->order_items()->get();
		$out   = array();
		foreach ( $items as $item ) {
			$out = array_merge( $out, self::groups_for( (int) $item->object_id ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** The WordPress account that bought it: the customer's, else the one with that email. */
	private static function buyer( $order, $customer ) {
		$customer = $customer ?: ( $order->customer ?? null );
		$user_id  = (int) ( $customer->user_id ?? 0 ) ?: (int) ( $order->user_id ?? 0 );
		if ( ! $user_id && ! empty( $customer->email ) ) {
			$user    = get_user_by( 'email', $customer->email );
			$user_id = $user ? (int) $user->ID : 0;
		}
		return $user_id && get_userdata( $user_id ) ? $user_id : 0;
	}

	/* ---------- The simple checkout page ---------- */

	public static function template( $template ) {
		if ( ! is_page() ) {
			return $template;
		}
		$settings = new \FluentCart\Api\StoreSettings();
		$page_id  = (int) get_queried_object_id();
		if ( $page_id === (int) $settings->get( 'checkout_page_id' ) ) {
			$groups = self::cart_groups();
			if ( $groups ) {
				self::$view = array( 'step' => 'checkout', 'group' => Access::groups()[ $groups[0] ] ?? null );
			}
		} elseif ( $page_id === (int) $settings->get( 'receipt_page_id' ) ) {
			$order = self::receipt_order();
			if ( $order && 'paid' === $order->payment_status ) {
				$groups = self::order_groups( $order );
				if ( $groups ) {
					self::on_paid( array( 'order' => $order ) ); // in case the paid hooks haven't run yet
					self::$view = array( 'step' => 'paid', 'group' => Access::groups()[ $groups[0] ] ?? null, 'order' => $order );
				}
			}
		}
		// FluentCart's account page (where Manage billing goes): the community's frame, FluentCart's own app inside.
		if ( empty( self::$view ) && is_user_logged_in() && self::links() && $page_id && $page_id === (int) $settings->getCustomerProfilePageId() ) {
			Helpers::no_page_cache();
			return MEMBUILD_COMMUNITY_DIR . 'templates/account.php';
		}
		if ( empty( self::$view['group'] ) ) {
			return $template;
		}
		Helpers::no_page_cache();
		// The words on FluentCart's form, as on our drawing: "Join for $49.00 a month", "Have a discount code?".
		$item = self::product( self::link_of( self::$view['group']['id'] ) );
		if ( $item ) {
			$label = array(
				/* translators: %s: a price */
				'month' => __( 'Join for %s a month', 'membuild-community' ),
				/* translators: %s: a price */
				'year'  => __( 'Join for %s a year', 'membuild-community' ),
				/* translators: %s: a price */
				'once'  => __( 'Join for %s', 'membuild-community' ),
			)[ $item['period'] ];
			add_filter( 'fluent_cart/checkout_page_order_button_text', fn() => sprintf( $label, $item['amount'] ) );
		}
		add_filter(
			'gettext_fluent-cart',
			fn( $text, $original ) => 'Have a Coupon?' === $original ? __( 'Have a discount code?', 'membuild-community' ) : $text,
			10,
			2
		);
		return MEMBUILD_COMMUNITY_DIR . 'templates/checkout.php';
	}

	/** What templates/checkout.php shows: step, group, order. */
	public static $view = array();

	private static function cart_groups() {
		$cart = \FluentCart\App\Helpers\CartHelper::getCart();
		if ( ! $cart ) {
			return array();
		}
		return self::groups_in( is_array( $cart->cart_data ) ? $cart->cart_data : ( json_decode( (string) $cart->cart_data, true ) ?: array() ) );
	}

	/** The memberships a cart's items open; none when anything in it isn't a membership. */
	public static function groups_in( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$groups = self::groups_for( (int) ( $item['object_id'] ?? $item['id'] ?? 0 ) );
			if ( ! $groups ) {
				return array(); // something else is in the cart too: FluentCart's own checkout shows it all
			}
			$out = array_merge( $out, $groups );
		}
		return array_values( array_unique( $out ) );
	}

	/** The order on FluentCart's receipt page: by its order hash, or by the payment's (after a checkout). */
	private static function receipt_order() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only links from FluentCart's own redirect.
		$order = isset( $_GET['order_hash'] ) ? sanitize_text_field( wp_unslash( $_GET['order_hash'] ) ) : '';
		$trx   = isset( $_GET['trx_hash'] ) ? sanitize_text_field( wp_unslash( $_GET['trx_hash'] ) ) : '';
		// phpcs:enable
		if ( $order ) {
			return \FluentCart\App\Models\Order::query()->where( 'uuid', $order )->first();
		}
		if ( $trx ) {
			$t = \FluentCart\App\Models\OrderTransaction::query()->where( 'uuid', $trx )->first();
			return $t ? \FluentCart\App\Models\Order::query()->find( $t->order_id ) : null;
		}
		return null;
	}

	/** For the left column: the published courses a membership opens, with their lesson counts. */
	public static function perks( $group_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, title, access, access_groups FROM %i WHERE status = 'published' AND access = 'groups' ORDER BY sort_order ASC, id ASC", Install::table( 'courses' ) ), ARRAY_A ) as $c ) {
			if ( ! in_array( (int) $group_id, Access::course_groups( $c ), true ) ) {
				continue;
			}
			$n     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $c['id'] ) );
			$out[] = array(
				'id'   => (int) $c['id'],
				'text' => $n ? sprintf( /* translators: 1: course name, 2: number of lessons */ _n( '%1$s: %2$d lesson', '%1$s: %2$d lessons', $n, 'membuild-community' ), $c['title'], $n ) : $c['title'],
			);
		}
		return $out;
	}
}
