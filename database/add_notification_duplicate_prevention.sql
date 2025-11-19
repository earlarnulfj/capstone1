-- Add database constraints to prevent duplicate notifications
-- This migration adds a composite index to prevent duplicate notifications
-- for the same type, recipient, and order within a short time window

-- First, let's add an index to improve performance for duplicate checking
ALTER TABLE `notifications` 
ADD INDEX `idx_duplicate_check` (`type`, `recipient_type`, `recipient_id`, `order_id`, `created_at`);

-- Add a composite index for efficient duplicate prevention queries
ALTER TABLE `notifications` 
ADD INDEX `idx_notification_dedup` (`type`, `recipient_type`, `recipient_id`, `order_id`, `created_at`);

-- Optional: Add a unique constraint for strict duplicate prevention
-- Note: This is commented out as it might be too restrictive for some use cases
-- Uncomment if you want strict database-level duplicate prevention
/*
ALTER TABLE `notifications` 
ADD CONSTRAINT `unique_notification_per_order_type` 
UNIQUE (`type`, `recipient_type`, `recipient_id`, `order_id`);
*/

-- Create a stored procedure to clean up old duplicate notifications (optional)
DELIMITER //
CREATE PROCEDURE CleanupDuplicateNotifications()
BEGIN
    -- Remove duplicate notifications keeping only the latest one for each combination
    DELETE n1 FROM notifications n1
    INNER JOIN notifications n2 
    WHERE n1.id < n2.id 
    AND n1.type = n2.type 
    AND n1.recipient_type = n2.recipient_type 
    AND n1.recipient_id = n2.recipient_id 
    AND n1.order_id = n2.order_id 
    AND n1.order_id IS NOT NULL
    AND TIMESTAMPDIFF(MINUTE, n1.created_at, n2.created_at) <= 5;
END //
DELIMITER ;