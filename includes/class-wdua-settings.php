<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eklenti ayarları: varsayılanlar + okuma.
 */
class WDUA_Settings {

	const OPTION = 'wdua_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			// Ürün sayfası rozeti.
			'badge_enabled'        => 'yes',
			'badge_position'       => 'after_price',
			'show_true_badge'      => 'yes',
			'show_scale'           => 'yes',
			'show_color_hint'      => 'no',
			'color_hint_threshold' => 30,

			// Hesaplama.
			'min_samples'          => 5,
			'confidence'           => 40,
			'half_life_days'       => 180,
			'size_attributes'      => 'pa_beden, beden, pa_size, size, pa_numara, numara',

			// İade talepleri.
			'returns_enabled'      => 'yes',
			'return_window_days'   => 14,
			'return_statuses'      => array( 'wc-completed' ),
			'notify_admin'         => 'yes',
			'notify_customer'      => 'yes',
			'track_refunds'        => 'yes',

			// Alıcı geri bildirimi.
			'feedback_enabled'     => 'yes',
			'feedback_email'       => 'yes',
			'feedback_delay_days'  => 5,

			// Maliyet varsayılanları (TL).
			'cost_ship_out'        => 45,
			'cost_ship_back'       => 45,
			'cost_labor'           => 15,
			'ship_out_lost'        => 'yes',
			'exchange_ship_cost'   => 'yes',
			'dep_fit'              => 5,
			'dep_color'            => 5,
			'dep_quality'          => 20,
			'dep_defect'           => 100,
			'dep_wrong'            => 0,
			'dep_other'            => 10,

			// Metinler. {pct} Türkçe ekiyle birlikte yazılır (62'si, 40'ı, 100'ü), {n} veri sayısıdır.
			'text_title'           => 'Kalıp Bilgisi',
			'text_small'           => 'Alıcıların %{pct} bir beden büyük tercih etti.',
			'text_large'           => 'Alıcıların %{pct} bir beden küçük tercih etti.',
			'text_true'            => 'Alıcıların %{pct} bu ürünü tam kalıp buldu.',
			'text_color'           => 'İade edenlerin %{pct} rengi görseldekinden farklı buldu.',
			'text_source'          => '{n} alıcının iade ve geri bildirimine göre',

			// Gelişmiş.
			'delete_on_uninstall'  => 'no',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function save( array $values ) {
		update_option( self::OPTION, $values );
		self::$cache = null;
	}

	public static function yes( $key ) {
		return 'yes' === self::get( $key );
	}
}
