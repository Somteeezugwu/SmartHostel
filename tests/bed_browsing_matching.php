<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(2);}
$testDb=sys_get_temp_dir().DIRECTORY_SEPARATOR.'smarthostel_browse_match_'.bin2hex(random_bytes(5)).'_test.sqlite';
putenv('SQLITE_DATABASE_PATH='.$testDb);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Services/ReservationService.php';
require dirname(__DIR__).'/app/Services/BedAvailabilityService.php';
require dirname(__DIR__).'/app/Services/CompatibilityService.php';
require dirname(__DIR__).'/app/Services/RoommateMatchService.php';
use SmartHostel\Services\BedAvailabilityService;
use SmartHostel\Services\CompatibilityService;
use SmartHostel\Services\ReservationService;
use SmartHostel\Services\RoommateMatchService;

function featureCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
function featureUser(PDO $pdo,string $suffix,string $gender='female'):int {
    $q=$pdo->prepare("INSERT INTO users(email,password_hash,role) VALUES(?,?,'student')");
    $q->execute([$suffix.'@feature-test.invalid',password_hash('Feature-only-password-123!',PASSWORD_DEFAULT)]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO student_profiles(user_id,matric_no,first_name,last_name,gender,eligibility) VALUES(?,?,?,? ,?,'eligible')")
        ->execute([$id,'FT/'.$suffix,$suffix,'Test',$gender]);
    return $id;
}
function addAnswers(PDO $pdo,int $id,array $answers):void {
    $pdo->prepare('INSERT INTO student_answers(user_id,sleep_time,study_time,cleanliness,noise_tolerance,social_style,guest_frequency) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,...$answers]);
}

$pdo=db();$ids=[];$hostelIds=[];$roomIds=[];$reservationIds=[];
try{
    $tag='ft'.bin2hex(random_bytes(4));
    $me=featureUser($pdo,$tag.'me');$ids[]=$me;
    $same=featureUser($pdo,$tag.'same');$ids[]=$same;
    $partial=featureUser($pdo,$tag.'partial');$ids[]=$partial;
    $different=featureUser($pdo,$tag.'different');$ids[]=$different;
    $male=featureUser($pdo,$tag.'male','male');$ids[]=$male;
    $prefs=[1380,600,4,3,5,2];
    addAnswers($pdo,$me,$prefs);addAnswers($pdo,$same,$prefs);addAnswers($pdo,$different,[720,0,1,1,1,1]);
    $pdo->prepare('INSERT INTO student_answers(user_id,cleanliness,noise_tolerance) VALUES(?,?,?)')->execute([$partial,4,3]);

    $pdo->prepare("INSERT INTO hostels(name,description,location,gender_eligibility,status) VALUES(?,?,?,'female','active')")->execute([$tag.' Hall','Testing block','North campus']);$hostel=(int)$pdo->lastInsertId();$hostelIds[]=$hostel;
    $pdo->prepare("INSERT INTO hostels(name,gender_eligibility,status) VALUES(?,'female','active')")->execute([$tag.' Empty']);$emptyHostel=(int)$pdo->lastInsertId();$hostelIds[]=$emptyHostel;
    $pdo->prepare("INSERT INTO hostels(name,gender_eligibility,status) VALUES(?,'male','active')")->execute([$tag.' Male']);$maleHostel=(int)$pdo->lastInsertId();$hostelIds[]=$maleHostel;
    $pdo->prepare("INSERT INTO rooms(hostel_id,room_number,capacity,status) VALUES(?,?,4,'active')")->execute([$hostel,'A-1']);$room=(int)$pdo->lastInsertId();$roomIds[]=$room;
    $pdo->prepare("INSERT INTO rooms(hostel_id,room_number,capacity,status) VALUES(?,?,1,'active')")->execute([$emptyHostel,'E-1']);$emptyRoom=(int)$pdo->lastInsertId();$roomIds[]=$emptyRoom;
    $beds=[];foreach(['Bed 1','Bed 2','Bed 3','Bed 4'] as $label){$pdo->prepare("INSERT INTO bed_spaces(room_id,bed_label,status) VALUES(?,?,'active')")->execute([$room,$label]);$beds[]=(int)$pdo->lastInsertId();}
    $pdo->prepare("INSERT INTO bed_spaces(room_id,bed_label,status) VALUES(?,'Inactive','inactive')")->execute([$emptyRoom]);
    $reserve=new ReservationService($pdo);
    $holder=featureUser($pdo,$tag.'holder');$ids[]=$holder;$reservationIds[]=$reserve->reserve($holder,$beds[0]);
    $allocated=featureUser($pdo,$tag.'allocated');$ids[]=$allocated;$reservationIds[]=$reserve->reserve($allocated,$beds[1]);
    $pdo->prepare("UPDATE reservations SET status='confirmed' WHERE id=?")->execute([$reservationIds[1]]);
    $pdo->prepare("INSERT INTO allocations(student_user_id,bed_space_id,reservation_id,status) VALUES(?,?,?,'confirmed')")->execute([$allocated,$beds[1],$reservationIds[1]]);

    $inventory=new BedAvailabilityService($pdo);
    $hostels=$inventory->hostels($me);
    featureCheck(count($hostels)===1&&(int)$hostels[0]['available_beds']===2&&(int)$hostels[0]['available_rooms']===1,'hostel list omits empty, ineligible, held, and allocated inventory and reports live counts');
    $rooms=$inventory->rooms($me,$hostel);
    featureCheck(count($rooms)===1&&(int)$rooms[0]['available_beds']===2,'room list is scoped to selected hostel and counts available beds');
    featureCheck($inventory->rooms($me,$emptyHostel)===[],'room with no available beds is hidden');
    featureCheck($inventory->beds($me,$emptyHostel,$room)===[],'room ID cannot be browsed under a different hostel ID');
    $listed=$inventory->beds($me,$hostel,$room);
    featureCheck(count($listed)===2,'only unheld and unallocated active beds are selectable');
    $racer=featureUser($pdo,$tag.'racer');$ids[]=$racer;
    try{$reservationIds[]=$reserve->reserve($racer,$beds[2]);featureCheck(true,'first reservation claims a bed after it was listed');}catch(Throwable $e){featureCheck(false,'first reservation claims a bed after it was listed: '.$e->getMessage());}
    try{$reserve->reserve($me,$beds[2]);featureCheck(false,'bed becoming unavailable between browse and reserve is rejected');}catch(RuntimeException){featureCheck(true,'bed becoming unavailable between browse and reserve is rejected');}
    featureCheck(count($inventory->beds($me,$hostel,$room))===1,'availability is refreshed after a competing hold');

    $weights=$pdo->query('SELECT criterion_name,weight FROM matching_weights WHERE enabled=1')->fetchAll(PDO::FETCH_KEY_PAIR);
    $matcher=new RoommateMatchService($pdo,new CompatibilityService($weights));
    $result=$matcher->find($me);
    featureCheck($result['questionnaire_complete']&&count($result['matches'])===2,'complete eligible same-gender candidates are returned without any bed reservation');
    featureCheck($result['matches'][0]['first_name']===$tag.'same'&&$result['matches'][0]['score']===100,'candidate ranking uses actual stored preference answers and yields the identical-preference score');
    featureCheck(!in_array($tag.'me',array_column($result['matches'],'first_name'),true),'current student is excluded from their own matches');
    $pdo->prepare('DELETE FROM student_answers WHERE user_id=?')->execute([$me]);
    $incomplete=$matcher->find($me);
    featureCheck($incomplete['reason']==='questionnaire_incomplete'&&count($incomplete['missing_preferences'])===6,'missing current questionnaire answers produce an explicit completion state');
    addAnswers($pdo,$me,$prefs);
    $pdo->prepare("UPDATE student_profiles SET eligibility='ineligible' WHERE user_id IN (?,?)")->execute([$same,$different]);
    featureCheck($matcher->find($me)['reason']==='candidates_incomplete','eligible peers with incomplete questionnaires produce a distinct empty-state reason');
    $pdo->prepare("UPDATE student_profiles SET eligibility='ineligible' WHERE user_id IN (?,?,?,?)")->execute([$partial,$racer,$holder,$allocated]);
    $none=$matcher->find($me);
    featureCheck($none['reason']==='no_eligible_students','no eligible peers produce a distinct empty-state reason');
    echo "Bed browsing and roommate matching checks passed.\n";
}finally{
    foreach($reservationIds as $id){$pdo->prepare('DELETE FROM allocations WHERE reservation_id=?')->execute([$id]);$pdo->prepare('DELETE FROM payments WHERE reservation_id=?')->execute([$id]);$pdo->prepare('DELETE FROM reservations WHERE id=?')->execute([$id]);}
    foreach($roomIds as $id){$pdo->prepare('DELETE FROM bed_spaces WHERE room_id=?')->execute([$id]);$pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([$id]);}
    foreach($hostelIds as $id)$pdo->prepare('DELETE FROM hostels WHERE id=?')->execute([$id]);
    foreach($ids as $id)$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    unset($pdo);foreach([$testDb,$testDb.'-wal',$testDb.'-shm'] as $file)if(is_file($file))@unlink($file);
}
