<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kalıp istatistikleri.
 *
 * Model:
 *  - Her sinyal (iade veya "kalıp nasıldı?" geri bildirimi) -2..+2 arası bir kalıp değeridir.
 *    Negatif = dar geldi (daha büyük beden tercih edilirdi), 0 = tam, pozitif = bol geldi.
 *  - Sinyaller yaşa göre üstel olarak ağırlıklandırılır (yarı ömür ayarı). Tedarikçi kalıbı
 *    değiştirdiğinde eski veri kendiliğinden etkisini yitirir.
 *  - Karar, Wilson güven aralığının ALT sınırı ile verilir: 3 veride %100 çıkması rozet
 *    göstermeye yetmez; veri arttıkça güven artar.
 */
class WDUA_Stats {

	const META = '_wdua_stats';

	/* --------------------------------------------------------------------
	 * Matematik
	 * ------------------------------------------------------------------ */

	/** Wilson skor aralığı alt sınırı (tek yönlü ~%90 güven). */
	public static function wilson_lb( $p, $n, $z = 1.2816 ) {
		if ( $n <= 0 ) {
			return 0.0;
		}
		$z2     = $z * $z;
		$denom  = 1 + $z2 / $n;
		$center = $p + $z2 / ( 2 * $n );
		$margin = $z * sqrt( max( 0, $p * ( 1 - $p ) / $n + $z2 / ( 4 * $n * $n ) ) );
		return max( 0.0, ( $center - $margin ) / $denom );
	}

	/**
	 * Sinyalleri toplar.
	 *
	 * @param array $signals Her biri: fit, created_at (GMT).
	 */
	public static function aggregate( array $signals, $half_life_days ) {
		$now   = time();
		$sum_w = 0.0;
		$sum_w2 = 0.0;
		$small = 0.0;
		$true  = 0.0;
		$large = 0.0;
		$fitw  = 0.0;
		$count = 0;

		foreach ( $signals as $s ) {
			if ( ! isset( $s['fit'] ) || null === $s['fit'] || '' === $s['fit'] ) {
				continue;
			}
			$fit = max( -2, min( 2, (int) $s['fit'] ) );
			$ts  = strtotime( $s['created_at'] . ' UTC' );
			$age = $ts ? max( 0, ( $now - $ts ) / DAY_IN_SECONDS ) : 0;
			$w   = $half_life_days > 0 ? pow( 0.5, $age / $half_life_days ) : 1.0;

			$sum_w  += $w;
			$sum_w2 += $w * $w;
			$fitw   += $w * $fit;
			++$count;

			if ( $fit < 0 ) {
				$small += $w;
			} elseif ( $fit > 0 ) {
				$large += $w;
			} else {
				$true += $w;
			}
		}

		if ( $count < 1 || $sum_w <= 0 ) {
			return array(
				'count'   => 0,
				'n_eff'   => 0,
				'p_small' => 0,
				'p_true'  => 0,
				'p_large' => 0,
				'mean'    => 0,
			);
		}

		return array(
			'count'   => $count,
			'n_eff'   => round( ( $sum_w * $sum_w ) / $sum_w2, 3 ),
			'p_small' => round( $small / $sum_w, 4 ),
			'p_true'  => round( $true / $sum_w, 4 ),
			'p_large' => round( $large / $sum_w, 4 ),
			'mean'    => round( $fitw / $sum_w, 3 ),
		);
	}

	/**
	 * Toplanmış veriden karar üretir. Ayarlar anlık uygulanır (yeniden hesaplama gerekmez).
	 *
	 * @return array type: small|large|true|none, pct: 0-100
	 */
	public static function verdict( $agg, $settings = null ) {
		$s    = $settings ? $settings : WDUA_Settings::all();
		$none = array(
			'type' => 'none',
			'pct'  => 0,
		);

		if ( empty( $agg['count'] ) || (int) $agg['count'] < max( 1, (int) $s['min_samples'] ) ) {
			return $none;
		}

		$conf = max( 0, min( 100, (float) $s['confidence'] ) ) / 100;
		$n    = (float) $agg['n_eff'];

		if ( $agg['p_small'] > $agg['p_large'] && self::wilson_lb( $agg['p_small'], $n ) >= $conf ) {
			return array(
				'type' => 'small',
				'pct'  => (int) round( $agg['p_small'] * 100 ),
			);
		}
		if ( $agg['p_large'] > $agg['p_small'] && self::wilson_lb( $agg['p_large'], $n ) >= $conf ) {
			return array(
				'type' => 'large',
				'pct'  => (int) round( $agg['p_large'] * 100 ),
			);
		}
		if ( 'yes' === $s['show_true_badge'] && self::wilson_lb( $agg['p_true'], $n ) >= $conf ) {
			return array(
				'type' => 'true',
				'pct'  => (int) round( $agg['p_true'] * 100 ),
			);
		}
		return $none;
	}

	/** Karar + ayarlardaki metin şablonu => cümle. */
	public static function message( array $verdict, $count, $settings = null ) {
		$s   = $settings ? $settings : WDUA_Settings::all();
		$map = array(
			'small' => 'text_small',
			'large' => 'text_large',
			'true'  => 'text_true',
		);
		if ( ! isset( $map[ $verdict['type'] ] ) ) {
			return '';
		}
		return self::fill( $s[ $map[ $verdict['type'] ] ], $verdict['pct'], $count );
	}

	public static function fill( $tpl, $pct, $count ) {
		return str_replace(
			array( '{pct}', '{n}' ),
			array( self::pct_tr( $pct ), number_format_i18n( (int) $count ) ),
			(string) $tpl
		);
	}

	/** Ölçek üzerindeki işaret konumu (%). */
	public static function marker( $mean ) {
		$pos = ( ( (float) $mean + 2 ) / 4 ) * 100;
		return max( 4, min( 96, round( $pos, 1 ) ) );
	}

	/**
	 * Sayı + Türkçe iyelik eki: 62'si, 40'ı, 30'u, 100'ü, 3'ü, 6'sı.
	 */
	public static function pct_tr( $n ) {
		$n = abs( (int) $n );
		return $n . "'" . self::tr_suffix( $n );
	}

	public static function tr_suffix( $n ) {
		$n = abs( (int) $n );
		if ( 0 === $n ) {
			return 'ı';
		}
		$ones = $n % 10;
		$tens = (int) floor( ( $n % 100 ) / 10 );
		if ( $ones ) {
			$m = array( 1 => 'i', 2 => 'si', 3 => 'ü', 4 => 'ü', 5 => 'i', 6 => 'sı', 7 => 'si', 8 => 'i', 9 => 'u' );
			return $m[ $ones ];
		}
		if ( $tens ) {
			$m = array( 1 => 'u', 2 => 'si', 3 => 'u', 4 => 'ı', 5 => 'si', 6 => 'ı', 7 => 'i', 8 => 'i', 9 => 'ı' );
			return $m[ $tens ];
		}
		return ( 0 === $n % 1000 ) ? 'i' : 'ü'; // bini / yüzü.
	}

	/* --------------------------------------------------------------------
	 * Önbellek (ürün meta)
	 * ------------------------------------------------------------------ */

	public static function rebuild_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		$s       = WDUA_Settings::all();
		$hl      = (int) $s['half_life_days'];
		$signals = WDUA_Repo::product_signals( $product_id );

		if ( ! $signals ) {
			delete_post_meta( $product_id, self::META );
			return;
		}

		$fit_signals = array();
		$groups      = array();
		$cats        = array();
		$returns     = 0;

		foreach ( $signals as $sig ) {
			if ( 'return' === $sig['src'] ) {
				++$returns;
				$c          = $sig['reason_cat'] ? $sig['reason_cat'] : 'other';
				$cats[ $c ] = isset( $cats[ $c ] ) ? $cats[ $c ] + 1 : 1;
			}
			if ( null === $sig['fit'] || '' === $sig['fit'] ) {
				continue;
			}
			$fit_signals[] = $sig;
			if ( '' !== (string) $sig['size_key'] ) {
				$k = (string) $sig['size_key'];
				if ( ! isset( $groups[ $k ] ) ) {
					$groups[ $k ] = array(
						'label'   => $sig['size_label'],
						'signals' => array(),
					);
				}
				$groups[ $k ]['signals'][] = $sig;
			}
		}

		$by_size = array();
		foreach ( $groups as $k => $g ) {
			$agg          = self::aggregate( $g['signals'], $hl );
			$agg['label'] = $g['label'];
			$by_size[ $k ] = $agg;
		}

		$stats = array(
			'overall' => self::aggregate( $fit_signals, $hl ),
			'by_size' => $by_size,
			'returns' => $returns,
			'cats'    => $cats,
			'updated' => time(),
		);

		update_post_meta( $product_id, self::META, $stats );
		do_action( 'wdua_stats_rebuilt', $product_id, $stats );
	}

	public static function rebuild_products( array $ids ) {
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			self::rebuild_product( $id );
		}
	}

	public static function rebuild_all() {
		self::rebuild_products( WDUA_Repo::products_with_signals() );
	}

	public static function get( $product_id ) {
		$s = get_post_meta( (int) $product_id, self::META, true );
		return is_array( $s ) ? $s : null;
	}

	/** Renk kaynaklı iade oranı (iadeler içinde). */
	public static function color_share( $stats ) {
		if ( empty( $stats['returns'] ) ) {
			return 0;
		}
		$c = isset( $stats['cats']['color'] ) ? (int) $stats['cats']['color'] : 0;
		return (int) round( 100 * $c / (int) $stats['returns'] );
	}

	/** Yönetici için aksiyon önerileri. */
	public static function recommendations( $stats, $settings = null ) {
		$s   = $settings ? $settings : WDUA_Settings::all();
		$out = array();
		if ( ! $stats ) {
			return $out;
		}
		$v = self::verdict( $stats['overall'], $s );
		if ( 'small' === $v['type'] ) {
			$out[] = 'Kalıp dar: açıklamaya "bir beden büyük tercih edin" notu ekleyin, beden tablosunu gözden geçirin.';
		} elseif ( 'large' === $v['type'] ) {
			$out[] = 'Kalıp bol: açıklamaya "bir beden küçük tercih edin" notu ekleyin, beden tablosunu gözden geçirin.';
		}

		$returns = (int) $stats['returns'];
		if ( $returns >= max( 3, (int) $s['min_samples'] ) ) {
			$share = function ( $cat ) use ( $stats, $returns ) {
				return isset( $stats['cats'][ $cat ] ) ? $stats['cats'][ $cat ] / $returns : 0;
			};
			if ( $share( 'color' ) >= 0.25 ) {
				$out[] = 'İadelerin %' . round( $share( 'color' ) * 100 ) . ' kadarı renk kaynaklı: ürünü gün ışığında yeniden fotoğraflayın.';
			}
			if ( $share( 'defect' ) >= 0.2 ) {
				$out[] = 'Kusur oranı yüksek (%' . round( $share( 'defect' ) * 100 ) . '): tedarikçi/paketleme kalite kontrolünü sıkılaştırın.';
			}
			if ( $share( 'quality' ) >= 0.25 ) {
				$out[] = 'Kalite şikâyeti yüksek: kumaş/malzeme bilgisini açıklamada netleştirin.';
			}
			if ( $share( 'wrong' ) >= 0.15 ) {
				$out[] = 'Yanlış gönderim oranı yüksek: depo toplama/etiketleme sürecini kontrol edin.';
			}
		}

		if ( ! empty( $stats['by_size'] ) ) {
			foreach ( $stats['by_size'] as $agg ) {
				$sv = self::verdict( $agg, $s );
				if ( 'none' !== $sv['type'] && 'true' !== $sv['type'] && $sv['type'] !== $v['type'] ) {
					$out[] = sprintf( '%s bedeni diğerlerinden farklı davranıyor (%s): o bedenin ölçülerini kontrol edin.', $agg['label'], 'small' === $sv['type'] ? 'dar' : 'bol' );
				}
			}
		}

		return $out;
	}
}
