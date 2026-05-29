-- Ticker aliases: maps an old/alternate symbol to the current (canonical) one,
-- so a security whose ticker changed (e.g. Barrick Gold GOLD -> B) is unified in
-- reports. Reports resolve `COALESCE(a.canonical, t.ticker)` via LEFT JOIN.
CREATE TABLE IF NOT EXISTS ticker_aliases (
    alias     VARCHAR(20) PRIMARY KEY,   -- old / alternate symbol seen in statements
    canonical VARCHAR(20) NOT NULL,      -- current symbol to report under
    note      VARCHAR(255)
);

-- Known change: Barrick Gold was "GOLD", now trades as "B".
INSERT INTO ticker_aliases (alias, canonical, note) VALUES ('GOLD', 'B', 'Barrick Gold ticker change')
ON CONFLICT (alias) DO UPDATE SET canonical = EXCLUDED.canonical, note = EXCLUDED.note;
