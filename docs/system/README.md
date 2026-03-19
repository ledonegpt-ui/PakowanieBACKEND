# System pakowania LED-ONE - dokumentacja główna

Ta dokumentacja ma służyć tak, żeby nowy programista po wejściu do projektu:
- nie zgadywał jak działa system,
- nie zgadywał jak wygląda API,
- nie zgadywał jak wygląda baza,
- nie zgadywał które elementy są już zaimplementowane,
- nie zgadywał które elementy są dopiero planowane.

## Zasada dokumentacji
Każda rzecz musi mieć jeden z trzech statusów:
- `POTWIERDZONE` - wynika z aktualnego kodu, bazy albo ustaleń biznesowych,
- `PLANOWANE` - ustalony kontrakt docelowy do wdrożenia,
- `DECYZJA_WYMAGANA` - temat nierozstrzygnięty i nie wolno go implementować „na czuja”.

## Część ręczna
- `01_system_context.md`
- `02_workflow_target.md`
- `03_api_contract.md`
- `04_database_contract.md`
- `05_shipping_and_labels.md`
- `06_implementation_rules.md`

## Część generowana z projektu i bazy
- `generated/01_inventory.md`
- `generated/02_routes.md`
- `generated/03_db_schema.md`
- `generated/04_carrier_groups.md`
- `generated/05_shipping_map.md`
- `generated/06_stations.md`
- `generated/07_migrations.md`

## Najważniejsze zasady biznesowe
1. System ma być API-first.
2. Klientem docelowym jest aplikacja tabletowa Kotlin.
3. Import zamówień ma dalej działać i nie wolno go zepsuć.
4. Stary system przeglądarkowy został odłożony do `starysystem/`.
5. Logika wyboru kuriera ma być rozbita na:
   - `menu_group` - grupa kafelka dla operatora,
   - `shipment_type` - docelowy typ wysyłki,
   - `label_provider` - przez jakie API generujemy etykietę,
   - `label_endpoint` - jaki adapter backendowy ma wykonać wysyłkę.
