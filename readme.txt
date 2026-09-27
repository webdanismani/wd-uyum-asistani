=== WD Uyum Asistanı – İade Verisinden Beden/Kalıp Asistanı ===
Contributors: webdanismani
Tags: woocommerce, iade, beden, kalıp, beden tablosu
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.9
Stable tag: 1.0.0
License: GPLv2 or later

İade nedenlerini ürün ve beden bazında toplar. Gerçek iade ve alıcı geri bildirimi verisinden ürün sayfasında
"Alıcıların %62'si bir beden büyük tercih etti." bilgisini gösterir ve iadesi en pahalı ürünleri raporlar.

== Destek ==

Ücretsiz bir eklentidir. Destek, soru ve öneriler için ve diğer ücretsiz yazılımlarımız için: https://oblifex.com
Geliştirici: Web Danışmanı – https://webdanismani.com

== Özellikler ==

* Müşteri, Hesabım > Siparişler ekranından iade/değişim talebi açar: ürün, adet, neden ve "ne kadar dar/bol geldi" seçimi.
* İade etmeyen alıcılardan "kalıp nasıldı?" geri bildirimi toplanır (sipariş sayfası + otomatik e-posta). Böylece istatistik yalnızca şikâyet edenlere dayanmaz.
* Ürün sayfası rozeti: Dar–Bol ölçeği, Türkçe ekli yüzde (62'si, 40'ı, 100'ü), veri sayısı. Beden seçilince o bedene özel sonuç gösterilir.
* İstatistiksel güven: Wilson alt sınırı ile karar verilir; 3 veride %100 çıkması rozet göstermeye yetmez.
* Zaman ağırlığı: eski veriler yarı ömürle söner; tedarikçi kalıbı değiştirdiğinde rozet kendiliğinden güncellenir.
* Maliyet modeli: gidiş kargosu (kayıp), dönüş kargosu, işçilik, nedene göre değer kaybı. Her kayıt elle düzeltilebilir.
* Raporlar: genel bakış, iade oranı, neden dağılımı, iadesi en pahalı ürünler, CSV (Excel uyumlu, ; ayraçlı).
* Ürün uyum analizi: dar/tam/bol oranları, beden bazında sapma, otomatik aksiyon önerileri.
* WooCommerce entegrasyonu: tek tıkla WooCommerce iade kaydı + stoğa ekleme; WooCommerce'te elle yapılan iadeler de otomatik kayda geçer.
* HPOS uyumlu, blok tema (fiyat bloğu) desteği, [wdua_kalip] kısa kodu.

== Kurulum ==

1. Klasörü /wp-content/plugins/ altına yükleyin veya zip olarak Eklentiler > Yeni Ekle ile kurun.
2. Etkinleştirin. Menüde "Uyum Asistanı" görünür.
3. Uyum Asistanı > Ayarlar'dan beden nitelik adlarınızı (varsayılan: pa_beden, beden, pa_numara...), kargo/işçilik tutarlarını ve iade süresini kontrol edin.

== Kancalar ==

* wdua_reasons (filter) – iade nedenlerini değiştirin/ekleyin.
* wdua_computed_costs (filter) – maliyet hesaplamasını özelleştirin.
* wdua_item_accepts_feedback (filter) – hangi kalemlerden kalıp geri bildirimi istenecek.
* wdua_badge_html (filter) – rozet HTML'i.
* wdua_request_created, wdua_return_updated, wdua_stats_rebuilt (action).

== Changelog ==

= 1.0.0 =
* İlk sürüm.
