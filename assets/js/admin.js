/* WD Uyum Asistanı – yönetim paneli */
(function ($) {
	'use strict';

	$(function () {
		// Onay isteyen formlar.
		$(document).on('submit', 'form[data-confirm]', function (e) {
			if (!window.confirm($(this).data('confirm'))) {
				e.preventDefault();
			}
		});

		// WooCommerce > Ayarlar > Genel'deki para birimi biçimiyle yazar (konum, ayraçlar, ondalık).
		function formatPrice(n) {
			var c = window.wduaPrice || { format: '%1$s%2$s', symbol: '', decimals: 2, decSep: '.', thouSep: ',' };
			var parts = Math.abs(n).toFixed(c.decimals).split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, c.thouSep);
			var num = (n < 0 ? '-' : '') + parts.join(c.decSep);
			return c.format.replace('%1$s', c.symbol).replace('%2$s', num).replace(/&nbsp;/g, '\u00a0');
		}

		// Maliyet toplamını canlı hesapla.
		var $inputs = $('.wdua-cost-input');
		function total() {
			var t = 0;
			$inputs.each(function () {
				var v = parseFloat(String($(this).val()).replace(',', '.'));
				if (!isNaN(v)) { t += v; }
			});
			$('.wdua-cost-total').text(formatPrice(t));
		}
		$inputs.on('input change', total);

		// Kalıp alanını yalnızca beden/kalıp nedenlerinde göster.
		function toggleFit($select) {
			var cat = $select.find('option:selected').data('cat');
			$select.closest('form').find('.wdua-fit-row').toggle(cat === 'fit');
		}
		$('.wdua-reason-select').each(function () { toggleFit($(this)); }).on('change', function () { toggleFit($(this)); });
	});
})(jQuery);
