# moserra/query-watchdog

[English](README.md) | Türkçe

[Nette](https://nette.org) uygulamaları için çalışma zamanı sorgu bekçisi. Statik analizin göremediği sorgu problemlerini sayfa gerçekten çalışırken yakalar:

- **Tekrarlı SELECT / N+1 dedektörü** — her sorgu, literal'leri `?`'ye normalize edilmiş parmak iziyle sayılır; yani `WHERE id = 5` ile `WHERE id = 8` *aynı kalıp* sayılır. Aynı kalıp bir request'te N kez koşarsa (varsayılan 5), debug modda request **exception ile patlar** — mesajda kalıp, örnek sorgu ve çözüm ipucu (IN-list/JOIN ile batch'le ya da memoize et) yazar. Production'da bunun yerine structured Tracy log'u yazılır.
- **Request başına sorgu bütçesi** — bir request'te N sorgudan fazlası (varsayılan 80) throw/log — en çok tekrar eden kalıplar listelenir.
- **Yavaş sorgu log'u** — eşiği aşan sorgular (varsayılan 200 ms) iki modda da log'lanır. Asla throw etmez: süre deterministik değildir (soğuk cache), yavaş bir sorgu rastgele sayfa öldürmemeli.

Transaction kontrol ifadeleri (`BEGIN`, `COMMIT`, `SET`, `EXPLAIN`, …) sayılmaz. Tekrar kuralı yalnız SELECT'lere uygulanır.

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
	duplicateSelectLimit: 5
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
