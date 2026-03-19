# System Architecture Diagram

Diagram pokazuje przepływ danych od importu zamówienia
do pakowania i drukowania etykiety.

---

## High level flow

```mermaid
flowchart TD

A[Marketplace / ERP]

B[pak_orders]
C[pak_order_items]

D[product_size_map]

E[resolveOrderPackageSize]

F[Picking]

G[picking_batches]
H[picking_batch_orders]
I[picking_order_items]
J[picking_batch_items]

K[Operator GUI]

L[Packing]

M[Printer Zebra]

A --> B
A --> C

C --> D
D --> E

B --> F
E --> F

F --> G
G --> H
H --> I
I --> J

K --> F

F --> L

L --> M