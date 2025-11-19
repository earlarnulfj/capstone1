-- Backfill: fix blank enum values caused by invalid writes

-- Fix orders with blank status to 'completed' when there is a delivered/completed delivery
UPDATE `orders` o
JOIN `deliveries` d ON d.order_id = o.id
SET o.confirmation_status = 'completed',
    o.confirmation_date = IFNULL(o.confirmation_date, NOW())
WHERE o.confirmation_status = ''
  AND d.status IN ('delivered','completed');

-- Normalize deliveries with blank status to 'delivered' when order is delivered/completed
UPDATE `deliveries` d
JOIN `orders` o ON o.id = d.order_id
SET d.status = 'delivered'
WHERE (d.status = '' OR d.status IS NULL)
  AND o.confirmation_status IN ('delivered','completed');