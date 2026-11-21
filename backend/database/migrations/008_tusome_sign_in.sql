-- Signing in from Tusome. A learner clicks Student Voice on their Tusome dashboard; Tusome sends a
-- signed one-use ticket (see Controllers\TusomeSignInController) and they are in - no voting number.
--
-- settings: small switches. voting_numbers = '1' lets students also sign in with a voting number;
--           '0' means Tusome only (slips no longer needed).
-- tusome_tickets: tickets already used, so each works once (kept a day, then cleared).

CREATE TABLE settings (
  name VARCHAR(64) NOT NULL,
  value VARCHAR(255) NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value) VALUES ('voting_numbers', '1');

CREATE TABLE tusome_tickets (
  nonce CHAR(24) NOT NULL,
  used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (nonce),
  KEY idx_ticket_used (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
