# Moduły backendu — stan i cele

## Foundation
- POTWIERDZONE: bootstrap, DB, ApiResponse, Request, Route, AuthMiddleware — gotowe

## Import
- POTWIERDZONE: importer działa, nie ruszać
- Pliki krytyczne: ImporterMasterV2, BaselinkerBatchReader, SubiektReaderV2, FirebirdEUReader, OrderRepositoryV2
- PLANOWANE: późniejsze dopasowanie pól importowanych do nowego modelu workflow

## Auth / Stations
- POTWIERDZONE: login przez barcode + station_code, bearer token, sesje — gotowe
- POTWIERDZONE: tabele users, user_roles, stations, user_station_sessions istnieją

## Carriers / Resolver
- POTWIERDZONE: ShippingMethodResolver działa
- POTWIERDZONE: shipping_map.php z pełnymi regułami, priorytety poprawne od 2026-03-11
- Kluczowe: menu_group != label_provider — resolver rozdziela te pojęcia

## Picking
- POTWIERDZONE: kompletny, domknięty, nie ruszać

## Packing
- POTWIERDZONE: szkielet gotowy (open/show/cancel/finish)
- PLANOWANE: domknięcie finish po działających etykietach

## Shipping / Etykiety
- POTWIERDZONE: ShippingAdapterFactory działa
- POTWIERDZONE: DpdAdapter zaimplementowany (bug XML do naprawy)
- PLANOWANE: GlsAdapter — ma credentials w DB, do implementacji
- PLANOWANE: BaseLinkerAdapter — ma token w DB, do implementacji (używany przez ERLI)
- DECYZJA_WYMAGANA: AllegroAdapter — token CHANGE_ME, najpierw uzupełnić credentials
- DECYZJA_WYMAGANA: InPostAdapter — token CHANGE_ME, najpierw uzupełnić credentials
- PLANOWANE: refaktor logiki z ShippingController do ShippingService + ShippingRepository

## Events / Audit
- POTWIERDZONE: picking_events działa
- POTWIERDZONE: packing_events działa
- POTWIERDZONE: tabele order_events, api_request_logs, workflow_errors istnieją

## Korekta 2026-03-13 — Picking
- POTWIERDZONE: `picking_batch_orders` jest aktywnie używana przez system
- POTWIERDZONE: picking operuje na lokalnej kopii pozycji zamówienia
- POTWIERDZONE: pozycje pickingu są subiektowe (`pak_order_items`), a nie równoległe source line'y EU/BL
- POTWIERDZONE: `offer_id` w `pak_order_items` jest kluczowym łącznikiem pozycji z marketplace
