ALTER TABLE packing_session_items
    ADD COLUMN offer_id VARCHAR(128) NULL AFTER pak_order_item_id;

ALTER TABLE packing_session_items
    ADD KEY idx_packing_session_items_offer_id (offer_id),
    ADD KEY idx_packing_session_items_session_offer (packing_session_id, offer_id);
