-- Trigger: when a delivery is updated to 'delivered', mark the related order as delivered
DELIMITER $$
CREATE TRIGGER `sync_order_on_delivery_delivered`
AFTER UPDATE ON `deliveries`
FOR EACH ROW
BEGIN
    IF NEW.status = 'delivered' AND (OLD.status IS NULL OR OLD.status <> 'delivered') THEN
        UPDATE `orders`
            SET `confirmation_status` = 'delivered',
                `confirmation_date` = IFNULL(`confirmation_date`, NOW())
            WHERE `id` = NEW.order_id;
        INSERT INTO `sync_events` (
            `event_type`, `source_system`, `target_system`, `order_id`, `delivery_id`, `status_before`, `status_after`, `success`, `message`
        ) VALUES (
            'delivery_status_update', 'supplier_system', 'admin_system', NEW.order_id, NEW.id, OLD.status, NEW.status, 1, 'Trigger: order marked delivered'
        );
    END IF;
END$$
DELIMITER ;