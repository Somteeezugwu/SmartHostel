CREATE TABLE room_switch_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 student_user_id INTEGER NOT NULL,
 target_student_user_id INTEGER NOT NULL,
 from_bed_space_id INTEGER NOT NULL,
 to_bed_space_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled')),
 reason TEXT,
 reviewer_user_id INTEGER,
 reviewed_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(student_user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(target_student_user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(from_bed_space_id) REFERENCES bed_spaces(id),
 FOREIGN KEY(to_bed_space_id) REFERENCES bed_spaces(id),
 FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX uq_pending_room_switch ON room_switch_requests(student_user_id) WHERE status='pending';
CREATE INDEX ix_room_switch_target_student ON room_switch_requests(target_student_user_id,status);
CREATE INDEX ix_room_switch_status ON room_switch_requests(status,created_at);
CREATE TRIGGER room_switch_requests_updated_at AFTER UPDATE ON room_switch_requests BEGIN UPDATE room_switch_requests SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END;
