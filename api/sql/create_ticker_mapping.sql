-- Mapovací tabulka pro správné stahování cen
CREATE TABLE IF NOT EXISTS broker_ticker_mapping (
    ticker VARCHAR(20) PRIMARY KEY,
    company_name VARCHAR(255),
    isin VARCHAR(12),
    exchange VARCHAR(20),
    currency VARCHAR(3),
    google_finance_code VARCHAR(50),  -- např. "CBK:FRA" pro Commerzbank
    coingecko_id VARCHAR(50),         -- např. "bitcoin" pro BTC
    last_verified TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('verified', 'needs_review', 'manual') DEFAULT 'needs_review',
    notes TEXT,
    INDEX idx_isin (isin),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Předvyplnění známých mapování
INSERT INTO broker_ticker_mapping (ticker, company_name, isin, exchange, currency, google_finance_code, status) VALUES
('CBK', 'Commerzbank AG', 'DE000CBK1001', 'FRA', 'EUR', 'CBK:FRA', 'verified'),
('ERCB', 'Ericsson', 'SE0000108656', 'STO', 'SEK', 'ERIC-B:STO', 'verified')
ON DUPLICATE KEY UPDATE 
    company_name = VALUES(company_name),
    isin = VALUES(isin),
    google_finance_code = VALUES(google_finance_code),
    status = VALUES(status);

-- Manuální ceny jdou přímo do broker_live_quotes se source='manual'
-- Příklad vložení manuální ceny:
-- INSERT INTO broker_live_quotes (id, source, current_price, currency, company_name, exchange, last_fetched, status)
-- VALUES ('CBK', 'manual', 31.83, 'EUR', 'Commerzbank AG', 'FRA', NOW(), 'active')
-- ON DUPLICATE KEY UPDATE current_price = VALUES(current_price), last_fetched = NOW();
