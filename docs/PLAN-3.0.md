# Erdo Image Optimizer 3.0 — Yeniden Yapılanma Planı

_Güncelleme: 2026-10-24 · Dal: `feature/hardening-2.3` · Önceki 9 fazlı hardening planının yerini alır._

## 0. Özet

**Hedef konum:** "Görsel kütüphaneni güvenle SEO'ya hazır hâle getir." Image SEO denetimi + AI (kullanıcının kendi anahtarı) + güvenli yeniden adlandırma/temizlik + orijinale dokunmayan WebP/AVIF. Sıkıştırmada 1M'lik devlerle yarışmıyoruz; onların zayıf olduğu, 10–100K'lık tek işlevli eklentilere bölünmüş alanı birleştiriyoruz.

**Sürüm:** 3.0.0 (mimari değişiyor). Öncesinde acil bir **2.2.3 düzeltmesi** öneriliyor (bkz. Faz H).

**Mevcut durum (2026-10-24):** 20 aktif kurulum, ~5 indirme/gün, 3 yorum (5★), 2 destek konusu.

---

## 1. Doğrulanmış kritik bulgular (Studio, 2026-10-24)

| # | Bulgu | Kanıt | Önem |
|---|---|---|---|
| K1 | **Dönüştürme siteyi bozuyor.** `convert_to_webp()` orijinali siliyor, içerikteki `.jpg` URL'leri güncellenmiyor. `<picture>` sarmalayıcı geçersiz `<source src>` yazıyor, tarayıcı silinmiş `.jpg`'ye düşüyor. | Studio: yazıdaki tam boy + 300x200 görsel **HTTP 404**, Chromium'da kırık | Kritik |
| K2 | `.htaccess` kuralı `x.jpg.webp` arıyor, eklenti `x.webp` yazıyor; kural hiç eşleşmiyor | Kod + baseline | Yüksek |
| K3 | Yüklemede otomatik dönüştürme yalnızca ana dosyayı çeviriyor, alt boyutlar JPEG kalıyor | Kod | Orta |
| K4 | Lazy-load filtresi LCP / `fetchpriority=high` görsele de `lazy` ekliyor; core'un 6.3+ mantığıyla çelişiyor | Baseline | Yüksek (performans) |
| K5 | "Kullanılmayan" tespiti güvenilmez (logo, sadece boyutlu URL, bayat cache); silme kalıcı | Baseline + panelde 16 görselin 12'si "Unused" | Yüksek (veri kaybı) |
| K6 | Lossless modu lossless değil; animasyonlu GIF düzleşip orijinali siliniyor | Baseline | Orta |
| K7 | Yeniden adlandırma boyutlu URL'leri bırakıyor; ham `REPLACE` serileştirilmiş meta'yı bozabiliyor | Baseline | Yüksek |
| K8 | Readme "orijinaller asla kaybolmaz" diyor, ama yedekleme varsayılan olarak kapalı | Kod | Yüksek (güven) |
| K9 | Multisite network ayarları kaydediliyor ama hiçbir yerde okunmuyor | grep | Düşük |
| K10 | AVIF tespiti `function_exists('imageavif')` ile yapılıyor; bazı sunucularda boş dosya üretiyor | Araştırma (core #60628, php #81217) | Orta |
| K11 | WP 7.1'de `wp_generate_attachment_metadata` iki kez çalışıyor (`create` + `update`); hook'larımız buna göre yazılmamış | make.wordpress.org | Orta |
| K12 | SEO puanında görselin `title` niteliğinin bir SEO karşılığı yok; "SEO Health" kartı yalnızca alt metin oranını gösteriyor | Google dokümanı + panel | Orta (ürün) |

**Faz 1 test paketinin ortaya çıkardığı ek bulgular (2026-10-24):**

| # | Bulgu | Faz |
|---|---|---|
| K13 | PNG → WebP dönüşümü bazı dosyalarda **daha büyük** çıktı üretiyor (34,6 KB > 29 KB), 2.2.2 yine de orijinali siliyor | F2 |
| K14 | Yeniden adlandırma **serileştirilmiş meta'yı bozuyor** (slider verisi artık unserialize edilemiyor = veri kaybı) | F4 |
| K15 | Büyük harfli uzantı (`IMG_1234.JPG`) yeniden adlandırılınca içerik güncellenmiyor ve görsel 404 veriyor (eski URL küçük harfli `.jpg` ile kuruluyor) | F4 |
| K16 | Yeniden adlandırma içeriği doğrudan SQL ile güncelliyor ama `clean_post_cache` çağırmıyor; kalıcı object cache (Redis) olan sitelerde eski, kırık URL'ler sunulmaya devam eder | F4 |
| K17 | Elementor JSON'daki (`http:\/\/`) URL'ler yeniden adlandırmada güncellenmiyor | F4 |
| K18 | Kullanım tespiti 8 gerçek kullanımı kaçırıyor: boyutlu URL, logo, site ikonu, header, ACF ID, Woo galeri, widget'lar, çöp kutusu. Ayrıca yazıya ekli olup içerikte geçmeyen görseli "kullanılıyor" sayıyor | F3 |
| K19 | Performans: 2.000 görselde tam kullanım taraması 68 sn (34 ms/görsel); görsel × yazı sayısıyla büyüyor | F3 |
| — | Güvenlik: 25 AJAX ucunun hepsi abone + geçerli nonce ve nonce'suz admin isteklerini reddediyor; REST anonim erişime kapalı ✅ | — |

---

## 2. Mimari kararlar

1. **Kardeş dosya modeli.** Orijinal hiçbir zaman silinmez ve değiştirilmez. Her boyut için `foto.jpg.webp` / `foto.jpg.avif` üretilir. Ekleme biçimi (`.jpg.webp`) şunları sağlar: `foto.jpg` ile `foto.png` çakışmaz, nginx `try_files $uri.webp` doğrudan çalışır, EWWW/WebP Express ile aynı konvansiyondur. Kardeş dosya yalnızca orijinalden küçükse tutulur. Geri yükleme = kardeş dosyaları silmek. `_erdo_backup/` kalkar.
2. **Sunum katmanı.**
   - Varsayılan: `wp_content_img_tag` (WP 6.0+) + `wp_get_attachment_image` üzerinden doğru `<picture>` (`<source type srcset sizes>`). Bu yöntem CDN dostudur, Cloudflare free ile de çalışır.
   - İsteğe bağlı: Apache `.htaccess` (`Vary: Accept` + CDN uyarısı) ve nginx kural metni.
   - Devre dışı bırakınca kurallar temizlenir. Kaldırınca kardeş dosyaları silmek opsiyoneldir.
3. **Core'u kullan, kopyalama.**
   - Yeniden boyutlandırma → `big_image_size_threshold`.
   - Kalite → `wp_editor_set_quality`.
   - Lazy/fetchpriority → core'a bırakılır; kendi filtremiz kalkar.
   - Kodlama → `WP_Image_Editor` (Imagick/GD). Lossless ve animasyon kontrolü küçük bir `ERDO_Encoder` katmanında yapılır, desteği gerçek bir deneme kodlamasıyla ölçer.
4. **Idempotent yükleme hattı.** Metadata hook'u `create`/`update` geçişlerinde iki kez çalışsa da aynı sonucu üretir. Kardeş üretim işi kuyruğa alınır, yüklemeyi yavaşlatmaz.
5. **Kullanım indeksi.** Her taramada `LIKE` sorgusu yerine `{prefix}erdo_usage` tablosu tutulur (attachment, kaynak tipi, kaynak id, bağlam). Arka planda bir kez kurulur, `save_post` / `updated_option` / `deleted_post` vb. ile artımlı güncellenir. "Nerede kullanılıyor" bilgisinin ve güvenli temizliğin temeli budur.
6. **Kalıcı silme yok → karantina.** Dosyalar `_erdo_quarantine/` klasörüne taşınır, attachment kaydı durur, tek tıkla geri alınır. Kalıcı silme ayrı ve açık bir eylemdir. Otomatik temizlik yoktur.
7. **AI = WP 7.0 core AI Client + Connectors.**
   - Anahtar bizde saklanmaz; kullanıcı **Settings > Connectors**'ta Anthropic, Google ya da OpenAI anahtarını girer.
   - Özellik `function_exists('wp_ai_client_prompt')` + `is_supported_*()` ile tespit edilir. Eski WP'de "WP 7.0 gerekli" kartı gösterilir.
   - Kendi HTTP istemcimizi 3.0'da yazmıyoruz. Gerekçe: 7.0+ siteler zaten %71, güvenlik yüzeyi ve wp.org inceleme riski küçülür. Talep gelirse 3.x'te eklenir.
8. **Kapsam daraltma (3.0'da kaldırılır, changelog'da alternatif önerilir).**
   - SVG yükleme: güvenlik yüzeyi; yerine Safe SVG önerilir.
   - BunnyCDN: çok niş.
   - Sahte multisite network sayfası: site bazlı ayarlar kalır.
   - Özel resize-on-upload kodu: core eşiğine taşınır.
   - Kendi lazy-load filtremiz.
   - Korunanlar: REST uçları, WP-CLI (genişletilir), dashboard widget, önce/sonra kaydırıcısı.
9. **Gereksinimler.** `Requires at least: 6.5` (AVIF + yükleme optimizasyonu API'leri; 6.5 altı kullanım çok düşük). `Requires PHP: 7.4` kalır (sitelerin %24'ü 8.1 altında). AI özellikleri yalnızca 7.0+.
10. **Build adımı yok.** Mevcut proje kuralı korunur: modüler vanilla JS / jQuery, bundler yok.

---

## 3. Fazlar

Her fazın sonunda: Studio'da canlı test + ilgili kod yollarının çapraz incelemesi (proaktif review kuralı). Önce tüm kontroller yapılır, bulgular toplu raporlanır, sonra düzeltmeye geçilir.

### ~~Faz H — Acil 2.2.3 düzeltmesi~~ (İPTAL, 2026-10-24)
Kullanıcı kararı: ara sürüm çıkmayacak, doğrudan 3.0'a geçilecek (kullanıcı sayısı az). **Kırık referans kurtarıcı filtre** yine de 3.0'a güvenlik ağı olarak girer (Faz 2). Bu filtre, `src`/`srcset`'teki uploads dosyası yoksa ve aynı gövdeli bir `.webp`/`.avif` dosyası varsa URL'yi ona çevirir.

### Faz 1 — Test altyapısı ✅ (2026-10-24)
**Teslim edildi:**
- `tests/run.ps1` ve 9 suite (`00-env`, `10-usage`, `20-convert`, `30-rename`, `40-delivery`, `50-seo`, `60-upload`, `70-security`, `90-perf`).
- Her test hedef fazıyla etiketlenir.
- Tüm eklenti çağrıları tek bir adaptör dosyasından (`lib/adapter.php`) geçer.
- Görsel kontrolleri gerçek HTTP üzerinden yapılır.
- AJAX uçları otomatik keşfedilir; güvenlik testinin gerçekten bir şey ölçtüğünü pozitif bir kontrol testi doğrular.

**2.2.2 baseline** (`tests/baseline-2.2.2.csv`): 111 PASS / 54 FAIL. Dağılım: F2: 29, F3: 13, F4: 12, "şimdiden geçmeli": 0.

Plandaki maddeler (referans):
- `tests/` fixture'ları genişletilir:
  - İçerik türleri: Gutenberg, klasik, boyutlu URL.
  - Builder ve eklenti verileri: Elementor JSON meta, ACF görsel alanı (ID / dizi / URL), Woo galeri.
  - Site ayarları: `theme_mods` logosu ve başlığı, site ikonu, widget'lar.
  - Özel durumlar: serileştirilmiş meta, animasyonlu GIF, büyük PNG, HEIC.
- Tek komutlu test koşucusu (`tests/run.ps1`), PASS/FAIL çıktısı. 2.2.2 baseline'ı kaydedilir.
- Playwright duman testleri: frontend görsel yükleniyor mu (HTTP 200 + naturalWidth), admin panel akışları.
- Sentetik 5.000 görsellik kütüphane ile performans ölçümü.

### Faz 2 — Güvenli çekirdek (dönüşüm + sunum) ✅ (2026-10-24)
**Teslim edildi:**
- Yeni sınıflar: `ERDO_Encoder`, `ERDO_Converter`, `ERDO_References`, `ERDO_Migration`.
- `ERDO_WebP_Server` yeniden yazıldı: `<picture>` + `.htaccess` + nginx + kurtarma filtresi.
- 2.x'in yıkıcı akışı kaldırıldı: yedekleme, `convert_to_webp`, lazy filtresi, resize-on-upload.
- Kuyruk, CLI (`wp erdo migrate`), dashboard, widget, REST ve detay paneli yeni modele bağlandı.
- Uninstall, ürettiği kardeş dosyaları da siliyor.

**Sonuç:**
- Test: 157 PASS, F2 hatası **0** (29'dan). Kalan 25 hata F3/F4'e ait.
- Plugin Check temiz.
- Tarayıcıda doğrulandı:
  - Ön yüzde AVIF sunuluyor, iç içe `<picture>` oluşmuyor, LCP korunuyor.
  - Admin akışları çalışıyor: Compare → Restore → Convert, "Repair now".

**Faz sırasında eklenen kurallar:**
- "AVIF + WebP" modunda AVIF yalnızca WebP'den küçükse tutulur.
- `original_image` (`-scaled` öncesi dosya) dönüştürülmez.

**Ertelenen (Faz 7):** Admin bildirimi eklenti başlığının içine yerleşiyor; yerleşimi sıkışık.

Plandaki maddeler (referans):
- `ERDO_Encoder`: yetenek tespiti gerçek deneme kodlamasıyla yapılır (WebP/AVIF × GD/Imagick, lossless, animasyon).
- `ERDO_Converter`: tüm boyutlar + `-scaled` için kardeş dosya, "küçükse tut", attachment başına `_erdo_formats` meta, kuyrukla toplu işlem, idempotent yükleme hook'u (7.1 iki geçiş).
- `ERDO_Delivery`: `<picture>` (srcset/sizes doğru), `.htaccess` (doğru adlandırma + Vary), nginx metni, aktivasyon/deaktivasyon temizliği.
- Animasyonlu GIF: Imagick animasyonu koruyabiliyorsa çevrilir, yoksa "atlandı" olarak işaretlenir.
- **2.x → 3.0 geçiş ve onarım sihirbazı.**
  - 2.x'in yıkıcı biçimde çevirdiği attachment'lar tespit edilir.
  - Yedek varsa: orijinal ana dosya olarak geri gelir, WebP kardeş dosyaya dönüşür.
  - Yedek yoksa: WebP ana dosya olarak kalır, içerikteki eski `.jpg` ve boyutlu URL'ler serileştirme güvenli şekilde güncellenir.
  - Her durumda önce dry-run raporu, sonra uygulama, sonra 404 taraması.
- Kaldırılanlar: lazy filtresi, resize-on-upload (→ core eşiği), `_erdo_backup` akışı (geçiş sonrası).

### Faz 3 — Kullanım indeksi + güvenli temizlik ✅ (2026-10-24)
**Teslim edildi:**
- `ERDO_Usage`: `{prefix}erdo_usage` tablosu; artımlı güncelleme (yazı, meta, seçenek, terim); partiler hâlinde tam kurulum (cron / AJAX / CLI).
  - URL → ID çözümü: boyutlu, `.webp` kardeşi, `-scaled`, `-eNNN`, 2.x kalıntısı.
  - Yapısal ID anahtarları: blok attr, JSON, serileştirilmiş veri, ACF.
  - Kullanım haritası (`sources()`).
- `ERDO_Quarantine`: tahmin edilemez klasör, özel post durumu, geri yükleme, kalıcı silme, otomatik temizlik ayarı (varsayılan kapalı).
  - Toplu işlemde iki katmanlı denetim: indeks + "dosya adı herhangi bir yerde geçiyor mu".
- Arayüz: indeks kurulum bildirimi, Karantina sekmesi, detay panelinde "Nerede kullanılıyor", "Quarantine All Unused".
  - Tekil "Delete" artık karantinaya alıyor.
- CLI: `wp erdo index`, `wp erdo unused`, `wp erdo quarantine list|add|restore|purge|add-unused`.
- Uninstall: karantinadakileri geri koyuyor, tabloyu siliyor.

**Sonuç:**
- 195 PASS, F3 hatası **0**; kalan 12 hata F4'e ait. Plugin Check temiz.
- Performans (2.000 görsel / 1.000 yazı):
  - Tam tarama 68 sn → **5 sn**.
  - İndeks kurulumu **1,75 sn**.
- Tarayıcıda doğrulandı:
  - İlk kurulum otomatik tamamlanıyor.
  - Kullanım haritası linkli.
  - Toplu karantina yalnızca kullanılmayanları taşıdı; ön yüzde kırık görsel yok.
  - Geri yükleme butonu çalışıyor.

**Faz sırasında bulunan ek sorunlar:**
- `bulk_analyze()` ham meta satırlarını 300 sn object cache'te tutuyordu. Görselin audit cache'i temizlense de grid bayat kalıyordu (2.2.2'den kalma, Redis'li sitelerde 5 dakikalık yanlış "kullanılmıyor" riski). İndeks değişince cache grubu temizleniyor.
- Blok temalarda core `custom_logo` ↔ `site_logo` senkronu yapıyor; ikisi ayrı ayrı izleniyor.
- Karantina klasörü nginx ve PHP sunucusunda `.htaccess` okunmadığı için açıktı → klasör adına rastgele belirteç eklendi.
- Windows'ta core'un `wp_delete_attachment` adımı alt boyutları silemiyor (çekirdek / yol sorunu). Purge artık dosyaları kendisi siliyor.
- Hata ve etkinlik günlüğü tutan eklentiler (test sitesinde Watch Your WP) dosya yollarını seçeneklere yazıyor. Derin kontrol bu yüzden kullanılmayan görselleri kilitliyordu. Adında log/error/debug/cache/history/stats/activity/audit geçen seçenekler artık bu kontrolde atlanıyor (testi var).

**Faz 7'ye kalan:** Karantina onay penceresi hâlâ kırmızı "tehlike" görünümünde; geri alınabilir bir işleme uygun sakin bir tasarıma geçmeli.

Plandaki maddeler (referans):
- `erdo_usage` tablosu (`dbDelta`) ve ayrıştırıcılar:
  - İçerik: `wp-image-ID`, blok `id` nitelikleri, tam/boyutlu/kardeş URL'ler.
  - Postmeta: serileştirilmiş ve JSON veriyi anlayan, ID ve URL arayan.
  - Ayarlar: `theme_mods`, `site_icon`, widget'lar.
  - Diğer: term meta, Woo galeri, öne çıkan görsel.
- Artımlı güncelleme hook'ları + arka planda ilk kurulum (kuyruk) + "yeniden tara".
- Güven seviyeleri: Kullanılıyor / Muhtemelen kullanılmıyor / Kullanılmıyor (ve "bu tür kaynaklar taranamadı" uyarısı).
- Karantina + geri alma + yok sayma listesi. Toplu karantina sunucu tarafında taze kontrol yapar (`only_unused` koruması).
- Görsel detayında **kullanım haritası**: hangi yazı/sayfa/ayar, linkleriyle.

### Faz 4 — Image SEO motoru 2.0 ✅ (2026-10-24)
**Teslim edildi:**
- `ERDO_SEO`: 0–100 puan (alt 40, dosya adı 20, format 10, dosya boyutu 10, boyutlar 5, bağlam 15). `title` bilgi amaçlı, puana katılmıyor.
  - Anlamsız ad tespiti: kamera/telefon önekleri (IMG, DSC, PXL…), ekran görüntüsü, WhatsApp, "adsız", hash/UUID.
  - Alt kalitesi: dosya adına benzeyen, "image of …", anahtar kelime listesi, çok uzun; dekoratif işaretli görselde boş alt geçerli.
  - Bağlam: yayımlanmış, herkese açık, noindex olmayan sayfada mı (taslak ya da yalnızca CSS'te kullanılan görsel daha düşük puan alıyor).
  - Odak anahtar kelime: Yoast, Rank Math, SEOPress, AIOSEO.
- `ERDO_Renamer`: dosyalar yalnızca taşınıyor (yeniden kodlama yok). Kapsam: tüm boyutlar, `-scaled` orijinali, düzenleme yedekleri, WebP/AVIF kardeşleri.
  - Referanslar `ERDO_References` ile güncelleniyor; K14–K17 kapandı.
  - Eski URL'ler 301 ile yeni adrese gidiyor; zincirler tek adıma iniyor.
  - Geri alma iki düzeyde: görsel başına ve toplu işlem başına.
- Desenli toplu yeniden adlandırma: `{keyword} {n} {parent} {title} {original} {alt}`; `{keyword}` boşsa sayfanın odak anahtar kelimesi kullanılıyor.
- Arayüz:
  - Kartlarda çoklu seçim ve seçim çubuğu.
  - Yeniden adlandırma penceresi: önizleme → adları düzenle → uygula → geri al. "Fix All Filenames" de artık önce önizleme gösteriyor.
  - Detay paneli: puan satırları, "dekoratif" işareti, odak anahtar kelime ipucu, "Renamed from … Undo".
  - Panel kartı: ortalama SEO puanı.
- Görsel site haritası: core `wp-sitemap.xml` içinde `wp-sitemap-erdoimages-1.xml`. SEO eklentisi varsa kapanıyor; ayarı var.
- CLI: `wp erdo rename` (`--all`, `--pattern`, `--dry-run`, `--undo`); `wp erdo audit` 0–100 + sorun listesi. CSV'de formül enjeksiyonu koruması.
- Ek düzeltme: detay panelinde yalnızca alt metni kaydetmek, dosya adı alanına dokunulmasa da `IMG_1234`'ü yeniden adlandırıyordu (2.x'ten kalma).

**Faz 6'ya not:** `uninstall.php` karantinadaki dosyayı geri taşıyamazsa (Windows'ta kilitli dosya) yine de durumu `inherit` yapıp kaydı siliyor. Dosyalar karantina klasöründe sahipsiz kalıyor. Test sitesinde 7 gerçek görselde bu belirti görüldü; nedeni kesin değil, dosyalar elle geri taşındı.

Plandaki maddeler (referans):
- **0–100 puan, ağırlıklı kategoriler.**
  - Alt metin (%40): var mı; kalitesi (dosya adına benzemiyor, "image of / resim" yok, makul uzunluk, anahtar kelime doldurma yok); dekoratif işaretli mi.
  - Dosya adı (%20): tanımlayıcı mı, kamera kalıbı mı (`IMG_`, `DSC_`, `Screenshot`, `WhatsApp Image`), tire mi alt çizgi mi, uzunluk.
  - Performans (%25): dosya boyutu, gösterim boyutuna göre aşırı büyüklük, modern format kardeşi var mı.
  - Bağlam (%15): kullanılıyor mu, indekslenebilir bir sayfada mı.
  - `title` niteliği bilgi amaçlı gösterilir, puana katılmaz.
- **Toplu yeniden adlandırma 2.0.**
  - Çoklu seçim + desen (`{keyword}-{n}`, `{parent}`, `{title}`, `{original}`); kullanıcının istediği özellik.
  - Tüm boyutlar ve kardeş dosyalar dahil, serileştirme güvenli referans güncelleme.
  - Dry-run önizleme + geri alma kaydı.
  - **Eski URL → yeni URL 301 yönlendirmesi** (404'te `_erdo_old_urls` haritası). Backlink ve Google Görseller korunur.
- "Dosya adından alt metin" kamera kalıplı ve anlamsız adları atlar.
- SEO eklentisi entegrasyonu: Yoast / Rank Math / AIOSEO odak anahtar kelimesini okur.
- Görsel site haritası: core `wp-sitemaps`'e `image:loc` eklenir; yalnızca SEO eklentisi bunu zaten yapmıyorsa.
- CSV dışa aktarma yeni puanlamayla güncellenir.

### Faz 5 — AI özellikleri (kullanıcının kendi anahtarı, core Connectors) ✅ (2026-10-24)
**Teslim edildi:**
- `ERDO_AI`: çekirdek `wp_ai_client_prompt()` kullanılıyor; anahtar eklentide saklanmıyor.
  - Hazırlık kontrolü istek atmadan yapılıyor. Durumlar: "WP 7.0 gerekli", "AI kapalı", "sağlayıcı bağlı değil".
  - Tek çağrıda JSON şemasıyla alt metin, dosya adı, başlık, altyazı, dekoratif kararı ve mevcut alt metnin denetimi alınıyor.
  - Önce ucuz görsel modeller tercih ediliyor (Haiku 4.5, Gemini 2.5 Flash, GPT-5 mini).
- Görsel ≤1024 px gönderiliyor. AVIF, HEIC, GIF ve büyük PNG geçici JPEG'e çevriliyor; 150 px küçük resim gönderilmiyor.
- Bağlam:
  - Görselin içerikte göründüğü sayfa (galeri/meta değil), odak anahtar kelime ve çevresindeki ~500 karakter metin.
  - Dil (WPML/Polylang dahil), Woo ürün adı ve kategorisi.
  - Kişisel veri gönderilmiyor.
- Önce incele, sonra kaydet:
  - Öneriler `_erdo_ai_suggestion` meta'sında bekliyor.
  - Detay panelindeki kutuda alan alan seçilip düzenlenerek kabul ediliyor.
  - "AI Suggestions" sekmesinde tek tek ya da toptan kabul/ret yapılabiliyor.
  - Dosya adı kabulü güvenli yeniden adlandırmadan (301 dahil) geçiyor.
- Toplu üretim: "eksik alt" ya da "eksik veya zayıf alt" kapsamı, maliyet tahmini, durdurma, hız sınırında artan beklemeyle yeniden deneme.
- Yüklemede otomatik öneri: ayarı var, varsayılan kapalı, arka planda (cron) çalışıyor.
- REST: `POST erdo/v1/ai/{id}/suggest|accept|reject`, o görsel için `edit_post` yetkisi gerekiyor (E1/E2 için hazır).
- readme'ye "External services" bölümü eklendi: ne zaman, hangi veri, hangi sağlayıcı, şartlar ve gizlilik linkleri.
- Test: `55-ai` (21 test). Sağlayıcı `erdo_ai_pre_generate` filtresiyle taklit ediliyor.
  - Gerçek çağrı zinciri anahtarsız çalıştırıldı: beklenen "model bulunamadı" hatasıyla bitiyor, fatal hata yok.
  - **Gerçek bir sağlayıcıyla uçtan uca deneme yapılmadı** (anahtar gerekiyor).

Plandaki maddeler (referans):
- **Özellikler** (hepsi kullanıcının başlattığı eylemler; yüklemede otomatik çalışma opsiyonel ve varsayılan kapalı):
  1. Alt metin üretimi: tekli + toplu.
  2. Dekoratif görsel tespiti (W3C karar ağacı → `alt=""` önerisi).
  3. Dosya adı önerisi (yeniden adlandırma 2.0 ile birleşik).
  4. Başlık / altyazı önerisi.
  5. Mevcut alt metin denetimi: "görselle uyuşmuyor" uyarısı.
- **Önce incele, sonra kaydet.** Öneriler `_erdo_ai_suggestion` meta'sına yazılır. "AI Önerileri" kuyruğunda kabul / düzenle / reddet / tümünü kabul et seçenekleri olur. Rakiplerin "otomatik yaz" yaklaşımından bu noktada ayrışıyoruz.
- **Bağlam:** üst yazı başlığı, görselin çevresindeki metin, odak anahtar kelime (yalnızca görseli doğru tarif ediyorsa), sitenin ya da yazının dili (WPML/Polylang). Kişi adı verilmez.
- **Maliyet kontrolü:** görsel ~1024px JPEG/WebP'ye küçültülüp base64 olarak gönderilir (AVIF/HEIC önce çevrilir; Claude AVIF kabul etmiyor). Yapılandırılmış JSON çıktı alınır. Toplu işlemden önce tahmini maliyet gösterilir (1.000 görsel için yaklaşık $0.15–0.40, en ucuz uygun modelle). İşlem iptal edilebilir, hız sınırına ve yeniden denemeye uyar.
- **Uygunluk:** readme'de "External services" bölümü (ne zaman, hangi veri, hangi sağlayıcı, şartlar/gizlilik linkleri). Özellik başına REST ucu + yetki kontrolü (toplu işlem için `manage_options`, tekil işlem için attachment başına `edit_post`).
- **Kurulum kartı:** Connectors yoksa ya da sağlayıcı eklentisi kurulu değilse adım adım yönlendirme.
- **Konum notu:** resmi "AI" eklentisi (60K) yalnızca blok editörde çalışıyor, odak anahtar kelime kullanmıyor ve toplu inceleme kuyruğu yok. Bizim farkımız SEO denetimiyle bütünleşik, toplu, incelemeli akış.

### Faz 6 — Güvenlik, kalite, uyumluluk ✅ (2026-10-24)
**Teslim edildi:**
- Kaldırılanlar: SVG yükleme ve sanitizer, BunnyCDN entegrasyonu, sahte multisite network sayfası.
  - Yükseltmede (DB v6) eski ayarlar, API anahtarı ve `_erdo_cdn_url` siliniyor.
  - 2.x'ten gelenlere bir kerelik, kapatılabilir "3.0'da neler değişti" bildirimi gösteriliyor; Safe SVG ve pull zone alternatifleri yazıyor.
- `Requires at least: 6.5`; eklenti açıklaması yeni konuma göre yazıldı.
- Yol güvenliği: `ERDO_References::in_uploads()` (realpath tabanlı).
  - Karantina, geri yükleme, kalıcı silme, 2.x onarımı, dönüştürme, yeniden adlandırma, boyutlandırma ve uninstall yalnızca uploads içinde çalışıyor.
  - Elle değiştirilmiş meta ile uploads dışına dosya yazılamıyor (testi var).
- Karantina artık medya düzenleyicisinin yedeklerini (`_wp_attachment_backup_sizes`) de taşıyor. Önceden bu yedekler herkese açık klasörde kalıyordu.
- Elle boyutlandırma orijinali kaybetmiyor:
  - İlk boyutlandırmada `-erdo-original` yedeği alınıyor; yeniden adlandırma ve karantina da bu yedeği birlikte taşıyor.
  - "Orijinal boyuta dön" butonu eklendi.
  - Büyütme ve aynı boyuta boyutlandırma reddediliyor.
- `uninstall.php`:
  - Karantinadan geri taşıma başarısız olursa kopyala + sil deneniyor; yol kontrolü yapılıyor.
  - Ayarlardan açık tercih: WebP/AVIF kopyaları silinsin mi kalsın mı, karantinadakiler geri konsun mu kalıcı silinsin mi.
- i18n:
  - admin.js'teki ~80 metin `t()` ile PHP `__()` eşlemesinden geliyor; testi var.
  - Kalan sabit PHP metinleri (`wp_send_json_error`, `WP_Error`, menü) çevrilebilir.
  - `languages/erdo-image-optimizer.pot` üretildi; Türkçe `.po/.mo` 363/363 çevrildi.
- XSS: önce/sonra görünümündeki URL'ler kaçışlanıyor.
- Test: `65-hardening` (13 test).

**Faz 8'e kalan:** readme hâlâ BunnyCDN, SVG ve lazy load diyor; tam yeniden yazım orada yapılacak.

Plandaki maddeler (referans):
- Tüm AJAX/REST uçlarında nonce + yetki. Yeniden adlandırmada yol geçişi (path traversal) ve dosya adı doğrulaması.
- Dosya işlemleri yalnızca uploads dizini içinde yapılır (`realpath` kontrolü).
- Plugin Check + PHPCS temiz (bilinen `mismatched_plugin_name` uyarısı hariç).
- i18n: JS dizgeleri dahil tüm metinler çevrilebilir; Türkçe `.po/.mo` güncellenir.
- `uninstall.php`: tablo, meta, ayarlar; kardeş ve karantina dosyaları için açık bir tercih.
- Yükseltme rutini: `erdo_db_version`, geçiş bildirimi, onarım sihirbazına yönlendirme.

### Faz 7 — Arayüz yeniden tasarımı ✅ (2026-10-25)
**Teslim edildi:**
- Maket `docs/mockups/faz7-ui.html` (Stitch MCP kimlik doğrulama hatası verdiği için HTML olarak yapıldı), kullanıcı onayladı.
- Beş sekme (`?tab=`): Genel Bakış (sağlık puanı, 4 metrik, "Sıradaki düzeltmeler", sunucu kartı, son işlemler), Kütüphane (ızgara + yeni liste görünümü, puan çipleri, sorun filtresi, istemci tarafı arama/sıralama, seçim çubuğu), Temizlik (kullanılmayanlar + karantina, "Tümünü geri yükle"), AI Önerileri (mevcut ↔ önerilen, alan bazında onay), Ayarlar (kendi sekmesi, 7 bölüm, her ayarda tek cümle açıklama, desteklenmeyen seçenek nedeniyle pasif).
- Görsel detayı sağdan açılan panel: ←/→ ile gezinme, Esc, odak tuzağı.
- Ortak diyalog: önizleme → onay → ilerleme → sonuç + geri al. Dönüştürme kuyruğu pencere açıkken tarayıcıdan da ilerletiliyor (kilitli), son dönüştürme geri alınabiliyor.
- İlk açılış sihirbazı: gerçek deneme kodlaması, CDN/SEO eklentisi/AI tespiti → önerilen ayarlar → kullanım dizini.
- E6'nın temeli: `ERDO_Activity` işlem günlüğü + Genel Bakış'tan geri alma.
- Bildirimler `wp-header-end` altında (sıkışma bitti); karantina onayı sakin, yalnızca kalıcı silme kırmızı.
- WP admin renk şemasına uyum (`--wp-admin-theme-color`), puanlar hep sayıyla, dar ekran düzeni, tr_TR 0 eksik.

**Faz sırasında bulunan ek sorunlar (düzeltildi, testleri var):**
- Windows'ta karantina geri yüklenemiyordu: `update_post_meta()` yol değerlerindeki ters eğik çizgileri siliyordu (`C:\Users…` → `C:Users…`). Faz 4'teki "dosyalar karantinada kaldı" olayının nedeni buydu. Yollar artık normalize saklanıyor, eski kayıtlar `{id}-*/dosya` aramasıyla kurtarılıyor (uninstall dahil). Aynı düzeltme `_erdo_legacy_files` için de yapıldı.
- Yeniden adlandırmayı geri almak "temiz olmayan" eski adı (ör. `DSC_0193`) geri getirmiyordu; geri alma artık eski adı olduğu gibi kullanıyor.

Plandaki maddeler (referans):
- **Bilgi mimarisi (sekmeler):**
  - Genel Bakış: site görsel sağlık puanı + "şimdi düzelt" görev kartları.
  - Kütüphane: ızgara/liste, filtreler, çoklu seçim, toplu işlem çubuğu.
  - Temizlik: kullanılmayanlar, karantina.
  - AI Önerileri.
  - Ayarlar: sekmeli, her ayarın altında bir cümle açıklama.
- **İlk açılış sihirbazı:** ortam kontrolü (gerçek WebP/AVIF testi, Apache/nginx, CDN/Cloudflare tespiti) → önerilen ayarlar tek tıkla → ilk tarama raporu.
- **Her toplu işlem aynı akışı izler:** önizleme → onay → ilerleme (kuyruk) → sonuç + geri al.
- Güvenli varsayılanlar.
- WordPress admin görsel diliyle uyumlu, WCAG AA, klavye erişimi, dar ekran desteği. Karanlık admin temasıyla çakışmaz.
- **Süreç:** `ui-ux-pro-max` / `frontend-design` ile tasarım sistemi → Stitch maketleri → **kullanıcı onayı** → kodlama. Mevcut kalıplar (dropdown dışarı tıklayınca kapanır, önce/sonra kaydırıcısında container sürükleme) korunur.

### Faz 8 — Görsel kimlik ve wp.org vitrini ✅ (2026-10-28)
**Teslim edildi:**
- İkon C1 (kobalt): `assets/icon.svg` (animasyonlu) + 128/256 PNG (dinlenme karesi). Kaynaklar ve notlar `docs/brand/`.
- Banner 772x250 + 1544x500, kaynağı `docs/brand/banner/banner.html` (önce 31 → sonra 94 hikâyesi).
- Admin başlığındaki "e" yerine yeni işaret (`includes/views/logo.php`, satır içi SVG).
- 8 ekran görüntüsü (1440x900, demo sitesinden), sıra readme'deki başlıklarla aynı.
- Live Preview blueprint'i (`assets/blueprints/`), readme 3.0, GitHub README 3.0.

**Ekran görüntüsü alırken bulunan sorunlar (düzeltildi):**
- Kütüphanede "Loading library..." yazısı hiç kaybolmuyordu (bileşenin `display` kuralı core `.hidden`'ı eziyordu).
- "Kamera adı" etiketi alt çizgili adlarda da çıkıyordu → "Zayıf dosya adı".
- "Dosya adlarını düzelt" kamera adlarını yalnızca temizliyordu (`IMG_4821` → `img-4821`, yine anlamsız). Artık AI önerisi, geçerli alt metin, başlık ya da görselin kullanıldığı sayfanın odak kelimesi/başlığından ad öneriliyor (testli).
- Ayarlar'daki "Önerilen" rozeti sihirbazın önerisiyle çelişiyordu; artık aynı kural.

Plandaki maddeler (referans):
- Yeni ikon (`icon.svg` + 128/256 PNG) ve banner (772x250 + 1544x500). Önce 2–3 yön sunulur, onaydan sonra üretilir.
- 6–8 ekran görüntüsü: Studio'da gerçekçi demo içerikle, yeni arayüzden (genel bakış, kütüphane, AI önerileri, temizlik/kullanım haritası, yeniden adlandırma önizleme, ayarlar).
- Playground **Live Preview** blueprint'i (`assets/blueprints/blueprint.json`) + demo içerik.
- Readme baştan yazılır:
  - Dürüst iddialar, ≤150 karakter kısa açıklama, en fazla 5 etiket.
  - External services bölümü, net SSS.
  - Uzun başlık kararı (bilinen uyarı) korunur ama anahtar kelime doldurma azaltılır.
- GitHub README güncellenir.

### Faz 9 — Doğrulama matrisi ve 3.0.0 yayını ✅ yerel kısım (2026-10-28)
**Teslim edildi:**
- Docker matrisi (`tests/docker/`): PHP 7.4 / 8.1 / 8.3; WP 6.5 / 6.9 / 7.1; yalnızca GD / Imagick; Apache `.htaccess` / nginx (eklentinin kendi snippet'i) / multisite. Tüm paketler her ortamda yeşil (yalnızca ortamın desteklemediği AVIF gibi testler atlanıyor).
- Gerçek 2.2.2 → 3.0 yükseltmesi (`upgrade.ps1`): 2.2.2 görselleri gerçekten bozucu biçimde çevirir, 3.0 ilk yüklemede sayar, onarımdan önce sayfalar kırık görünmez, `wp erdo migrate` sonrası yedekli görsel JPEG'e döner, diğerlerinin bağlantıları düzelir.
- 5.000 görselde performans (Studio): indeks 6,2 sn, soğuk tarama 6,2 ms/görsel, ızgara 1 ms.
- WP 7.1 tarayıcıda görsel işleme (Chromium, blok editör): create → boyut başına sideload → finalize; her boyutun WebP kopyası oluştu.
- Plugin Check temiz (bilinen `mismatched_plugin_name` hariç). Sürüm 3 yerde 3.0.0, changelog, `tags/3.0.0`, kurulum zip'i (Linux izinleriyle) test edildi.

**Matriste bulunan sorunlar (düzeltildi, testli):**
- nginx snippet'i hiç çalışmıyordu: `location` içindeki `if`, `try_files`'ı devre dışı bırakıyordu. `set`/`if` sunucu düzeyine alındı, eksik dosya WordPress'e düşüyor (eski URL'ler 301 alır), AVIF türü açıkça veriliyor.
- Multisite + Apache'de yeniden adlandırılmış görsellerin eski URL'leri 404 veriyordu (core'un multisite kuralları eksik dosyayı WordPress'e göndermiyor). Uploads `.htaccess`'ine ayrı bir "Erdo Redirects" bloğu yazılıyor.

**Kalan (kullanıcı onayıyla):** GitHub'a merge/push, SVN trunk kopyası + TortoiseSVN commit/tag, LiteSpeed/gerçek hosting kontrolü (SSH staging), 4 yüzey doğrulaması.

Plandaki maddeler (referans):
- **Matris:**
  - PHP 7.4 / 8.1 / 8.3; WP 6.5 / 6.9 / 7.1.
  - Yalnızca GD / Imagick.
  - Sunum: `<picture>` / `.htaccess` / nginx metni.
  - 7.1 tarayıcıda görsel işleme ile yükleme (Chromium).
  - Multisite.
  - 2.2.2 → 3.0 yükseltme (yıkıcı biçimde çevrilmiş görsellerle).
  - 5K görselde performans.
- Sürüm numarası 3 yerde birlikte artırılır + changelog + `tags/3.0.0`.
- SVN kopyası yalnızca dosya bazında ve açık onayla yapılır; commit ve tag kullanıcıda (TortoiseSVN).
- 4 yüzey doğrulaması.

### Ek özellikler (2026-10-24 — kullanıcı onayladı, hepsi 3.0'a giriyor)
**3.0 kapsamı:**
- **E1. Kullanıcının çalıştığı yerde olmak.**
  - Core Medya Kütüphanesi'ne (liste + ızgara + görsel detayı) Erdo puanı ve "AI alt metin öner" butonu.
  - Blok editörde Image bloğu yan panelinde puan ve öneri.
  - Build adımı gerektirmez; `wp.plugins` / `wp.blockEditor` global'leri düz JS ile kullanılır.
- **E2. Yayın öncesi görsel kontrolü.** Gutenberg yayın öncesi paneli (pre-publish) ve klasik editör meta kutusunda: alt metni eksik, aşırı büyük, kamera adlı veya modern formatı olmayan görseller listelenir, tek tıkla düzeltilir.
- **E3. Birebir kopya görsel tespiti.** Dosya hash'iyle, yerel ve ücretsiz. Kopyalar tek görselde birleştirilir, referanslar güncellenir, fazlalar karantinaya alınır.
- **E4. WooCommerce desteği.**
  - Kullanım indeksine ürün görseli ve galeri dahil edilir.
  - AI bağlamına ürün adı ve kategori eklenir.
  - "Ürün görsellerinin SEO durumu" filtresi.
- **E5. Site Health entegrasyonu.** WordPress'in Site Sağlığı ekranına testler eklenir: WebP/AVIF desteği, alt metni eksik görsel oranı, kırık görsel referansı, sunum kurallarının durumu.
- **E6. İşlem günlüğü ve tek noktadan geri alma.** Toplu yeniden adlandırma, karantina, dönüştürme ve AI kabul işlemleri kaydedilir; her işlem geri alınabilir.
- **E7. Abilities API kaydı (WP 6.9+).** "Görseli denetle", "alt metin öner", "kullanılmayanları listele" yetenekleri kaydedilir. Böylece AI ajanları ve MCP adaptörü Erdo'yu çağırabilir. Maliyeti düşük, "agent-ready" konumlandırmasına katkı sağlar.

**3.x'e ertelenenler:**
- Sayfa bazlı görsel raporu (URL ver → ağırlık, LCP adayı, eksik `width`/`height`, `fetchpriority`).
- Yakın kopya tespiti (perceptual hash).
- Alt metnin dile göre çevrilmesi (WPML/Polylang).
- Lisans ve yaratıcı yapılandırılmış verisi (IPTC/ImageObject).
- Haftalık e-posta sağlık raporu.
- Kendi AI HTTP istemcisi (WP < 7.0 için).

### Yayın sonrası
- Destek forumuna hızlı yanıt (çözülme oranı sıralamayı etkiliyor).
- Başarılı bir toplu işlemden sonra kapatılabilir, tek seferlik yorum isteği (Guideline 11 uyumlu).
- 3.x yol haritası: kendi HTTP istemcisi (talep olursa), yakın kopya görsel tespiti (yerel perceptual hash), lisans ve telif yapılandırılmış verisi.

---

## 4. Kullanılacak ajanlar ve skill'ler
- Araştırma: `general-purpose` ajanları (yapıldı).
- Kod: `wordpress-pro`, `wp-plugin-development`, `php-security-patterns`.
- İnceleme: `code-review` (her faz sonu), `security-review` (Faz 6).
- Tasarım: `ui-ux-pro-max`, `frontend-design`, Stitch MCP.
- Görsel üretim: `impeccable-asset-producer`.
- Vitrin: `blueprint` (Playground), `copywriting` / `copy-editing` (readme).
- Test: Studio `wp eval-file` + Playwright / tarayıcı paneli.

## 5. Kaynaklar
- Google Images SEO: https://developers.google.com/search/docs/appearance/google-images
- Görsel site haritaları: https://developers.google.com/search/docs/crawling-indexing/sitemaps/image-sitemaps
- LCP: https://web.dev/articles/optimize-lcp
- WP 6.5 AVIF: https://make.wordpress.org/core/2024/02/23/wordpress-6-5-adds-avif-support/
- WP 6.3 görsel performansı: https://make.wordpress.org/core/2023/07/13/image-performance-enhancements-in-wordpress-6-3/
- WP 7.1 client-side media: https://make.wordpress.org/core/2026/07/22/client-side-media-processing-in-wordpress-7-1/
- WP 7.0 AI Client: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
- WP 7.0 Connectors: https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/
- AI eklentisi örneği: https://developer.wordpress.org/news/2026/07/build-your-first-ai-powered-wordpress-plugin/
- Eklenti kuralları: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Vitrin görselleri: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- Live Preview: https://developer.wordpress.org/plugins/wordpress-org/previews-and-blueprints/
- W3C alt metin karar ağacı: https://www.w3.org/WAI/tutorials/images/decision-tree/
- EWWW WebP: https://docs.ewww.io/article/16-ewww-io-and-webp-images
- Media Cleaner: https://wordpress.org/plugins/media-cleaner/
- Resmi AI eklentisi: https://wordpress.org/plugins/ai/
