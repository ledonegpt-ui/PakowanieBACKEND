# Backend Workstreams — aktualizacja 2026-03-12 (sesja 3)

## Zrobione w sesji 3 (2026-03-12)
- ✅ `PICKING_BATCH_SIZE` — konfigurowalny rozmiar batcha przez `.env`
- ✅ `ShippingService::generateLabel()` — wyodrębniona logika z kontrolera
- ✅ `PackingController::finish()` — generuje etykietę + drukuje przed zamknięciem sesji
- ✅ `PackingService::finishSession()` — zwraca `next_order_code`, `batch_completed`, `carrier_key`
- ✅ `PackingRepository` — dodane `findNextBatchOrder()` i `findBatchCarrierKey()`
- ✅ `AllegroTokenProvider` — token z MYSQL2 (allegro_accounts)
- ✅ `PackingRepository` — poprawiony błąd składni (metody były poza klasą)

## Zrobione w sesji 2 (2026-03-11)
- ✅ Resolver — naprawione wszystkie błędy mapowania
- ✅ InPostAdapter — paczkomat + kurier + COD + Smart + service dynamiczny
- ✅ GlsAdapter — SOAP ADE API, COD, ZPL, reprint
- ✅ DpdAdapter — ObjServices SOAP, COD, PDF
- ✅ AllegroAdapter — wszystkie metody, COD, punkty odbioru, zagraniczne
- ✅ BaseLinkerAdapter — ERLI przez BL API, createPackage + getLabel
- ✅ ZebraPrinter — drukowanie przez CUPS
- ✅ ShippingController — requires_size flow, automatyczny wydruk
- ✅ pickup_point_id w bazie i findOrder()

## Do zrobienia
- Testy end-to-end z tabletu (pełny flow picking → packing → label → print → next)
- `ShippingRepository` — pusty, nie blokuje działania
- `picking_batch_orders` — tabela pusta, picking nie przypisuje jeszcze zamówień do batchy
