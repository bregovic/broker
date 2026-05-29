-- Helpdesk (change requests) + development history schema — PostgreSQL.
-- Idempotent: safe to run repeatedly. Consolidates the legacy setup_*.php scripts
-- (changerequests, attachments, history, comments, reactions, assignee/subject ALTERs)
-- into one PG-compatible definition. See .agent/DB_STATE.md.

-- Main requests table
CREATE TABLE IF NOT EXISTS changerequest_log (
    id              SERIAL PRIMARY KEY,
    user_id         INT NOT NULL,
    subject         VARCHAR(255) NOT NULL DEFAULT 'No Subject',
    priority        VARCHAR(20)  NOT NULL DEFAULT 'medium',   -- low | medium | high
    assigned_to     INT NULL,
    description     TEXT NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'New',       -- New|Analysis|Development|Testing|Done|Approved|Canceled
    attachment_path VARCHAR(255) NULL,
    admin_notes     TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Re-add columns if an older table version already exists without them
ALTER TABLE changerequest_log ADD COLUMN IF NOT EXISTS subject     VARCHAR(255) NOT NULL DEFAULT 'No Subject';
ALTER TABLE changerequest_log ADD COLUMN IF NOT EXISTS assigned_to INT NULL;

-- Request attachments
CREATE TABLE IF NOT EXISTS changerequest_attachments (
    id         SERIAL PRIMARY KEY,
    request_id INT NOT NULL,
    file_path  VARCHAR(255) NOT NULL,
    filename   VARCHAR(255) DEFAULT '',
    filesize   INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Audit/history of changes to a request
CREATE TABLE IF NOT EXISTS changerequest_history (
    id          SERIAL PRIMARY KEY,
    request_id  INT NOT NULL,
    user_id     INT NULL,
    username    VARCHAR(100),
    change_type VARCHAR(50) NOT NULL,   -- status | priority | assignee | description | created
    old_value   TEXT,
    new_value   TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Comments on a request
CREATE TABLE IF NOT EXISTS changerequest_comments (
    id         SERIAL PRIMARY KEY,
    request_id INT NOT NULL REFERENCES changerequest_log(id) ON DELETE CASCADE,
    user_id    INT NOT NULL,
    username   VARCHAR(100) NOT NULL,
    comment    TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Attachments on a comment
CREATE TABLE IF NOT EXISTS changerequest_comment_attachments (
    id         SERIAL PRIMARY KEY,
    comment_id INT NOT NULL REFERENCES changerequest_comments(id) ON DELETE CASCADE,
    file_path  VARCHAR(500) NOT NULL,
    file_name  VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Reactions on a comment
CREATE TABLE IF NOT EXISTS changerequest_comment_reactions (
    id            SERIAL PRIMARY KEY,
    comment_id    INT NOT NULL,
    user_id       INT NOT NULL,
    reaction_type VARCHAR(50) NOT NULL,  -- smile | check | cross | heart | ...
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Development history / changelog
CREATE TABLE IF NOT EXISTS development_history (
    id              SERIAL PRIMARY KEY,
    date            DATE NOT NULL DEFAULT CURRENT_DATE,  -- used by api-dev-history (SELECT/INSERT)
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    category        VARCHAR(20) DEFAULT 'feature',  -- feature|bugfix|improvement|refactor|deployment
    related_task_id INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE development_history ADD COLUMN IF NOT EXISTS date DATE NOT NULL DEFAULT CURRENT_DATE;

-- Helpful indexes
CREATE INDEX IF NOT EXISTS idx_cr_attach_request   ON changerequest_attachments(request_id);
CREATE INDEX IF NOT EXISTS idx_cr_history_request  ON changerequest_history(request_id);
CREATE INDEX IF NOT EXISTS idx_cr_comments_request ON changerequest_comments(request_id);
CREATE INDEX IF NOT EXISTS idx_cr_comment_react    ON changerequest_comment_reactions(comment_id);
