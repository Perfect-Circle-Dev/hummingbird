-- Holy Frijoles buying group: database tables (SQLite).
-- Money is stored in cents (integers) so totals never pick up rounding errors.

CREATE TABLE IF NOT EXISTS households (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY,
    household_id INTEGER NOT NULL REFERENCES households(id),
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    phone TEXT,
    is_admin INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One-time sign-in links. Only a hash of the token is stored.
CREATE TABLE IF NOT EXISTS login_tokens (
    token_hash TEXT PRIMARY KEY,
    member_id INTEGER NOT NULL REFERENCES members(id),
    expires_at TEXT NOT NULL,
    used_at TEXT
);

-- Devices that are signed in.
CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    member_id INTEGER NOT NULL REFERENCES members(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT,
    expires_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
INSERT OR IGNORE INTO settings (key, value) VALUES ('discount_percent', '30');
INSERT OR IGNORE INTO settings (key, value) VALUES ('deposit_half_gallon_cents', '150');
INSERT OR IGNORE INTO settings (key, value) VALUES ('deposit_gallon_plus_cents', '300');
INSERT OR IGNORE INTO settings (key, value) VALUES ('share_expiry_months', '3');

-- Catalog, copied nightly from Hummingbird. A "variant" is one size of a product
-- and carries the item number (sku) members order by.
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY,              -- Hummingbird's product id
    title TEXT NOT NULL,
    handle TEXT,
    product_type TEXT,
    tags TEXT
);

CREATE TABLE IF NOT EXISTS variants (
    id INTEGER PRIMARY KEY,              -- Hummingbird's variant id (used to fill the cart)
    product_id INTEGER NOT NULL REFERENCES products(id),
    sku TEXT,
    title TEXT NOT NULL,                 -- the size, e.g. "25 lb bag"
    list_price_cents INTEGER NOT NULL,   -- price shown on Hummingbird's site
    grams INTEGER,
    available INTEGER NOT NULL DEFAULT 1,
    size_amount REAL,                    -- 25 (worked out from the title; used for shares)
    size_unit TEXT,                      -- lb, gal or oz
    deposit_cents INTEGER NOT NULL DEFAULT 0,
    deposit_override_cents INTEGER,      -- set by the organizer when the automatic rule is wrong
    active INTEGER NOT NULL DEFAULT 1,   -- 0 when it disappears from Hummingbird's catalog
    synced_at TEXT
);
CREATE INDEX IF NOT EXISTS variants_sku ON variants(sku);

-- One row per monthly order. There is also always one row with status 'next': the
-- standing "next month" list members can save items on. It has a placeholder name and
-- an empty deadline until the organizer opens it, when it becomes that month.
CREATE TABLE IF NOT EXISTS cycles (
    id INTEGER PRIMARY KEY,
    label TEXT NOT NULL UNIQUE,          -- e.g. "December 2026"
    due_at TEXT NOT NULL,                -- deadline shown to members (soft)
    pickup_note TEXT,
    status TEXT NOT NULL DEFAULT 'open', -- next, open, locked, closed
    locked_at TEXT,
    closed_at TEXT
);

-- One order per household per month.
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY,
    cycle_id INTEGER NOT NULL REFERENCES cycles(id),
    household_id INTEGER NOT NULL REFERENCES households(id),
    status TEXT NOT NULL DEFAULT 'draft', -- draft (unfinished) or submitted
    submitted_at TEXT,
    paid_at TEXT,
    paid_method TEXT,                     -- check, venmo or cash
    updated_at TEXT,
    UNIQUE (cycle_id, household_id)
);

CREATE TABLE IF NOT EXISTS order_lines (
    id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    variant_id INTEGER NOT NULL REFERENCES variants(id),
    qty INTEGER NOT NULL,
    unit_price_cents INTEGER NOT NULL,   -- member price; the organizer can edit it after ordering
    deposit_cents INTEGER NOT NULL DEFAULT 0,
    out_of_stock INTEGER NOT NULL DEFAULT 0,
    added_by INTEGER REFERENCES members(id),
    UNIQUE (order_id, variant_id)
);

-- Share board.
CREATE TABLE IF NOT EXISTS shares (
    id INTEGER PRIMARY KEY,
    variant_id INTEGER NOT NULL REFERENCES variants(id),
    started_by INTEGER NOT NULL REFERENCES members(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open', -- open, ordered, expired
    cycle_id INTEGER REFERENCES cycles(id), -- the month it was ordered in
    price_cents INTEGER,                 -- member price for the whole item, fixed when ordered
    out_of_stock INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS share_claims (
    id INTEGER PRIMARY KEY,
    share_id INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES members(id),
    amount REAL NOT NULL,                -- in the variant's unit (lb or gal)
    cost_cents INTEGER,                  -- this person's part of the price, saved when the month is locked
    UNIQUE (share_id, member_id)
);

CREATE TABLE IF NOT EXISTS favorites (
    member_id INTEGER NOT NULL REFERENCES members(id),
    variant_id INTEGER NOT NULL REFERENCES variants(id),
    PRIMARY KEY (member_id, variant_id)
);

-- Every email the site sends (or would send, in 'log' mode).
CREATE TABLE IF NOT EXISTS email_log (
    id INTEGER PRIMARY KEY,
    to_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL,                -- logged, sent or failed
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
