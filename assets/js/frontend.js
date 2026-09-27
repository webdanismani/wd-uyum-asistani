/* WD Uyum Asistanı – mağaza tarafı */
(function ($) {
	'use strict';

	/* ---------------- Ürün sayfası: varyasyona göre kalıp bilgisi ---------------- */

	// WordPress sanitize_title() ile uyumlu basit slug (özel nitelik değerleri için yedek eşleme).
	function slug(str) {
		var map = { 'ç': 'c', 'ğ': 'g', 'ı': 'i', 'i̇': 'i', 'ö': 'o', 'ş': 's', 'ü': 'u', 'â': 'a', 'î': 'i', 'û': 'u' };
		return String(str || '')
			.toLocaleLowerCase('tr-TR')
			.replace(/[çğıöşüâîû]/g, function (c) { return map[c] || c; })
			.normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/\./g, '-')
			.replace(/[^a-z0-9 _-]/g, '')
			.replace(/\s+/g, '-')
			.replace(/-+/g, '-')
			.replace(/^-|-$/g, '');
	}

	function isSizeAttr(name, list) {
		var k = String(name).toLowerCase().replace(/^attribute_/, '');
		var bare = k.replace(/^pa_/, '');
		for (var i = 0; i < list.length; i++) {
			if (list[i] === k || list[i].replace(/^pa_/, '') === bare) { return true; }
		}
		return false;
	}

	function initBadge($box) {
		var data;
		try { data = JSON.parse($box.attr('data-wdua')); } catch (e) { return; }
		if (!data) { return; }

		var $msg = $box.find('.wdua-fit__msg');
		var $dot = $box.find('.wdua-fit__dot');
		var $scale = $box.find('.wdua-fit__scale');
		var $src = $box.find('.wdua-fit__src');
		var $size = $box.find('.wdua-fit__size');
		var hasColor = $box.find('.wdua-fit__color').length > 0;

		function render(vm, sizeLabel) {
			$box.removeClass('wdua-fit--small wdua-fit--large wdua-fit--true wdua-fit--manual wdua-fit--none')
				.addClass('wdua-fit--' + (vm.type || 'none'));
			$msg.text(vm.msg || '');
			$dot.css('left', (vm.marker || 50) + '%');
			$scale.prop('hidden', !vm.scale);
			$src.text(vm.source || '').prop('hidden', !vm.scale || vm.type === 'manual');
			if (sizeLabel) {
				$size.text(data.sizeTpl.replace('%s', sizeLabel)).prop('hidden', false);
			} else {
				$size.prop('hidden', true);
			}
			$box.toggleClass('wdua-is-hidden', !vm.show && !hasColor);
		}

		function sizeKeyFor(variation, $form) {
			if (variation && data.vmap && data.vmap[variation.variation_id]) {
				return data.vmap[variation.variation_id];
			}
			// "Herhangi bir beden" tanımlı varyasyonlar için seçili değerden bul.
			var key = '';
			$form.find('[name^="attribute_"]').each(function () {
				if (!key && isSizeAttr(this.name, data.sizeAttrs || []) && $(this).val()) {
					key = slug($(this).val());
				}
			});
			return key;
		}

		var $scope = $box.closest('.product');
		var $form = $scope.length ? $scope.find('form.variations_form').first() : $();
		if (!$form.length) { $form = $('form.variations_form').first(); }
		if (!$form.length) { return; }

		$form.on('found_variation.wdua', function (e, variation) {
			var key = sizeKeyFor(variation, $form);
			var vm = key && data.sizes ? data.sizes[key] : null;
			if (vm && vm.show) {
				render(vm, vm.label);
			} else {
				render(data.overall, '');
			}
		});
		$form.on('reset_data.wdua hide_variation.wdua', function () {
			render(data.overall, '');
		});
	}

	/* ---------------- Hesabım: iade formu ---------------- */

	function initReturnForm($form) {
		$form.on('change', '.wdua-item__check', function () {
			var $item = $(this).closest('.wdua-item');
			var on = this.checked;
			$item.toggleClass('is-on', on);
			$item.find('.wdua-item__fields').prop('hidden', !on);
		});

		$form.on('change', '.wdua-reason', function () {
			var $opt = $(this).find('option:selected');
			var $item = $(this).closest('.wdua-item');
			var $pick = $item.find('.wdua-fitpick');
			var isFit = $opt.data('cat') === 'fit';
			var dir = parseInt($opt.data('fit'), 10) || 0;

			$pick.prop('hidden', !isFit);
			if (!isFit) {
				$pick.find('input').prop('checked', false);
				return;
			}
			$pick.find('.wdua-fitpick__dir').text(dir < 0 ? 'dar' : 'bol');
			// Yalnızca nedenle aynı yöndeki seçenekleri göster.
			$pick.find('.wdua-seg__opt').each(function () {
				var v = parseInt($(this).data('val'), 10);
				var ok = (dir < 0 && v < 0) || (dir > 0 && v > 0);
				$(this).toggle(ok);
				if (!ok) { $(this).find('input').prop('checked', false); }
			});
			if (!$pick.find('input:checked').length) {
				$pick.find('.wdua-seg__opt[data-val="' + dir + '"] input').prop('checked', true);
			}
		});

		$form.on('submit', function (e) {
			var $checked = $form.find('.wdua-item__check:checked');
			if (!$checked.length) {
				e.preventDefault();
				window.alert('Lütfen iade etmek istediğiniz en az bir ürünü seçin.');
				return;
			}
			var missing = false;
			$checked.each(function () {
				if (!$(this).closest('.wdua-item').find('.wdua-reason').val()) { missing = true; }
			});
			if (missing) {
				e.preventDefault();
				window.alert('Lütfen seçtiğiniz her ürün için bir iade nedeni belirtin.');
			}
		});
	}

	$(function () {
		$('.wdua-fit[data-wdua]').each(function () { initBadge($(this)); });
		$('.wdua-return-form').each(function () { initReturnForm($(this)); });
	});
})(jQuery);
