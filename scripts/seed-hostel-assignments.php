<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This utility is CLI only.\n");
    exit(2);
}
if (!in_array(strtolower(trim(envv('ALLOW_HOSTEL_ASSIGNMENT_SEEDING'))), ['1', 'true', 'yes'], true)) {
    fwrite(STDERR, "Seeding refused. Set ALLOW_HOSTEL_ASSIGNMENT_SEEDING=1 to explicitly allow hostel assignment seeding.\n");
    exit(2);
}

try {
    $pdo = db();
    $hostels = $pdo->query("SELECT id,name,gender_eligibility FROM hostels WHERE status='active' ORDER BY id")->fetchAll();
    if (count($hostels) !== 1) {
        throw new RuntimeException('Expected exactly one active hostel; no records were changed.');
    }
    $hostel = $hostels[0];
    $hostelId = (int)$hostel['id'];

    $studentsQuery = $pdo->prepare("SELECT p.user_id,p.gender FROM student_profiles p WHERE p.eligibility='eligible' AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.student_user_id=p.user_id AND a.status='confirmed') AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.student_user_id=p.user_id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP) ORDER BY CASE p.gender WHEN 'female' THEN 0 WHEN 'male' THEN 1 ELSE 2 END,p.user_id");
    $studentsQuery->execute();
    $students = $studentsQuery->fetchAll();

    $roomsQuery = $pdo->prepare("SELECT id,room_number,capacity FROM rooms WHERE hostel_id=? AND status='active' ORDER BY id");
    $roomsQuery->execute([$hostelId]);
    $rooms = $roomsQuery->fetchAll();
    if (!$rooms) {
        throw new RuntimeException('The active hostel has no active room to use as a capacity template.');
    }
    $roomCapacity = max(1, (int)$rooms[0]['capacity']);

    $roomInsert = $pdo->prepare("INSERT INTO rooms(hostel_id,room_number,capacity,status) VALUES(?,?,?,'active')");
    $bedInsert = $pdo->prepare("INSERT INTO bed_spaces(room_id,bed_label,status) VALUES(?,?,'active')");
    $bedList = $pdo->prepare('SELECT id,room_id,bed_label FROM bed_spaces WHERE room_id=? ORDER BY id');
    $roomOccupants = $pdo->prepare("SELECT DISTINCT p.gender FROM allocations a JOIN bed_spaces b ON b.id=a.bed_space_id JOIN student_profiles p ON p.user_id=a.student_user_id WHERE b.room_id=? AND a.status='confirmed'");
    $nextRoomNumber = 1;
    foreach ($rooms as $room) {
        if (preg_match('/^Room-(\d+)$/', (string)$room['room_number'], $match)) {
            $nextRoomNumber = max($nextRoomNumber, (int)$match[1] + 1);
        }
    }

    $bedsByRoom = [];
    $roomGender = [];
    $bedAvailable = $pdo->prepare("SELECT b.id FROM bed_spaces b WHERE b.room_id=? AND b.status='active' AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.bed_space_id=b.id AND a.status='confirmed') AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.bed_space_id=b.id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP)");

    $roomsCreated = 0;
    $bedsCreated = 0;
    $assignmentsCreated = 0;
    $skipped = 0;

    begin_write($pdo);
    try {
        foreach ($rooms as $room) {
            $roomId = (int)$room['id'];
            $bedList->execute([$roomId]);
            $existingLabels = array_column($bedList->fetchAll(), 'bed_label');
            for ($bedNumber = 1; $bedNumber <= (int)$room['capacity']; $bedNumber++) {
                $label = 'Bed ' . $bedNumber;
                if (in_array($label, $existingLabels, true)) {
                    continue;
                }
                $bedInsert->execute([$roomId, $label]);
                $bedsCreated++;
            }
            $bedAvailable->execute([$roomId]);
            $bedsByRoom[$roomId] = array_map('intval', $bedAvailable->fetchAll(PDO::FETCH_COLUMN));
            $roomOccupants->execute([$roomId]);
            $occupants = $roomOccupants->fetchAll(PDO::FETCH_COLUMN);
            $roomGender[$roomId] = count($occupants) === 1 ? $occupants[0] : (count($occupants) > 1 ? '__mixed__' : null);
        }

        $createRoom = function () use (&$nextRoomNumber, &$roomsCreated, &$bedsCreated, &$bedsByRoom, &$roomGender, $hostelId, $roomCapacity, $roomInsert, $bedInsert, $pdo): int {
            do {
                $roomNumber = sprintf('Room-%03d', $nextRoomNumber++);
                $statement = $pdo->prepare('SELECT id FROM rooms WHERE hostel_id=? AND room_number=? LIMIT 1');
                $statement->execute([$hostelId, $roomNumber]);
            } while ($statement->fetchColumn());
            $roomInsert->execute([$hostelId, $roomNumber, $roomCapacity]);
            $newRoomId = (int)$pdo->lastInsertId();
            for ($bedNumber = 1; $bedNumber <= $roomCapacity; $bedNumber++) {
                $bedInsert->execute([$newRoomId, 'Bed ' . $bedNumber]);
                $bedsByRoom[$newRoomId][] = (int)$GLOBALS['pdo']->lastInsertId();
                $bedsCreated++;
            }
            $roomGender[$newRoomId] = null;
            $roomsCreated++;
            return $newRoomId;
        };

        $reservationInsert = $pdo->prepare("INSERT INTO reservations(student_user_id,bed_space_id,status,created_at,expires_at) VALUES(?,?,'confirmed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $allocationInsert = $pdo->prepare("INSERT INTO allocations(student_user_id,bed_space_id,reservation_id,status) VALUES(?,?,?,'confirmed')");

        foreach ($students as $student) {
            $studentId = (int)$student['user_id'];
            $gender = (string)$student['gender'];
            if ($hostel['gender_eligibility'] !== 'any' && $hostel['gender_eligibility'] !== $gender) {
                $skipped++;
                continue;
            }

            $selectedRoomId = null;
            $selectedBedId = null;
            foreach ($bedsByRoom as $candidateRoomId => $candidateBeds) {
                if (!$candidateBeds || ($roomGender[$candidateRoomId] !== null && $roomGender[$candidateRoomId] !== $gender)) {
                    continue;
                }
                $selectedRoomId = (int)$candidateRoomId;
                $selectedBedId = array_shift($bedsByRoom[$candidateRoomId]);
                break;
            }

            if ($selectedBedId === null) {
                $selectedRoomId = $createRoom();
                $selectedBedId = array_shift($bedsByRoom[$selectedRoomId]);
            }

            $roomGender[$selectedRoomId] = $gender;
            $reservationInsert->execute([$studentId, $selectedBedId]);
            $allocationInsert->execute([$studentId, $selectedBedId, (int)$pdo->lastInsertId()]);
            $assignmentsCreated++;
        }

        commit_write($pdo);
    } catch (Throwable $e) {
        rollback_write($pdo);
        throw $e;
    }

    fwrite(STDOUT, sprintf("Hostel seeding complete for %s. Rooms created: %d; beds created: %d; students assigned: %d; skipped: %d.\n", $hostel['name'], $roomsCreated, $bedsCreated, $assignmentsCreated, $skipped));
    fwrite(STDOUT, "Assignments use confirmed reservations and allocations; no payment records were created.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Hostel seeding failed: ' . $e->getMessage() . "\n");
    exit(1);
}