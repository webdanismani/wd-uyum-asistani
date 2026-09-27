<?php
defined( 'ABSPATH' ) || exit;

/**
 * İade nedenleri, kategoriler, kalıp ölçeği ve durumlar.
 */
class WDUA_Reasons {

	/**
	 * İade nedenleri.
	 * cat: fit | color | quality | defect | wrong | other
	 * fit: beden kaynaklı nedenlerde varsayılan kalıp yönü (-1 dar, +1 bol)
	 */
	public static function all() {
		$reasons = array(
			'dar_geldi'    => array( 'label' => 'Dar / küçük geldi', 'cat' => 'fit', 'fit' => -1 ),
			'bol_geldi'    => array( 'label' => 'Bol / büyük geldi', 'cat' => 'fit', 'fit' => 1 ),
			'kisa_geldi'   => array( 'label' => 'Boyu kısa geldi', 'cat' => 'fit', 'fit' => -1 ),
			'uzun_geldi'   => array( 'label' => 'Boyu uzun geldi', 'cat' => 'fit', 'fit' => 1 ),
			'renk_farkli'  => array( 'label' => 'Renk görseldekinden farklı', 'cat' => 'color', 'fit' => null ),
			'kumas_kalite' => array( 'label' => 'Kumaş / kalite beklentimi karşılamadı', 'cat' => 'quality', 'fit' => null ),
			'kusurlu'      => array( 'label' => 'Kusurlu / hasarlı ürün', 'cat' => 'defect', 'fit' => null ),
			'yanlis_urun'  => array( 'label' => 'Yanlış ürün veya beden gönderildi', 'cat' => 'wrong', 'fit' => null ),
			'vazgectim'    => array( 'label' => 'Vazgeçtim / beğenmedim', 'cat' => 'other', 'fit' => null ),
			'diger'        => array( 'label' => 'Diğer', 'cat' => 'other', 'fit' => null ),
			'belirtilmedi' => array( 'label' => 'Belirtilmedi (manuel iade)', 'cat' => 'other', 'fit' => null, 'admin_only' => true ),
		);

		return apply_filters( 'wdua_reasons', $reasons );
	}

	public static function customer_reasons() {
		return array_filter(
			self::all(),
			function ( $r ) {
				return empty( $r['admin_only'] );
			}
		);
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function label( $key ) {
		$r = self::get( $key );
		return $r ? $r['label'] : $key;
	}

	public static function categories() {
		return array(
			'fit'     => 'Beden / kalıp',
			'color'   => 'Renk',
			'quality' => 'Kalite',
			'defect'  => 'Kusur / hasar',
			'wrong'   => 'Yanlış gönderim',
			'other'   => 'Diğer',
		);
	}

	public static function fit_labels() {
		return array(
			-2 => 'Çok dar',
			-1 => 'Dar',
			0  => 'Tam',
			1  => 'Bol',
			2  => 'Çok bol',
		);
	}

	public static function fit_label( $fit ) {
		if ( null === $fit || '' === $fit ) {
			return '—';
		}
		$l = self::fit_labels();
		return isset( $l[ (int) $fit ] ) ? $l[ (int) $fit ] : '—';
	}

	public static function statuses() {
		return array(
			'requested' => 'Talep alındı',
			'approved'  => 'Onaylandı – kargo bekleniyor',
			'received'  => 'Ürün teslim alındı',
			'refunded'  => 'İade tamamlandı',
			'exchanged' => 'Değişim yapıldı',
			'rejected'  => 'Reddedildi',
		);
	}

	public static function status_label( $key ) {
		$s = self::statuses();
		return isset( $s[ $key ] ) ? $s[ $key ] : $key;
	}

	/** Maliyet raporuna giren durumlar (gerçekleşmiş iadeler). */
	public static function cost_statuses() {
		return array( 'approved', 'received', 'refunded', 'exchanged' );
	}

	/** Henüz kapanmamış durumlar. */
	public static function open_statuses() {
		return array( 'requested', 'approved', 'received' );
	}

	public static function customer_status_message( $status ) {
		$m = array(
			'approved'  => 'İade talebiniz onaylandı. Ürünü kargoya verebilirsiniz.',
			'received'  => 'İade ettiğiniz ürün tarafımıza ulaştı ve inceleniyor.',
			'refunded'  => 'İadeniz tamamlandı. Ücret iadesi ödeme yönteminize yapılacaktır.',
			'exchanged' => 'Değişim işleminiz tamamlandı. Yeni ürününüz kargoya verilecektir.',
			'rejected'  => 'İade talebiniz maalesef onaylanmadı.',
		);
		return isset( $m[ $status ] ) ? $m[ $status ] : '';
	}
}
