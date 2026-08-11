# moserra/query-watchdog

[English](README.md) | Türkçe

[Nette](https://nette.org) uygulamaları için çalışma zamanı sorgu bekçisi. Statik analizin göremediği sorgu problemlerini sayfa gerçekten çalışırken yakalar:

- **Tekrarlı SELECT / N+1 dedektörü** — her sorgu, literal'leri `?`'ye normalize edilmiş parmak iziyle sayılır; yani `WHERE id = 5` ile `WHERE id = 8` *aynı kalıp* sayılır. Aynı kalıp bir request'te N kez koşarsa (varsayılan 5), debug modda request **exception ile patlar** — mesajda kalıp, örnek sorgu ve çözüm ipucu (IN-list/JOIN ile batch'le ya da memoize et) yazar. Production'da bunun yerine structured Tracy log'u yazılır.
- **Birebir-tekrar (exact) dedektörü** — literal'ler **korunur** (yalnız boşluk normalize edilir); yani *birebir aynı* SQL — hem kalıp hem değerler aynı — iki kez koşarsa dönen satırlar da aynıdır: saf memoize eksikliği. Düşük eşik (varsayılan 2), yüksek isabet: `WHERE id = 1` ile `WHERE id = 2` asla çakışmaz. Kalıp kuralının (limit 5) kaçırdığı eşik-altı tekrarları yakalar — ör. iki kod yolunun aynı id'ler için aynı lookup'ı çekmesi.
- **Request başına sorgu bütçesi** — bir request'te N sorgudan fazlası (varsayılan 80) throw/log — en çok tekrar eden kalıplar listelenir.
- **Yavaş sorgu log'u** — eşiği aşan sorgular (varsayılan 200 ms) iki modda da log'lanır. Asla throw etmez: süre deterministik değildir (soğuk cache), yavaş bir sorgu rastgele sayfa öldürmemeli.

Transaction kontrol ifadeleri (`BEGIN`, `COMMIT`, `SET`, `EXPLAIN`, …) sayılmaz. Tekrar kuralı yalnız SELECT'lere uygulanır.

### Birebir-tekrar kuralının geri çekildiği yerler

Kural *aynı SQL → aynı satırlar* varsayar. Bu varsayımın tutmadığı iki durum var; ikisi de karşılanıyor:

- **Arada yazma olmuşsa.** "Oku → yaz → yeniden oku" meşru bir sıradır; ikinci okuma gerçekten başka satır döndürür. SELECT olmayan her ifade (işlem defteri hariç) yeni bir kuşak açar, tekrar sayımı sıfırdan başlar. CTE ile başlayan salt okuma (`WITH … SELECT`) burada yazma sayılır: bu kuralı yalnız gevşetir, yanlış rapor üretemez. **Kalıp** kuralı kuşaktan kasten etkilenmez — döngü içinde "satırı güncelle, satırı oku" hâlâ N+1'dir.
- **İfade deterministik değilse.** Kilitleyen okumalar (`FOR UPDATE`/`FOR SHARE`, `SKIP LOCKED`, advisory kilitler), dizi okumaları (`nextval`) ve `random()` muaftır: amaç kilit ya da yan etkidir ve `SKIP LOCKED` tasarımı gereği her çağrıda *başka* satır döndürür. `now()` muaf **değildir** — PostgreSQL'de işlem başlangıç zamanıdır, işlem içinde sabittir.

## Neden

Statik gate'ler (PHPStan kuralları, copy-paste dedektörleri) aynı fonksiyondaki döngü-içi sorguyu yakalar. Servis çağrısının arkasına gizlenmiş döngüyü ya da beş ayrı component'in aynı settings satırını ayrı ayrı çekmesini göremez. Tracy panelin gösterir — ama kimse panele bakarak build kırmaz. Bu paket "Tracy'de fark et"i "sayfa, sorgu ve sayaçla birlikte throw eder"e çevirir.

## Desteklenen veritabanı katmanları

İki köprü de DI container'daki tüm connection servislerine (alt sınıflar dahil) otomatik bağlanır:

- **nette/database** — `Connection::$onQuery` event'i ile
- **nextras/dbal** — `Connection::addLogger()` ile

## Kurulum

```bash
composer config repositories.moserra-query-watchdog vcs https://github.com/iatila/query-watchdog
composer require moserra/query-watchdog
```

NEON config'e extension'ı kaydet:

```neon
extensions:
	queryWatchdog: Moserra\QueryWatchdog\DI\QueryWatchdogExtension
```

Bu kadar. Opsiyonel ayarlar (varsayılanlar gösterildi):

```neon
queryWatchdog:
	budgetPerRequest: 80
	duplicateSelectLimit: 5      # aynı KALIP (literal→?) N× → N+1
	exactDuplicateLimit: 2       # BİREBİR aynı SQL (literaller korunur) N× → memoize eksikliği
	slowQueryMs: 200
	# strict: true    # verilmezse %debugMode% izlenir (dev = throw, prod = log)
```

## İhlal nasıl görünür

Debug modda request `Moserra\QueryWatchdog\QueryWatchdogException` ile ölür:

```
Duplicate SELECT: same query shape ran 5× in one request — batch it (IN-list/JOIN) or memoize the result.
Shape: SELECT * FROM orders WHERE customer_id = ?
Example: SELECT * FROM orders WHERE customer_id = 42
```

Production'da aynı bilgi Tracy log'una structured kayıt olarak düşer
(`query_watchdog.violation` / `query_watchdog.slow_query`) — exception yok, kullanıcı etkilenmez.

## Ayar

Bir sayfanın gerçekten daha fazlasına ihtiyacı varsa limitleri config'ten yükselt — ama bunu son çare say. İhlal mesajı hangi sorguyu batch'leyeceğini ya da memoize edeceğini söylüyor; onu düzeltmek neredeyse her zaman tavanı yükseltmekten ucuzdur.

## Lisans

MIT

## Toplu işlerde susturma

Kurallar "bir istek" varsayımına dayanır: aynı SELECT'i tekrarlamak, istek bütçesini
aşmak bir istekte kokudur. Tohumlayıcı/içe aktarma gibi toplu işler bunu bilerek yapar
(kayıt başına kod serisi okuma, kayıt başına doğrulama) — orada uyarı yanlış alarmdır,
`strict` kipte üstelik işi durdurur.

```php
$watchdog->suspend();   // konsol uygulamasının açılışında
$watchdog->resume();    // sayaçları da sıfırlar
```
