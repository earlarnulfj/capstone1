-- Migration: create sync_events table for logging synchronization events
CREATE TABLE IF NOT EXISTS `sync_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL, -- e.g., delivery_status_update
  `source_system` varchar(50) NOT NULL, -- e.g., supplier_system
  `target_system` varchar(50) NOT NULL, -- e.g., admin_system
  `order_id` int(11) DEFAULT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `status_before` varchar(50) DEFAULT NULL,
  `status_after` varchar(50) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id_idx` (`order_id`),
  KEY `delivery_id_idx` (`delivery_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;