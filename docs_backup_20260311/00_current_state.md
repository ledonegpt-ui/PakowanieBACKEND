# Stan aktualny systemu — po sesji onboardingowej 2026-03-11

## Status
POTWIERDZONE

## Co działa i jest gotowe

### Foundation
- `app/bootstrap.php` — konfiguracja, ładowanie środowiska
- `app/Lib/Db.php` — PDO wrapper, `Db::mysql($cfg)`
- `app/Support/ApiResponse.php` — standardowy JSON response
- `app/Support/Request.php` — parsowanie żądań
- `app/Support/Route.php` — prosty router
- `app/Support/AuthMiddleware.php` — bearer token middleware
- `api/v1/index.php` — główny router, wszystkie trasy zarejestrowane

### Auth / Stations
- `POST /api/v1/auth/login` — logowanie przez barcode + station_code, zwraca bearer token
- `GET /api/v1/auth/me` — dane zalogowanego operatora
- `POST /api/v1/auth/logout`
- `GET /api/v1/stations` — lista aktywnych stanowisk
- Sesje operatorów w tabeli `user_station_sessions`
- Bearer token middleware działa, chronione endpointy bez tokenu zwracają 401

### Carriers / Resolver
- `GET /api/v1/carriers` — lista grup kafelków kurierskich
- `POST /api/v1/shipping/resolve-method` — diagnostyczny endpoint resolvera
- `app/Support/ShippingMethodResolver.php` — działa poprawnie
- `app/Config/shipping_map.php` — pełna mapa reguł, priorytety naprawione 2026-03-11

### Picking — GOTOWY I DOMKNIĘTY
Kompletny flow, nie ruszać bez powodu.
Wszystkie endpointy działają: open/current/show/orders/products/picked/missing/drop/refill/close/abandon.
Transakcje z SELECT FOR UPDATE, agregaty, heartbeat, cron abandon, event log.

### Packing — SZKIELET GOTOWY
- open/show/cancel/finish zaimplementowane
- Blokady ownership sesji działają
- Sprawdzenie zakończonego pickingu przed otwarciem packingu działa
- finish wymaga wcześniej wygenerowanej etykiety (package + label w DB)

### Shipping / Etykiety — PROTOTYP, NIEDOMKNIĘTY
- `ShippingController::generateLabel()` — logika zaimplementowana w kontrolerze
- `ShippingAdapterFactory` — factory działa
- `DpdAdapter` — zaimplementowany, ale ma bug XML (patrz niżej)
- Pozostałe adaptery — tylko stub (`throw not implemented`)
- `ShippingService` — PUSTY
- `ShippingRepository` — PUSTY
- Do zrobienia: refaktor logiki z kontrolera do Service/Repository

## Baza danych
- MySQL 5.7.42, baza `admin_pakowanie`
- Wszystkie nowe tabele workflow istnieją (migracje wykonane)
- Tabele importu `pak_orders` i `pak_order_items` nienaruszone

## Zewnętrzne credentials w shipping_providers
| provider_code | Stan |
|---|---|
| `dpd_contract` | ✅ wpisane (wsdl, login, master_fid, password) |
| `baselinker` | ✅ token wygląda realnie |
| `gls` | ✅ wsdl i dane wpisane |
| `inpost_shipx` | ⚠️ token = CHANGE_ME — wymaga uzupełnienia |
| `allegro` | ⚠️ token = CHANGE_ME — wymaga uzupełnienia |

## Znany bug — DpdAdapter (2026-03-11)
Adapter generuje błąd `Premature end of file` po stronie DPD SOAP.
Diagnoza: problem ze strukturą XML — `xmlns=""` na węzłach wewnętrznych powoduje
reset namespace i DPD parser gubi zawartość. Temat przekazany osobnemu developerowi.
Fix: usunąć `xmlns=""` z węzłów `openUMLFeV5`, `pkgNumsGenerationPolicyV1`, `langCode`, `authDataV1`.
