# Shipping i generowanie etykiet

## Status
CZĘŚCIOWO ZAIMPLEMENTOWANE — aktualizacja 2026-03-11 (sesja 2)

## Kluczowa zasada — 4 pojęcia kurierskie
Nie wolno traktować tych pojęć jako równoważnych:

| Pojęcie | Gdzie | Co oznacza |
|---|---|---|
| `menu_group` | kafelek UI, `carrier_key` w picking_batches | Co widzi operator |
| `shipment_type` | wynik resolvera | Biznesowy typ przesyłki |
| `label_provider` | wynik resolvera | Przez jakie API generujemy etykietę |
| `label_endpoint` | wynik resolvera | Docelowy adapter backendowy |

## Przykłady gdzie menu_group != label_provider
| delivery_method | menu_group | label_provider |
|---|---|---|
| Kurier DPD pobranie | dpd | dpd_api |
| Allegro Kurier DPD pobranie (AD) | dpd | allegro_api |
| Allegro Automaty Paczkowe DPD (AD) | dpd | allegro_api |
| Allegro Automat DHL BOX 24/7 (AD) | dhl | allegro_api |
| Allegro Automat ORLEN Paczka | orlen | allegro_api |
| Allegro One Box, DPD | dpd | allegro_api |
| Allegro One Punkt, DPD | dpd | allegro_api |
| Allegro Paczkomaty InPost | inpost | inpost_shipx |
| ERLI InPost Paczkomaty | inpost | baselinker_api |
| ERLI DPD | dpd | baselinker_api |
| ERLI DHL | dhl | baselinker_api |

## Resolver — jak działa
Plik: `app/Support/ShippingMethodResolver.php`
Konfiguracja: `app/Config/shipping_map.php`

Resolver przyjmuje `delivery_method`, `carrier_code`, `courier_code` i zwraca:
- `menu_group` — pod jakim przyciskiem w UI
- `menu_label` — etykieta przycisku
- `shipment_type` — typ przesyłki
- `label_provider` — przez jakie API
- `label_endpoint` — metoda adaptera
- `requires_size` — czy wymagany rozmiar (paczkomat)
- `matched` — czy reguła dopasowana
- `matched_rule` — kod reguły która wygrała

Reguły mają priorytety — wygrywa reguła z najwyższym priorytetem.

| Zakres | Kto |
|---|---|
| 2000 | odbiór osobisty |
| 1000 | ERLI (zawsze przez baselinker) |
| 960 | Allegro One Box DPD, Allegro One Punkt DPD |
| 950 | Allegro International One |
| 920 | Allegro Paczkomaty InPost (→ inpost_shipx) |
| 900 | Allegro One default |
| 880 | Allegro DPD Pickup |
| 870 | Allegro DPD, Allegro DHL, Allegro ORLEN |
| 860 | Allegro InPost kurier |
| 850 | zwykły InPost paczkomat |
| 840 | zwykły InPost kurier |
| 830 | zwykły DPD Pickup |
| 821 | DPD sprzedający ustala |
| 820 | zwykły DPD kurier |
| 815 | GLS generic |
| 810 | GLS |
| 800 | DHL |
| 790 | ORLEN/Packeta |

Narzędzie diagnostyczne: `resolver_debug.php` (plik PHP w `public_html`) —
tabela wszystkich unikalnych kombinacji z wynikiem resolvera, filtry, DataTables.

## Adaptery — stan

| Adapter | Plik | Stan | Uwagi |
|---|---|---|---|
| InPostAdapter | Adapters/InPostAdapter.php | ✅ DZIAŁA | paczkomat + kurier, COD, Smart, service dynamiczny, ZPL przez ShipX API |
| DpdAdapter | Adapters/DpdAdapter.php | ✅ DZIAŁA | ObjServices SOAP, COD, PDF, przepisany z XmlServices |
| GlsAdapter | Adapters/GlsAdapter.php | ✅ DZIAŁA | SOAP ADE API, COD, ZPL, reprint |
| AllegroAdapter | Adapters/AllegroAdapter.php | ✅ DZIAŁA | wszystkie metody Allegro, COD, punkty odbioru, zagraniczne, ZPL |
| BaseLinkerAdapter | Adapters/BaseLinkerAdapter.php | ✅ DZIAŁA | createPackage + getLabel, ERLI InPost kurier + paczkomat, service dynamiczny, fallback reprint |

## Credentials

### InPost ShipX
- Token: `.env` → `INPOST_TOKEN`
- Org ID: `.env` → `INPOST_ORG_ID` (wartość: 12368)
- Adapter czyta: `getenv('INPOST_TOKEN')`, fallback `$providerCfg['token']`
- Base URL: `https://api-shipx-pl.easypack24.net/v1`

### GLS
- Tabela `shipping_providers`, `provider_code = 'gls'`, kolumna `config_json`
- Pola: `wsdl`, `username`, `password`
- WSDL: `https://adeplus.gls-poland.com/adeplus/pm1/ade_webapi2.php?wsdl`
- Format etykiety: `roll_160x100_zebra` (ZPL)

### DPD
- Tabela `shipping_providers`, `provider_code = 'dpd_contract'`
- Pola: `wsdl`, `login`, `master_fid`, `password`

### Allegro
- Tabela `shipping_providers`, `provider_code = 'allegro'`
- Token = CHANGE_ME — wymaga uzupełnienia przez ops

### BaseLinker (ERLI)
- Tabela `shipping_providers`, `provider_code = 'baselinker'`
- Pole: `token`

## Mapping label_provider → provider_code w DB
Zdefiniowany w `ShippingController::resolveProviderCode()`:
- `dpd_api` → `dpd_contract`
- `gls_api` → `gls`
- `inpost_shipx` / `inpost_api` → `inpost_shipx`
- `allegro_api` → `allegro`
- `baselinker_api` / `baselinker` → `baselinker`

## Flow generowania etykiety (aktualny)
```
POST /api/v1/shipping/orders/{orderId}/generate-label
Body: { "size": "B" }   ← opcjonalne, wymagane dla paczkomat
```

1. Sprawdza czy istnieje otwarta sesja packingu dla operatora
2. Pobiera zamówienie z `pak_orders` (w tym `pickup_point_id`)
3. Wywołuje resolver → dostaje `label_provider`, `requires_size`
4. Jeśli `requires_size=true` i brak `size` w body → zwraca `requires_size: true, size_options: [A,B,C]`
5. Tworzy rekord w `packages` (jeśli nie istnieje)
6. Pobiera config providera z `shipping_providers`
7. `ShippingAdapterFactory::make(label_provider)` → zwraca adapter
8. `adapter->generateLabel(order, package, resolved, providerCfg)` → zwraca wynik
9. Zapisuje tracking_number i status do `packages`
10. Tworzy rekord w `package_labels` z `file_path` i `file_token`
11. **Drukuje przez CUPS na `zebra_st{station_code}_raw`** ← NOWE
12. Loguje event `label_generated` do `packing_events`

## Drukowanie — ZebraPrinter
Plik: `app/Support/ZebraPrinter.php`
```php
ZebraPrinter::print($stationCode, $fullPath);
// wywołuje: lp -d zebra_st{N}_raw /path/to/label.zpl
```

- Drukarki w CUPS: `zebra_st1_raw` ... `zebra_st11_raw`
- `station_code` pochodzi z `currentSession['station_code']`
- Błąd drukowania jest logowany ale nie przerywa flow — etykieta jest już wygenerowana
- Etykiety zapisywane w: `storage/labels/`

## InPostAdapter — szczegóły

### Paczkomat
- Wymaga `pickup_point_id` w zamówieniu (np. `GWI15M`)
- Dane z `pak_orders.pickup_point_id` — zapisywane przez importer od 2026-03-11
- Service: `inpost_locker_allegro_smart`
- Rozmiar (`package_size`) z `$resolved['package_size']` → `template: small/medium/large`
- Mapowanie: A=small, B=medium, C=large

### Kurier
- Dostawa pod adres z `pak_orders`
- Service: `inpost_courier_allegro`
- Nie wymaga `pickup_point_id`

### Oba tryby
- Tworzą przesyłkę przez `POST /organizations/{org_id}/shipments`
- Pobierają `tracking_number` przez `GET /organizations/{org_id}/shipments?id={shipment_id}`
- Pobierają etykietę ZPL przez `GET /shipments/{id}/label?format=zpl`
- Zapisują ZPL do `storage/labels/inpost_{order_code}_{datetime}.zpl`


## AllegroAdapter — szczegóły (zaimplementowany 2026-03-12)

### Token
Pobierany przez `AllegroTokenProvider::getToken($cfg)` z bazy MYSQL2, tabela `allegro_accounts`, login `LED-ONE`.
Bot odświeża token codziennie — zawsze aktualny. NIE używać `$providerCfg`.

### Obsługiwane metody dostawy
- Allegro One Kurier
- Allegro One Box
- Allegro One Punkt
- DHL kurier + automat (POP BOX)
- DPD kurier + pickup
- ORLEN Paczka
- Express One
- Przesyłki zagraniczne (Węgry, Czechy i inne) — waluta dynamiczna per metoda
- Zamówienia z pobraniem (COD) — kwota i waluta z pak_orders
- **Wyjątek:** Allegro Paczkomaty InPost → obsługuje InPostAdapter (nie AllegroAdapter)

### Flow
1. Pobiera dane zamówienia z Allegro API (adres, email, telefon, punkt odbioru)
2. Sprawdza czy przesyłka już istnieje — jeśli tak, pobiera etykietę ponownie
3. Jeśli nie istnieje — tworzy przesyłkę, czeka na potwierdzenie, pobiera etykietę ZPL
4. Zapisuje tracking_number i external_shipment_id do packages
5. Drukowanie obsługuje ShippingController automatycznie

### Ważne niuanse API Allegro (odkryte metodą prób i błędów)
- Jednostki wymiarów: `CENTIMETER` (nie `CENTIMETERS`)
- Punkt odbioru paczkomatu: zwykły string, nie obiekt
- Numer przesyłki wraca na głównym poziomie odpowiedzi, nie zagnieżdżony
- Email odbiorcy: musi być zanonimizowany `allegromail.pl` z Allegro, nie z adresu dostawy
- Przesyłki zagraniczne: waluta ubezpieczenia i COD pobierana dynamicznie z API per metoda

### Etykieta
- Format: ZPL (drukarka Zebra)
- Zapisywana w: `storage/labels/allegro_{order_code}_{datetime}.zpl`
- Na etykiecie: numer zamówienia LED-ONE (dla magazynu)

### Base URL
`https://api.allegro.pl`

### Nagłówki
```
Authorization: Bearer {token}
Accept: application/vnd.allegro.public.v1+json
Content-Type: application/vnd.allegro.public.v1+json
```

## DpdAdapter — szczegóły (przepisany 2026-03-12)
Oryginalny adapter używał XmlServices API (ręczny XML, base64, problemy z namespace).
Przepisany na ObjServices API z natywnym PHP SoapClient — brak ręcznego XML, brak problemów z namespace.
Obsługuje: kurier zwykły, kurier pobranie (COD), etykieta PDF.
