CREATE TABLE contact_messages (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 120),
 email TEXT NOT NULL CHECK(length(email)<=190),
 subject TEXT NOT NULL CHECK(length(subject) BETWEEN 1 AND 180),
 message TEXT NOT NULL CHECK(length(message) BETWEEN 1 AND 5000),
 status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','handled')),
 submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 handled_at TEXT,
 handled_by INTEGER,
 FOREIGN KEY(handled_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX ix_contact_status_time ON contact_messages(status,submitted_at);
