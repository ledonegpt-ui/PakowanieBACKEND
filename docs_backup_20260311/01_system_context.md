# 01. Kontekst systemu

## Status
POTWIERDZONE

## Cel systemu
Backend do obsługi magazynowego procesu pakowania i wysyłki zamówień.
Klientem jest aplikacja tabletowa Kotlin — system jest API-first.

Flow:
1. Import zamówień z zewnętrznych systemów (dzieje się automatycznie)
2. Logowanie operatora przez skan kodu kreskowego
3. Wybór grupy kurierskiej (kafelek)
4. Kompletacja produktów (picking)
5. Pakowanie zamówienia (packing)
6. Generowanie etykiety przez API kuriera
7. Wydruk etykiety na drukarce stanowiskowej
8. Zapis zdarzeń operacyjnych

## Środowisko techniczne
- PHP 7.2.24 (bez Laravela/Symfony, bez Composera)
- MySQL 5.7.42, baza: `admin_pakowanie`
- Serwer HTTP z rewrite do `/api/v1`
- Aplikacja docelowa: tablet Android / Kotlin

## Stan projektu (2026-03-11)
- Stary system przeglądarkowy przeniesiony do `starysystem/` — nie rozwijamy
- Nowy backend API-first — aktywny projekt
- Picking — gotowy i domknięty
- Packing — szkielet gotowy, etykiety do domknięcia
- Shipping / etykiety — prototyp, główne zadanie do wykonania

## Zachowane elementy krytyczne (nie ruszać)
- `app/bootstrap.php`
- `app/Lib/Db.php`
- `app/Services/ImportState.php`
- `app/Services/ImporterMasterV2.php`
- `app/Services/BaselinkerBatchReader.php`
- `app/Services/SubiektReaderV2.php`
- `app/Services/FirebirdEUReader.php`
- `app/Services/OrderRepositoryV2.php`
- `app/Services/LegacyAuctionPhotoMap.php`
- `bin/import_orders_master_v2.php`

## Źródła importu zamówień
- BaseLinker (marketplace aggregator)
- Subiekt / MSSQL (ERP)
- Firebird EU (system magazynowy)

## Zewnętrzne integracje kurierskie
| Kurier | Provider | Stan adaptera |
|---|---|---|
| DPD (własny kontrakt) | dpd_api → dpd_contract | ⚠️ zaimplementowany, bug XML |
| DPD przez Allegro | allegro_api → allegro | ❌ stub, brak credentials |
| InPost ShipX | inpost_shipx | ❌ stub, brak credentials |
| GLS | gls_api → gls | ❌ stub, credentials są |
| DHL | dhl_api | ❌ brak adaptera w ogóle |
| ORLEN/Packeta | orlen_api | ❌ brak adaptera w ogóle |
| ERLI (przez BaseLinker) | baselinker_api → baselinker | ❌ stub, credentials są |
| Allegro One | allegro_api → allegro | ❌ stub, brak credentials |

## Kluczowe zasady
- `menu_group` != `label_provider` — zawsze przez resolver
- `delivery_method` z importu nie wystarcza — zawsze przez resolver
- Identyfikator zamówienia w nowym flow = `order_code`
- Stary system w `starysystem/` nie jest wiążący dla nowego API
