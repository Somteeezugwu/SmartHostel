<?php
declare(strict_types=1);

namespace SmartHostel\Services;

use PDO;
use RuntimeException;
use Throwable;

final class RoomSwitchService {
    public function __construct(private PDO $pdo, private ?CompatibilityService $engine = null) {}

    public function requestSwitch(int $studentId, int $targetStudentId, int $targetBedOrRoomId, ?string $note = null): array {
        if ($studentId <= 0 || $targetStudentId <= 0 || $targetBedOrRoomId <= 0) {
            return ['error' => 'Choose a valid student and room or bed.'];
        }
        if ($studentId === $targetStudentId) {
            return ['error' => 'You cannot request a switch to your own bed.'];
        }

        $this->transactionStart();
        try {
            $student = $this->studentProfile($studentId);
            if (!$student || $student['eligibility'] !== 'eligible') {
                throw new RuntimeException('Only eligible students may request a room switch.');
            }
            $target = $this->studentProfile($targetStudentId);
            if (!$target || $target['eligibility'] !== 'eligible') {
                throw new RuntimeException('The target student is not eligible for a room switch.');
            }
            if (($student['gender'] ?? null) !== ($target['gender'] ?? null)) {
                throw new RuntimeException('Roommate switches are limited to the same accommodation category.');
            }
            if (!$this->hasCompleteQuestionnaire($student) || !$this->hasCompleteQuestionnaire($target)) {
                throw new RuntimeException('Both students must complete all lifestyle preferences before requesting a roommate switch.');
            }
            $engine = $this->compatibilityEngine();
            $compatibility = $engine->compare($student, $target)['score'];
            if ($compatibility === null || $compatibility < 1) {
                throw new RuntimeException('These students do not have compatible saved preferences for a room switch.');
            }

            $current = $this->currentAllocation($studentId);
            if (!$current) {
                throw new RuntimeException('You do not currently hold a confirmed bed in the hostel.');
            }
            $targetAllocation = $this->currentAllocation($targetStudentId);
            if (!$targetAllocation) {
                throw new RuntimeException('The target student is not currently allocated to a room.');
            }
            $targetBedId = $this->resolveTargetBedId($targetBedOrRoomId, (int)$targetAllocation['room_id']);
            if ($targetBedId === null) {
                throw new RuntimeException('That room or bed is not available for a switch.');
            }
            $targetBed = $this->bedRoomData($targetBedId);
            if (!$targetBed || $targetBed['status'] !== 'active' || $targetBed['room_status'] !== 'active' || $targetBed['hostel_status'] !== 'active' || (int)$targetBed['room_id'] !== (int)$targetAllocation['room_id']) {
                throw new RuntimeException('The destination is no longer in the target student’s active room.');
            }
            if ($targetBed['hostel_gender'] !== 'any' && $targetBed['hostel_gender'] !== $student['gender']) {
                throw new RuntimeException('The destination hostel does not permit this student profile.');
            }
            if ($this->hasPendingRequest($studentId)) {
                throw new RuntimeException('A room-switch request is already pending for this student.');
            }
            if ($this->bedUnavailable($targetBedId)) {
                throw new RuntimeException('The target bed is no longer available.');
            }
            if ($this->hasPendingDestination($targetBedId)) {
                throw new RuntimeException('Another room-switch request is already pending for this bed.');
            }

            $insert = $this->pdo->prepare('INSERT INTO room_switch_requests(student_user_id,target_student_user_id,from_bed_space_id,to_bed_space_id,status,reason,created_at,updated_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            $insert->execute([$studentId, $targetStudentId, $current['bed_space_id'], $targetBedId, 'pending', trim((string)($note ?? ''))]);
            $requestId = (int)$this->pdo->lastInsertId();
            $this->noteStudent($studentId, 'Room switch requested', 'A room-switch request was submitted for the target room.');
            $this->noteStudent($targetStudentId, 'Room switch request', 'A student requested a move into your room.');
            foreach ($this->pdo->query("SELECT id FROM users WHERE role IN ('admin','super_admin')") as $admin) {
                $this->noteStudent((int)$admin['id'], 'Room switch pending review', 'A student submitted a room-switch request for administrator review.');
            }
            $this->transactionCommit();
            return ['ok' => true, 'status' => 'pending', 'id' => $requestId, 'request_id' => $requestId, 'to_bed_space_id' => $targetBedId, 'message' => 'Your room-switch request is pending review.'];
        } catch (Throwable $e) {
            $this->transactionRollback();
            return ['error' => $this->safeError($e)];
        }
    }

    public function reviewSwitch(int $reviewerId, int $requestId, string $decision, ?string $note = null): array {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['error' => 'Choose a valid review decision.'];
        }

        $this->transactionStart();
        try {
            $request = $this->pdo->prepare('SELECT * FROM room_switch_requests WHERE id=? AND status=?');
            $request->execute([$requestId, 'pending']);
            $row = $request->fetch();
            if (!$row) {
                throw new RuntimeException('This room-switch request is no longer pending.');
            }

            $studentId = (int)$row['student_user_id'];
            $targetStudentId = (int)$row['target_student_user_id'];
            $fromBedSpaceId = (int)$row['from_bed_space_id'];
            $toBedSpaceId = (int)$row['to_bed_space_id'];

            if ($decision === 'rejected') {
                $this->pdo->prepare('UPDATE room_switch_requests SET status=?, reviewer_user_id=?, reviewed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['rejected', $reviewerId, $requestId]);
                $this->noteStudent($studentId, 'Room switch rejected', 'Your switch request was not approved.');
                $this->noteStudent($targetStudentId, 'Room switch request closed', 'The room-switch request for your room was rejected.');
                $this->auditAction($reviewerId, 'room_switch_rejected', 'room_switch_request', $requestId, ['student_user_id' => $studentId, 'target_student_user_id' => $targetStudentId]);
                $this->transactionCommit();
                return ['ok' => true, 'status' => 'rejected', 'id' => $requestId];
            }

            $student = $this->studentProfile($studentId);
            $target = $this->studentProfile($targetStudentId);
            if (!$student || !$target || $student['eligibility'] !== 'eligible' || $target['eligibility'] !== 'eligible') {
                throw new RuntimeException('A student in this request is no longer valid.');
            }
            if (($student['gender'] ?? null) !== ($target['gender'] ?? null) || !$this->hasCompleteQuestionnaire($student) || !$this->hasCompleteQuestionnaire($target)) {
                throw new RuntimeException('The roommate eligibility rules are no longer satisfied.');
            }
            $compatibility = $this->compatibilityEngine()->compare($student, $target)['score'];
            if ($compatibility === null || $compatibility < 1) {
                throw new RuntimeException('The saved preferences are no longer compatible for this move.');
            }
            $currentAllocation = $this->currentAllocation($studentId);
            if (!$currentAllocation || (int)$currentAllocation['bed_space_id'] !== $fromBedSpaceId) {
                throw new RuntimeException('The requesting student no longer holds the original bed.');
            }
            if ($this->bedUnavailable($toBedSpaceId)) {
                throw new RuntimeException('The target bed is no longer available.');
            }
            $targetAllocation = $this->currentAllocation($targetStudentId);
            $destination = $this->bedRoomData($toBedSpaceId);
            if (!$targetAllocation || !$destination || (int)$targetAllocation['room_id'] !== (int)$destination['room_id']) {
                throw new RuntimeException('The target student is no longer allocated to a room.');
            }
            if ($destination['status'] !== 'active' || $destination['room_status'] !== 'active' || $destination['hostel_status'] !== 'active' || ($destination['hostel_gender'] !== 'any' && $destination['hostel_gender'] !== $student['gender'])) {
                throw new RuntimeException('The destination is no longer eligible for this student.');
            }

            $this->pdo->prepare('UPDATE allocations SET status="cancelled" WHERE student_user_id=? AND status="confirmed" AND bed_space_id=?')->execute([$studentId, $fromBedSpaceId]);
            $this->pdo->prepare("UPDATE reservations SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='confirmed'")->execute([(int)$currentAllocation['reservation_id']]);
            $newReservationId = $this->createConfirmedReservation($studentId, $toBedSpaceId);
            $this->pdo->prepare('INSERT INTO allocations(student_user_id,bed_space_id,reservation_id,status,created_at) VALUES(?,?,?,\'confirmed\',CURRENT_TIMESTAMP)')->execute([$studentId, $toBedSpaceId, $newReservationId]);
            $this->pdo->prepare('UPDATE room_switch_requests SET status=?, reviewer_user_id=?, reviewed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['approved', $reviewerId, $requestId]);
            $this->noteStudent($studentId, 'Room switch approved', 'Your room switch was approved and your allocation has been updated.');
            $this->noteStudent($targetStudentId, 'Roommate move approved', 'A roommate switched into your room and the allocation was updated.');
            $this->auditAction($reviewerId, 'room_switch_approved', 'room_switch_request', $requestId, ['student_user_id' => $studentId, 'from_bed_space_id' => $fromBedSpaceId, 'to_bed_space_id' => $toBedSpaceId]);
            $this->transactionCommit();
            return ['ok' => true, 'status' => 'approved', 'id' => $requestId, 'student_user_id' => $studentId, 'bed_space_id' => $toBedSpaceId, 'message' => 'The switch was approved and the allocation was updated.'];
        } catch (Throwable $e) {
            $this->transactionRollback();
            return ['error' => $this->safeError($e)];
        }
    }

    public function studentRequests(int $studentId): array {
        $s = $this->pdo->prepare('SELECT r.*,CASE WHEN r.student_user_id=? THEN \'outgoing\' ELSE \'incoming\' END AS direction,sp.first_name,sp.last_name,t.first_name as target_first_name,t.last_name as target_last_name,fromBed.bed_label as from_bed,toBed.bed_label as to_bed,fromRoom.room_number as from_room,toRoom.room_number as to_room,fromHost.name as from_hostel,toHost.name as to_hostel FROM room_switch_requests r JOIN student_profiles sp ON sp.user_id=r.student_user_id JOIN student_profiles t ON t.user_id=r.target_student_user_id JOIN bed_spaces fromBed ON fromBed.id=r.from_bed_space_id JOIN rooms fromRoom ON fromRoom.id=fromBed.room_id JOIN hostels fromHost ON fromHost.id=fromRoom.hostel_id JOIN bed_spaces toBed ON toBed.id=r.to_bed_space_id JOIN rooms toRoom ON toRoom.id=toBed.room_id JOIN hostels toHost ON toHost.id=toRoom.hostel_id WHERE r.student_user_id=? OR r.target_student_user_id=? ORDER BY r.created_at DESC');
        $s->execute([$studentId, $studentId, $studentId]);
        return $s->fetchAll();
    }

    public function switchOptions(int $studentId, int $targetStudentId): array {
        if ($studentId <= 0 || $targetStudentId <= 0 || $studentId === $targetStudentId) {
            return ['error' => 'Choose a valid roommate.'];
        }
        $student = $this->studentProfile($studentId);
        $target = $this->studentProfile($targetStudentId);
        $current = $this->currentAllocation($studentId);
        $targetAllocation = $this->currentAllocation($targetStudentId);
        if (!$student || $student['eligibility'] !== 'eligible' || !$current) {
            return ['error' => 'A confirmed allocation and approved eligibility are required to request a switch.'];
        }
        if ($this->hasPendingRequest($studentId)) {
            return ['error' => 'A room-switch request is already pending for this student.'];
        }
        if (!$target || $target['eligibility'] !== 'eligible' || !$targetAllocation || ($student['gender'] ?? null) !== ($target['gender'] ?? null) || !$this->hasCompleteQuestionnaire($student) || !$this->hasCompleteQuestionnaire($target) || $this->compatibilityEngine()->compare($student, $target)['score'] < 1) {
            return ['error' => 'That student is not eligible for a compatible room switch.'];
        }
        $targetHostel = $this->bedRoomData((int)$targetAllocation['bed_space_id']);
        if (!$targetHostel || $targetHostel['room_status'] !== 'active' || $targetHostel['hostel_status'] !== 'active' || ($targetHostel['hostel_gender'] !== 'any' && $targetHostel['hostel_gender'] !== $student['gender'])) {
            return ['error' => 'The target student’s room is not available for this student profile.'];
        }
        $beds = $this->pdo->prepare("SELECT b.id,b.bed_label,rm.room_number,h.name AS hostel_name FROM bed_spaces b JOIN rooms rm ON rm.id=b.room_id JOIN hostels h ON h.id=rm.hostel_id WHERE b.room_id=? AND b.status='active' AND rm.status='active' AND h.status='active' AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.bed_space_id=b.id AND a.status='confirmed') AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.bed_space_id=b.id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP) AND NOT EXISTS(SELECT 1 FROM room_switch_requests s WHERE s.to_bed_space_id=b.id AND s.status='pending') ORDER BY b.id");
        $beds->execute([(int)$targetAllocation['room_id']]);
        return [
            'current' => ['hostel' => $current['hostel_name'], 'room_number' => $current['room_number'], 'bed_label' => $current['bed_label']],
            'target' => ['first_name' => $target['first_name'], 'room_number' => $targetAllocation['room_number'], 'hostel' => $targetAllocation['hostel_name']],
            'beds' => $beds->fetchAll(),
            'requests' => $this->studentRequests($studentId),
        ];
    }

    public function adminRequests(): array {
        $s = $this->pdo->query('SELECT r.*,sp.first_name,sp.last_name,sp.matric_no,t.first_name AS target_first_name,t.last_name AS target_last_name,fromBed.bed_label AS from_bed,toBed.bed_label AS to_bed,fromRoom.room_number AS from_room,toRoom.room_number AS to_room,fromHost.name AS from_hostel,toHost.name AS to_hostel FROM room_switch_requests r JOIN student_profiles sp ON sp.user_id=r.student_user_id JOIN student_profiles t ON t.user_id=r.target_student_user_id JOIN bed_spaces fromBed ON fromBed.id=r.from_bed_space_id JOIN rooms fromRoom ON fromRoom.id=fromBed.room_id JOIN hostels fromHost ON fromHost.id=fromRoom.hostel_id JOIN bed_spaces toBed ON toBed.id=r.to_bed_space_id JOIN rooms toRoom ON toRoom.id=toBed.room_id JOIN hostels toHost ON toHost.id=toRoom.hostel_id ORDER BY CASE r.status WHEN \'pending\' THEN 0 ELSE 1 END,r.created_at DESC LIMIT 200');
        return $s->fetchAll();
    }

    public function cancelSwitch(int $studentId, int $requestId): array {
        $this->transactionStart();
        try {
            $cancel = $this->pdo->prepare("UPDATE room_switch_requests SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=? AND student_user_id=? AND status='pending'");
            $cancel->execute([$requestId, $studentId]);
            if (!$cancel->rowCount()) {
                throw new RuntimeException('This pending switch request was not found.');
            }
            $this->transactionCommit();
            return ['ok' => true, 'status' => 'cancelled', 'id' => $requestId];
        } catch (Throwable $e) {
            $this->transactionRollback();
            return ['error' => $this->safeError($e)];
        }
    }

    private function studentProfile(int $userId): ?array {
        $s = $this->pdo->prepare('SELECT p.user_id,p.first_name,p.last_name,p.gender,p.eligibility,p.faculty,p.department,p.academic_level,a.sleep_time,a.study_time,a.cleanliness,a.noise_tolerance,a.social_style,a.guest_frequency FROM student_profiles p LEFT JOIN student_answers a ON a.user_id=p.user_id WHERE p.user_id=?');
        $s->execute([$userId]);
        return $s->fetch() ?: null;
    }

    private function hasCompleteQuestionnaire(array $profile): bool {
        foreach (CompatibilityService::criteriaLabels() as $key => $_label) {
            $value = $profile[$key] ?? null;
            if ($value === null || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
                return false;
            }
            $number = (int)$value;
            if (in_array($key, ['sleep_time', 'study_time'], true) ? ($number < 0 || $number > 1439) : ($number < 1 || $number > 5)) {
                return false;
            }
        }
        return true;
    }

    private function compatibilityEngine(): CompatibilityService {
        if ($this->engine) {
            return $this->engine;
        }
        $weights = $this->pdo->query('SELECT criterion_name,weight FROM matching_weights WHERE enabled=1')->fetchAll(PDO::FETCH_KEY_PAIR);
        return new CompatibilityService($weights);
    }

    private function currentAllocation(int $studentId): ?array {
        $s = $this->pdo->prepare("SELECT a.id,a.student_user_id,a.bed_space_id,a.reservation_id,a.status,b.bed_label,rm.room_number,rm.id room_id,h.name hostel_name,h.gender_eligibility FROM allocations a JOIN bed_spaces b ON b.id=a.bed_space_id JOIN rooms rm ON rm.id=b.room_id JOIN hostels h ON h.id=rm.hostel_id WHERE a.student_user_id=? AND a.status='confirmed' LIMIT 1");
        $s->execute([$studentId]);
        return $s->fetch() ?: null;
    }

    private function bedRoomData(int $bedId): ?array {
        $s = $this->pdo->prepare('SELECT b.id,b.status,rm.id AS room_id,rm.status AS room_status,h.status AS hostel_status,h.gender_eligibility AS hostel_gender FROM bed_spaces b JOIN rooms rm ON rm.id=b.room_id JOIN hostels h ON h.id=rm.hostel_id WHERE b.id=? LIMIT 1');
        $s->execute([$bedId]);
        return $s->fetch() ?: null;
    }

    private function hasPendingRequest(int $studentId): bool {
        $s = $this->pdo->prepare('SELECT id FROM room_switch_requests WHERE student_user_id=? AND status="pending" LIMIT 1');
        $s->execute([$studentId]);
        return (bool)$s->fetchColumn();
    }

    private function hasPendingDestination(int $bedId): bool {
        $s = $this->pdo->prepare("SELECT id FROM room_switch_requests WHERE to_bed_space_id=? AND status='pending' LIMIT 1");
        $s->execute([$bedId]);
        return (bool)$s->fetchColumn();
    }

    private function resolveTargetBedId(int $targetBedOrRoomId, int $targetRoomId): ?int {
        $bed = $this->pdo->prepare('SELECT id FROM bed_spaces WHERE id=? AND status="active" AND room_id=? LIMIT 1');
        $bed->execute([$targetBedOrRoomId, $targetRoomId]);
        return $bed->fetchColumn() && !$this->bedUnavailable($targetBedOrRoomId) ? $targetBedOrRoomId : null;
    }

    private function bedUnavailable(int $bedId): bool {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM bed_spaces b WHERE b.id=? AND b.status='active' AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.bed_space_id=b.id AND a.status='confirmed') AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.bed_space_id=b.id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP)");
        $s->execute([$bedId]);
        return (int)$s->fetchColumn() === 0;
    }

    private function transactionStart(): void {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('A room-switch transaction is already active.');
        }
        if (function_exists('begin_write')) {
            \begin_write($this->pdo);
            return;
        }
        $this->pdo->beginTransaction();
    }

    private function transactionCommit(): void {
        if (function_exists('commit_write')) {
            \commit_write($this->pdo);
            return;
        }
        $this->pdo->commit();
    }

    private function transactionRollback(): void {
        if (function_exists('rollback_write')) {
            \rollback_write($this->pdo);
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function noteStudent(int $userId, string $title, string $message): void {
        if (function_exists('note')) {
            \note($this->pdo, $userId, $title, $message);
            return;
        }
        $this->pdo->prepare('INSERT INTO notifications(user_id,title,message) VALUES(?,?,?)')->execute([$userId, $title, $message]);
    }

    private function auditAction(int $actor, string $action, string $type, ?int $id, array $details = []): void {
        if (function_exists('audit')) {
            \audit($this->pdo, $actor, $action, $type, $id, $details);
            return;
        }
        $this->pdo->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)')->execute([$actor, $action, $type, $id, json_encode($details)]);
    }

    private function createConfirmedReservation(int $studentId, int $bedId): int {
        $created = gmdate('Y-m-d H:i:s');
        $insert = $this->pdo->prepare('INSERT INTO reservations(student_user_id,bed_space_id,status,created_at,expires_at) VALUES(?,?,?, ?, ?)');
        $insert->execute([$studentId, $bedId, 'confirmed', $created, $created]);
        return (int)$this->pdo->lastInsertId();
    }

    private function safeError(Throwable $error): string {
        if ($error instanceof \PDOException) {
            error_log('Room-switch database operation failed: ' . $error->getMessage());
            return 'The room-switch request could not be completed because the accommodation data changed.';
        }
        return $error->getMessage();
    }
}
