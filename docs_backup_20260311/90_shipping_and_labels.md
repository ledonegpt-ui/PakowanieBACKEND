# Shipping i generowanie etykiet

## Status
POTWIERDZONE + PLANOWANE

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
| Allegro Kurier DPD pobranie | dpd | **allegro_api** |
| Allegro Automaty Paczkowe DPD | dpd | **allegro_api** |
| ERLI InPost Paczkomaty | erli | **baselinker_api** |
| ERLI DPD | erli | **baselinker_api** |
| Allegro One Box, DPD | allegro_one | allegro_api |

## Resolver — jak działa
Plik: `app/Support/ShippingMethodResolver.php`
Konfiguracja: `app/Config/shipping_map.php`

Resolver przyjmuje `delivery_method`, `carrier_code`, `courier_code` i zwraca pełny obiekt z `menu_group`, `shipment_type`, `label_provider`, `label_endpoint`, `requires_size`.

Reguły mają priorytety — wygrywa reguła z najwyższym priorytetem.
Priorytety po naprawie 2026-03-11:

| Zakres | Kto |
|---|---|
| 2000 | odbiór osobisty |
| 1000 | ERLI (zawsze przez baselinker) |
| 950 | Allegro One Box/Punkt |
| 920 | Allegro InPost paczkomat |
| 900 | Allegro One default |
| 880 | Allegro DPD Pickup |
| 870 | **Allegro DPD** (naprawione z 300) |
| 860 | **Allegro InPost kurier** (naprawione z 310) |
| 850 | zwykły InPost paczkomat |
| 840 | zwykły InPost kurier |
| 830 | zwykły DPD Pickup |
| 821 | DPD sprzedający ustala |
| 820 | zwykły DPD kurier |
| 815 | GLS generic |
| 810 | GLS |
| 800 | DHL |
| 790 | ORLEN/Packeta |

## Adaptery — stan

| Adapter | Plik | Stan | Credentials |
|---|---|---|---|
| DpdAdapter | Adapters/DpdAdapter.php | ⚠️ zaimplementowany, bug XML | ✅ dpd_contract w DB |
| GlsAdapter | Adapters/GlsAdapter.php | ❌ stub | ✅ gls w DB |
| InPostAdapter | Adapters/InPostAdapter.php | ❌ stub | ⚠️ CHANGE_ME |
| AllegroAdapter | Adapters/AllegroAdapter.php | ❌ stub | ⚠️ CHANGE_ME |
| BaseLinkerAdapter | Adapters/BaseLinkerAdapter.php | ❌ stub | ✅ baselinker w DB |

## Mapping label_provider -> provider_code w DB
Zdefiniowany w `ShippingController::resolveProviderCode()`:
- `dpd_api` → `dpd_contract`
- `gls_api` → `gls`
- `inpost_shipx` / `inpost_api` → `inpost_shipx`
- `allegro_api` → `allegro`
- `baselinker_api` / `baselinker` → `baselinker`

## Flow generowania etykiety
1. `ShippingController::generateLabel()` przyjmuje order_code
2. Sprawdza czy istnieje otwarta sesja packingu dla operatora
3. Pobiera zamówienie z `pak_orders`
4. Wywołuje resolver → dostaje `label_provider`
5. Tworzy rekord w `packages` (jeśli nie istnieje)
6. Pobiera config providera z `shipping_providers` (config_json)
7. `ShippingAdapterFactory::make(label_provider)` → zwraca adapter
8. `adapter->generateLabel(order, package, resolved, providerCfg)` → zwraca wynik
9. Zapisuje tracking_number i status do `packages`
10. Tworzy rekord w `package_labels` z file_path i file_token
11. Loguje event `label_generated` do `packing_events`

## Bug DpdAdapter — szczegóły techniczne
Problem: `xmlns=""` na węzłach wewnętrznych SOAP resetuje domyślny namespace.
DPD parser Javy nie może sparsować zawartości `openUMLFeV5`.
Fix: usunąć `xmlns=""` z: `openUMLFeV5`, `pkgNumsGenerationPolicyV1`, `langCode`, `authDataV1`.
Zweryfikowane: credentials są poprawne (SOAP odpowiada, endpoint dostępny).
