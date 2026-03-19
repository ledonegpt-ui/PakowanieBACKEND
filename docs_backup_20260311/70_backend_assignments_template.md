# Podział zadań backendowych — aktualny stan

## Developer — Shipping / Etykiety (GŁÓWNE ZADANIE)

### Zadanie A — Napraw DpdAdapter
Status: DO ZROBIENIA
Problem: `Premature end of file` w odpowiedzi DPD SOAP.
Przyczyna: `xmlns=""` na węzłach wewnętrznych SOAP resetuje namespace.
Fix: usunąć `xmlns=""` z węzłów `openUMLFeV5`, `pkgNumsGenerationPolicyV1`, `langCode`, `authDataV1`.
Plik: `app/Modules/Shipping/Adapters/DpdAdapter.php`

### Zadanie B — Zaimplementuj GlsAdapter
Status: DO ZROBIENIA
Credentials: są w tabeli `shipping_providers` (provider_code = `gls`)
Plik: `app/Modules/Shipping/Adapters/GlsAdapter.php`

### Zadanie C — Zaimplementuj BaseLinkerAdapter
Status: DO ZROBIENIA
Credentials: token w tabeli `shipping_providers` (provider_code = `baselinker`)
Używany przez: ERLI (wszystkie metody ERLI idą przez baselinker_api)
Plik: `app/Modules/Shipping/Adapters/BaseLinkerAdapter.php`

### Zadanie D — Uzupełnij credentials i zaimplementuj InPostAdapter
Status: ZABLOKOWANE — token = CHANGE_ME
Akcja: manager/ops musi wpisać prawdziwy token do `shipping_providers` gdzie `provider_code = 'inpost_shipx'`
Plik: `app/Modules/Shipping/Adapters/InPostAdapter.php`

### Zadanie E — Uzupełnij credentials i zaimplementuj AllegroAdapter
Status: ZABLOKOWANE — token = CHANGE_ME
Akcja: manager/ops musi wpisać prawdziwy token do `shipping_providers` gdzie `provider_code = 'allegro'`
Plik: `app/Modules/Shipping/Adapters/AllegroAdapter.php`

### Zadanie F — Refaktor ShippingController
Status: DO ZROBIENIA (po działających adapterach)
Cel: przenieść logikę biznesową do ShippingService, zapytania do ShippingRepository
Aktualnie: cała logika siedzi w ShippingController::generateLabel()
Pliki: ShippingService.php (pusty), ShippingRepository.php (pusty)

### Zadanie G — Domknij packing/finish
Status: DO ZROBIENIA (po działającym generate-label)
Cel: pełny end-to-end: generate-label -> finish -> pak_orders zaktualizowane
Weryfikacja: przejść pełny flow na realnym order_code bez ręcznych poprawek w DB

## Definition of Done
Temat uznany za zamknięty gdy:
- pełny flow przechodzi na realnym order_code: login → picking → close → packing open → generate-label → label fetch → packing finish
- label_provider dobrany poprawnie przez resolver
- rekordy packages i package_labels zapisują się poprawnie
- finish kończy pakowanie bez obejść
- logika shippingu siedzi w service/repository, nie w kontrolerze
- błędy providera trafiają do logów / packing_events
