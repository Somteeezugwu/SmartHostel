<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/CompatibilityService.php';
require dirname(__DIR__) . '/app/Services/RoommateMatchService.php';
require dirname(__DIR__) . '/app/Services/RoomSwitchService.php';

use SmartHostel\Services\CompatibilityService;
use SmartHostel\Services\RoommateMatchService;
use SmartHostel\Services\RoomSwitchService;

function assertTrue(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $label);
    }
    echo "PASS: $label\n";
}

function assertEquals($actual, $expected, string $label): void {
    $ok = $actual == $expected;
    if (!$ok) {
        throw new RuntimeException('Failed: ' . $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
    echo "PASS: $label\n";
}

function boot(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Unable to read schema.sql');
    }
    $pdo->exec($schema);
    return $pdo;
}

$pdo = boot();

$pdo->exec("INSERT INTO users(id,email,password_hash,role) VALUES(1,'student1@example.com','hash','student')");
$pdo->exec("INSERT INTO users(id,email,password_hash,role) VALUES(2,'student2@example.com','hash','student')");
$pdo->exec("INSERT INTO users(id,email,password_hash,role) VALUES(3,'admin@example.com','hash','super_admin')");
$pdo->exec("INSERT INTO student_profiles(user_id,matric_no,first_name,last_name,gender,eligibility) VALUES(1,'S001','Alice','Sample','female','eligible')");
$pdo->exec("INSERT INTO student_profiles(user_id,matric_no,first_name,last_name,gender,eligibility) VALUES(2,'S002','Beth','Sample','female','eligible')");
$pdo->exec("INSERT INTO student_answers(user_id,sleep_time,study_time,cleanliness,noise_tolerance,social_style,guest_frequency) VALUES(1,420,540,4,3,4,3)");
$pdo->exec("INSERT INTO student_answers(user_id,sleep_time,study_time,cleanliness,noise_tolerance,social_style,guest_frequency) VALUES(2,450,600,4,3,4,3)");

$pdo->exec("INSERT INTO hostels(id,name,gender_eligibility,status) VALUES(1,'Mabel Hostel','female','active')");
$pdo->exec("INSERT INTO rooms(id,hostel_id,room_number,capacity,status) VALUES(1,1,'Room 1',2,'active')");
$pdo->exec("INSERT INTO rooms(id,hostel_id,room_number,capacity,status) VALUES(2,1,'Room 24',2,'active')");
$pdo->exec("INSERT INTO bed_spaces(id,room_id,bed_label,status) VALUES(1,1,'Bed 1','active')");
$pdo->exec("INSERT INTO bed_spaces(id,room_id,bed_label,status) VALUES(2,1,'Bed 2','active')");
$pdo->exec("INSERT INTO bed_spaces(id,room_id,bed_label,status) VALUES(3,2,'Bed 1','active')");
$pdo->exec("INSERT INTO bed_spaces(id,room_id,bed_label,status) VALUES(4,2,'Bed 2','active')");

$pdo->exec("INSERT INTO reservations(id,student_user_id,bed_space_id,status,created_at,expires_at) VALUES(1,1,1,'confirmed','2026-01-01 00:00:00','2027-01-01 00:00:00')");
$pdo->exec("INSERT INTO reservations(id,student_user_id,bed_space_id,status,created_at,expires_at) VALUES(2,2,3,'confirmed','2026-01-01 00:00:00','2027-01-01 00:00:00')");
$pdo->exec("INSERT INTO allocations(id,student_user_id,bed_space_id,reservation_id,status) VALUES(1,1,1,1,'confirmed')");
$pdo->exec("INSERT INTO allocations(id,student_user_id,bed_space_id,reservation_id,status) VALUES(2,2,3,2,'confirmed')");

$engine = new CompatibilityService();
$matches = (new RoommateMatchService($pdo, $engine))->find(1);
assertEquals($matches['matches'][0]['user_id'] ?? 0, 2, 'eligible same-gender matching includes the target student');
assertTrue($matches['matches'][0]['switch_possible'] ?? false, 'candidate with an open bed in their occupied room is switch-compatible');

$service = new RoomSwitchService($pdo, $engine);
$options = $service->switchOptions(1, 2);
assertEquals((int)($options['beds'][0]['id'] ?? 0), 4, 'switch options return the actual open bed in the target student room');
$request = $service->requestSwitch(1, 2, 4);
assertEquals($request['status'] ?? null, 'pending', 'switch request is created in pending state');
assertEquals((int)$pdo->query("SELECT bed_space_id FROM allocations WHERE student_user_id=1 AND status='confirmed'")->fetchColumn(), 1, 'request leaves the current allocation unchanged pending administrator approval');
assertEquals($service->studentRequests(1)[0]['direction'] ?? null, 'outgoing', 'requesting student sees the outgoing request');
assertEquals($service->studentRequests(2)[0]['direction'] ?? null, 'incoming', 'target student sees the incoming request');
assertEquals($service->adminRequests()[0]['status'] ?? null, 'pending', 'administrator listing includes the pending request');
try {
    $pdo->exec("INSERT INTO room_switch_requests(student_user_id,target_student_user_id,from_bed_space_id,to_bed_space_id,status) VALUES(2,1,3,4,'pending')");
    assertTrue(false, 'database rejects competing pending requests for one destination bed');
} catch (PDOException) {
    assertTrue(true, 'database rejects competing pending requests for one destination bed');
}
assertTrue(!empty($service->requestSwitch(1, 2, 4)['error'] ?? null), 'duplicate switch request is rejected');

$approved = $service->reviewSwitch(3, $request['id'], 'approved', 'Approved for room change');
assertEquals($approved['status'] ?? null, 'approved', 'admin approval records the request as approved');
assertEquals((int) $pdo->query("SELECT COUNT(*) FROM allocations WHERE student_user_id=1 AND status='confirmed'")->fetchColumn(), 1, 'requester retains exactly one confirmed allocation');
assertEquals((int) $pdo->query("SELECT COUNT(*) FROM allocations WHERE bed_space_id=4 AND status='confirmed'")->fetchColumn(), 1, 'selected bed is allocated exactly once');
assertEquals((int) $pdo->query("SELECT COUNT(*) FROM allocations WHERE bed_space_id=1 AND status='confirmed'")->fetchColumn(), 0, 'old bed is released after approval');
assertEquals($pdo->query("SELECT status FROM reservations WHERE id=1")->fetchColumn(), 'cancelled', 'old confirmed reservation is cancelled after approval');

$pdo->exec("INSERT INTO users(id,email,password_hash,role) VALUES(4,'student3@example.com','hash','student')");
$pdo->exec("INSERT INTO student_profiles(user_id,matric_no,first_name,last_name,gender,eligibility) VALUES(4,'S003','Cleo','Sample','female','eligible')");
$pdo->exec("INSERT INTO student_answers(user_id,sleep_time,study_time,cleanliness,noise_tolerance,social_style,guest_frequency) VALUES(4,450,540,4,3,4,3)");
$pdo->exec("INSERT INTO rooms(id,hostel_id,room_number,capacity,status) VALUES(3,1,'Room 30',1,'active')");
$pdo->exec("INSERT INTO bed_spaces(id,room_id,bed_label,status) VALUES(5,3,'Bed 1','active')");
$pdo->exec("INSERT INTO reservations(student_user_id,bed_space_id,status,created_at,expires_at) VALUES(4,5,'confirmed','2026-01-01 00:00:00','2027-01-01 00:00:00')");
$pdo->exec("INSERT INTO allocations(student_user_id,bed_space_id,reservation_id,status) VALUES(4,5,(SELECT id FROM reservations WHERE student_user_id=4 AND bed_space_id=5 LIMIT 1),'confirmed')");

$fullRoomMatches = (new RoommateMatchService($pdo, $engine))->find(1)['matches'];
$fullRoomMatch = array_values(array_filter($fullRoomMatches, static fn(array $match): bool => $match['user_id'] === 4))[0] ?? null;
assertTrue($fullRoomMatch !== null && $fullRoomMatch['allocated'] && !$fullRoomMatch['room_available'] && !$fullRoomMatch['switch_possible'], 'allocated compatible match in a full room has no switch availability');

$blocked = $service->requestSwitch(1, 4, 5);
assertTrue(!empty($blocked['error']) || $blocked['status'] === 'rejected', 'room full or unavailable switch is blocked');

echo "All room switching flow checks passed.\n";
