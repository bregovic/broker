-- Insert ticket mappings for known tickers
INSERT INTO broker_ticker_mapping (ticker, company_name, isin, exchange, currency, google_finance_code, status) VALUES
('CBK', 'Commerzbank AG', 'DE000CBK1001', 'FRA', 'EUR', 'CBK:FRA', 'verified'),
('ERCB', 'Ericsson', 'SE0000108656', 'STO', 'SEK', 'ERIC-B:STO', 'verified')
ON DUPLICATE KEY UPDATE 
    company_name = VALUES(company_name),
    isin = VALUES(isin),
    exchange = VALUES(exchange),
    currency = VALUES(currency),
    google_finance_code = VALUES(google_finance_code),
    status = VALUES(status);
