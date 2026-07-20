# DATABASE SCHEMA — Laravel (MySQL/PostgreSQL)

## Catalogo prodotti

```sql
brands
  id, name                                   -- Franke, Dalla Corte, Jura, Universale/Accessori

categories
  id, name, parent_id (nullable)             -- nested categories

products
  id, sku, name, category_id, brand_id,
  type (enum: standalone|configurable|component),
  base_price, description

product_option_slots                         -- slot di un prodotto configurabile
  id, product_id, slot_name, min_qty, max_qty, required (bool)

product_option_slot_items                    -- componenti ammessi in uno slot
  id, slot_id, component_product_id, price_delta_override (nullable)
```

## Listini

```sql
price_lists
  id, name, valid_from, valid_to             -- unico, globale (no partner_id)

price_list_items
  id, price_list_id, product_id, price
```

## Partner e scoping

```sql
partners
  id, name                                   -- es. GIFAR

partner_brand_access
  partner_id, brand_id                       -- ogni partner ha sempre anche "Universale"

users
  id, name, email, partner_id (nullable)     -- null = staff interno
```

## Preventivi e ordini

```sql
quotes
  id, partner_id, client_id, status, total

quote_items
  id, quote_id, product_id, quantity, base_price_at_time

quote_item_components
  id, quote_item_id, slot_id, component_product_id, price_delta_at_time

orders
  id, quote_id (nullable), partner_id, status
  -- tracking stato: tabella separata o jsonb history, da decidere in fase di build
```

## Clienti, impianti, mezzi

```sql
clients
  id, name, address, email (nullable)        -- email necessaria per invio rapportino

installations
  id, client_id, tipo_impianto, data_installazione

vehicles
  id, targa, modello, tecnico_assegnato_id (nullable)
```

## Scadenzario (polimorfico: impianti + mezzi)

```sql
deadline_categories
  id, name                                   -- Lavaggio impianto birra, Manutenzione
                                              -- ordinaria caffè, Revisione mezzo, Assicurazione, Bollo

deadlines
  id, deadlineable_type, deadlineable_id,    -- Installation | Vehicle
  category_id, frequenza_mesi (nullable),
  ultima_esecuzione, prossima_scadenza, note
```

## Rapportini tecnici

```sql
work_reports
  id, uuid (da Flutter, unique), client_id, installation_id (nullable),
  deadline_id (nullable), technician_id, date, time_in, time_out,
  signature_path, pdf_path,
  emailed_client_at (nullable), emailed_admin_at (nullable),
  created_at   -- immutabile: nessun updated_at rilevante dopo la firma

work_report_components
  id, work_report_id, component_product_id, action (enum: sostituito|riparato), quantity
```

## Magazzino ricambi

```sql
warehouse_items
  id, product_id, quantity_available, min_threshold

warehouse_movements
  id, warehouse_item_id, type (enum: carico|scarico), quantity,
  work_report_id (nullable), created_at
```

## HR — presenze, ferie, straordinari

```sql
employees
  id, user_id

employee_contracts
  id, employee_id, ore_settimanali_contrattuali, valid_from, valid_to

attendances
  id, uuid (da Flutter, unique, nullable se creata da web), employee_id,
  clock_in, clock_out (nullable),
  clock_in_lat, clock_in_lng, clock_out_lat, clock_out_lng,
  synced_at (nullable)                       -- timestamp arrivo lato server, distinto da clock_in/out

leave_requests
  id, employee_id, type (enum: ferie|permesso), date_from, date_to,
  status (enum: pending|approved|rejected), approved_by
```

## RBAC e audit (via package, tabelle standard)

```sql
-- generate da spatie/laravel-permission
roles, permissions, model_has_roles, model_has_permissions, role_has_permissions

-- generate da spatie/laravel-activitylog
activity_log     -- applicato a: products, price_list_items, deadlines
```

## Note trasversali

- Tutti gli ID creati offline (Flutter) sono **UUID**, mai autoincrementali —
  necessario per idempotenza della sync.
- Prezzi e configurazioni scelte in un preventivo/ordine sono sempre uno
  **snapshot congelato** al momento della conferma, mai un riferimento
  dinamico a `price_list_items`/`product_option_slot_items` (che possono
  cambiare nel tempo).
