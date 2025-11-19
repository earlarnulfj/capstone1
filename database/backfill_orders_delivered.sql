-- Backfill: set orders to delivered where there is a delivered delivery
UPDATE `orders` o
JOIN (
  SELECT DISTINCT order_id FROM `deliveries` WHERE `status` = 'delivered'
) d ON d.order_id = o.id
SET o.confirmation_status = 'delivered',
    o.confirmation_date = IFNULL(o.confirmation_date, NOW())
WHERE o.confirmation_status <> 'cancelled' AND o.confirmation_status <> 'delivered';