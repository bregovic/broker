-- Tabulka pro dočasné uložení nahraných souborů k importu
-- Umožňuje obejít omezení ephemeral filesystemu a zvýšit bezpečnost/spolehlivost
CREATE TABLE IF NOT EXISTS import_staging (
    staging_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    filename TEXT NOT NULL,
    file_content BYTEA NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Index pro pročištění starých záznamů
CREATE INDEX IF NOT EXISTS idx_staging_created_at ON import_staging(created_at);
