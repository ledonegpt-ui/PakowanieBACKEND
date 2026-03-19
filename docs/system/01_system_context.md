# 01. Kontekst systemu

## Status
POTWIERDZONE + PLANOWANE

## Cel systemu
Celem systemu jest obsługa procesu:
- importu zamówień do bazy lokalnej,
- logowania operatora,
- wyboru stanowiska,
- wyboru grupy kurierskiej,
- kompletacji produktów,
- pakowania zamówienia,
- wygenerowania listu przewozowego i etykiety,
- wydruku etykiety,
- zapisania zdarzeń operacyjnych.

## Środowisko techniczne
POTWIERDZONE:
- PHP 7.2
- MySQL 5.7
- serwer HTTP z rewrite do `/api/v1`
- aplikacja docelowa: tablet / Kotlin
- projekt działa bez nowoczesnego frameworka typu Laravel/Symfony
- trzeba zachować kompatybilność z PHP 7.2

## Obecny stan projektu
POTWIERDZONE:
- aktywny projekt został odchudzony
- stary system został przeniesiony do `starysystem/`
- w aktywnym projekcie zostawiono:
  - bootstrap,
  - konfigurację,
  - DB layer,
  - importer i repo importowe,
  - nową strukturę katalogów API/module,
  - nowe migracje dla auth/shipping/picking/packing/audit

## Zachowane elementy krytyczne
POTWIERDZONE:
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

## Zewnętrzne integracje
POTWIERDZONE lub PLANOWANE:
- BaseLinker
- Allegro API
- InPost ShipX
- DPD API
- DHL API
- GLS API
- ORLEN / Packeta API
- źródła importu:
  - MSSQL / Subiekt
  - Firebird EU
  - BaseLinker

## Docelowy tryb działania
PLANOWANE:
- dziś: tryb zintegrowany `integrated`
  - ten sam operator może kompletować i pakować
- przyszłość: rozdzielenie ról
  - komisjoner zbiera,
  - pakowacz pakuje

## Kluczowa zasada dla nowych programistów
Nie wolno zakładać, że:
- `menu_group == label_provider`
- `delivery_method` samo w sobie wystarcza do logiki etykiety
- stare statusy `pak_orders.status` są już finalnie uzgodnione
- logika starego systemu przeglądarkowego jest wiążąca dla nowego API
