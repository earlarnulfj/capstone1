-- Add password reset tokens table for email verification and password reset functionality
-- This table stores verification codes and reset tokens with expiration times

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `verification_code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_token` (`token`),
  KEY `idx_verification_code` (`verification_code`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add index for faster lookups
CREATE INDEX idx_email_token ON password_reset_tokens(email, token);
CREATE INDEX idx_email_code ON password_reset_tokens(email, verification_code);

-- Clean up expired tokens (optional - can be run as a cron job)
-- DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR used = 1;