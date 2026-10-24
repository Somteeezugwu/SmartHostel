-- SQLite schema, initialized through app/bootstrap.php (PRAGMA user_version = 2).
CREATE TABLE users (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 email TEXT NOT NULL UNIQUE CHECK(length(email)<=190), password_hash TEXT NOT NULL,
 role TEXT NOT NULL DEFAULT 'student' CHECK(role IN ('student','admin','super_admin')),
 failed_logins INTEGER NOT NULL DEFAULT 0 CHECK(failed_logins>=0), locked_until TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE student_profiles (
 user_id INTEGER PRIMARY KEY, matric_no TEXT NOT NULL UNIQUE CHECK(length(matric_no)<=80),
 first_name TEXT NOT NULL CHECK(length(first_name)<=100), last_name TEXT NOT NULL CHECK(length(last_name)<=100),
 phone TEXT, faculty TEXT, department TEXT, course TEXT, academic_level TEXT,
 gender TEXT NOT NULL CHECK(gender IN ('female','male','other','prefer_not_to_say')),
 school_fee_rrr TEXT, eligibility TEXT NOT NULL DEFAULT 'pending' CHECK(eligibility IN ('pending','eligible','ineligible')),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE school_fee_verifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, student_user_id INTEGER NOT NULL, rrr TEXT NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','verified','rejected')),
 reviewer_user_id INTEGER, review_note TEXT, submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at TEXT,
 FOREIGN KEY(student_user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX uq_pending_school_fee ON school_fee_verifications(student_user_id) WHERE status='pending';
CREATE INDEX ix_school_fee_pending ON school_fee_verifications(status,submitted_at);
CREATE TABLE academic_sessions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','closed')), created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE hostels (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT, location TEXT,
 gender_eligibility TEXT NOT NULL DEFAULT 'any' CHECK(gender_eligibility IN ('female','male','any')),
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')), created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE rooms (
 id INTEGER PRIMARY KEY AUTOINCREMENT, hostel_id INTEGER NOT NULL, room_number TEXT NOT NULL,
 capacity INTEGER NOT NULL CHECK(capacity BETWEEN 1 AND 100), status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
 UNIQUE(hostel_id,room_number), FOREIGN KEY(hostel_id) REFERENCES hostels(id)
);
CREATE TABLE bed_spaces (
 id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER NOT NULL, bed_label TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
 UNIQUE(room_id,bed_label), FOREIGN KEY(room_id) REFERENCES rooms(id)
);
CREATE TABLE student_answers (
 user_id INTEGER PRIMARY KEY, sleep_time INTEGER CHECK(sleep_time BETWEEN 0 AND 1439), study_time INTEGER CHECK(study_time BETWEEN 0 AND 1439),
 cleanliness INTEGER CHECK(cleanliness BETWEEN 1 AND 5), noise_tolerance INTEGER CHECK(noise_tolerance BETWEEN 1 AND 5),
 social_style INTEGER CHECK(social_style BETWEEN 1 AND 5), guest_frequency INTEGER CHECK(guest_frequency BETWEEN 1 AND 5),
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE matching_weights (
 criterion_name TEXT PRIMARY KEY, weight REAL NOT NULL CHECK(weight>=0), enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1))
);
INSERT INTO matching_weights(criterion_name,weight,enabled) VALUES
 ('sleep_time',20,1),('study_time',20,1),('cleanliness',20,1),('noise_tolerance',15,1),('social_style',15,1),('guest_frequency',10,1);
CREATE TABLE reservations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, student_user_id INTEGER NOT NULL, bed_space_id INTEGER NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('pending_payment','confirmed','expired','cancelled')),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(student_user_id) REFERENCES users(id), FOREIGN KEY(bed_space_id) REFERENCES bed_spaces(id)
);
CREATE UNIQUE INDEX uq_active_student_hold ON reservations(student_user_id) WHERE status='pending_payment';
CREATE UNIQUE INDEX uq_active_bed_hold ON reservations(bed_space_id) WHERE status='pending_payment';
CREATE INDEX ix_res_bed_status ON reservations(bed_space_id,status,expires_at);
CREATE INDEX ix_res_student ON reservations(student_user_id,status);
CREATE TABLE payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_id INTEGER NOT NULL, purpose TEXT NOT NULL DEFAULT 'hostel' CHECK(purpose='hostel'),
 rrr TEXT NOT NULL UNIQUE, amount NUMERIC NOT NULL CHECK(amount>0), currency TEXT NOT NULL DEFAULT 'NGN' CHECK(length(currency)=3),
 mode TEXT NOT NULL DEFAULT 'development_review' CHECK(mode IN ('development_review','remita')),
 status TEXT NOT NULL DEFAULT 'pending_review' CHECK(status IN ('pending_review','verified','rejected','cancelled')),
 reviewer_user_id INTEGER, review_note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, reviewed_at TEXT,
 FOREIGN KEY(reservation_id) REFERENCES reservations(id), FOREIGN KEY(reviewer_user_id) REFERENCES users(id)
);
CREATE INDEX ix_payments_pending ON payments(status,created_at);
CREATE TABLE allocations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, student_user_id INTEGER NOT NULL, bed_space_id INTEGER NOT NULL,
 reservation_id INTEGER NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'confirmed' CHECK(status IN ('confirmed','cancelled')),
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(student_user_id) REFERENCES users(id),
 FOREIGN KEY(bed_space_id) REFERENCES bed_spaces(id), FOREIGN KEY(reservation_id) REFERENCES reservations(id)
);
CREATE UNIQUE INDEX uq_active_bed ON allocations(bed_space_id) WHERE status='confirmed';
CREATE UNIQUE INDEX uq_student_allocation ON allocations(student_user_id) WHERE status='confirmed';
CREATE TABLE notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL CHECK(length(title)<=150),
 message TEXT NOT NULL, is_read INTEGER NOT NULL DEFAULT 0 CHECK(is_read IN (0,1)), created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE audit_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, action TEXT NOT NULL, entity_type TEXT NOT NULL,
 entity_id INTEGER, details_json TEXT CHECK(details_json IS NULL OR json_valid(details_json)), created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX ix_audit_time ON audit_logs(created_at);
CREATE TRIGGER users_updated_at AFTER UPDATE ON users BEGIN UPDATE users SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END;
CREATE TRIGGER answers_updated_at AFTER UPDATE ON student_answers BEGIN UPDATE student_answers SET updated_at=CURRENT_TIMESTAMP WHERE user_id=NEW.user_id; END;
CREATE TRIGGER reservations_updated_at AFTER UPDATE ON reservations BEGIN UPDATE reservations SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END;
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
