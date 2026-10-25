<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Services/ReservationService.php';
require dirname(__DIR__).'/app/Services/ReservationPaymentService.php';
use SmartHostel\Services\ReservationPaymentService;
use SmartHostel\Services\ReservationService;

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(2);}
$testDb=sys_get_temp_dir().DIRECTORY_SEPARATOR.'smarthostel_workflow_'.bin2hex(random_bytes(5)).'_test.sqlite';
putenv('APP_ENV=development');putenv('ALLOW_DEVELOPMENT_PAYMENT_REVIEW=1');putenv('SQLITE_DATABASE_PATH='.$testDb);
$pdo=db();
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";}
function makeUser(PDO $pdo,string $tag,string $role='student'):int{$q=$pdo->prepare('INSERT INTO users(email,password_hash,role) VALUES(?,?,?)');$q->execute([$tag.'@workflow-test.invalid',password_hash('Test-only-password-123!',PASSWORD_DEFAULT),$role]);$id=(int)$pdo->lastInsertId();if($role==='student'){$q=$pdo->prepare("INSERT INTO student_profiles(user_id,matric_no,first_name,last_name,gender,eligibility) VALUES(?,?,?,?,'female','eligible')");$q->execute([$id,'WF/'.$tag,$tag,'Test']);}return $id;}
function scalar(PDO $pdo,string $sql,array $args=[]):mixed{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

$tag='wf'.bin2hex(random_bytes(5));$ids=[];$hostelId=null;$roomId=null;
try{
    putenv('APP_ENV=production');verify(!ReservationPaymentService::developmentReviewEnabled(),'development payment review is disabled in production');
    try{(new ReservationPaymentService($pdo))->submit(0,1,'12345678','1.00');verify(false,'backend cannot submit a simulated payment when development mode is disabled');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'disabled'),'backend cannot submit a simulated payment when development mode is disabled');}
    putenv('APP_ENV=development');verify(ReservationPaymentService::developmentReviewEnabled(),'development payment review requires the explicit local opt-in');
    verify(ReservationPaymentService::normalizeReference(' 1234-5678-9012 ')==='123456789012','RRR display separators normalize consistently');
    try{ReservationPaymentService::normalizeReference('not valid!');verify(false,'malformed RRR characters are rejected');}catch(RuntimeException){verify(true,'malformed RRR characters are rejected');}
    verify((int)$pdo->query('PRAGMA user_version')->fetchColumn()===2,'SQLite schema is automatically initialized at version 2');
    verify((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='contact_messages'")->fetchColumn()===1,'contact messages table is present in the migrated schema');
    verify((int)$pdo->query('SELECT COUNT(*) FROM matching_weights')->fetchColumn()===6,'default matching weights are seeded exactly once');
    $admin=makeUser($pdo,$tag.'admin','super_admin');$ids[]=$admin;
    for($i=1;$i<=8;$i++)$ids[$i]=makeUser($pdo,$tag.'s'.$i);
    $pdo->prepare("INSERT INTO hostels(name,gender_eligibility,status) VALUES(?,'female','active')")->execute([$tag.' Hall']);$hostelId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO rooms(hostel_id,room_number,capacity,status) VALUES(?,?,8,'active')")->execute([$hostelId,'WF-1']);$roomId=(int)$pdo->lastInsertId();
    $beds=[];for($i=1;$i<=8;$i++){$pdo->prepare("INSERT INTO bed_spaces(room_id,bed_label,status) VALUES(?,?,'active')")->execute([$roomId,'Bed '.$i]);$beds[$i]=(int)$pdo->lastInsertId();}

    // Two independent PHP processes contend for one SQLite bed using BEGIN IMMEDIATE.
    $barrier=sys_get_temp_dir().DIRECTORY_SEPARATOR.$tag.'.go';$workers=[];
    for($i=1;$i<=2;$i++){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/reservation_worker.php').' '.(int)$ids[$i].' '.$beds[1].' '.escapeshellarg($barrier);$pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Could not start concurrent reservation worker.');fclose($pipes[0]);$workers[]=[$proc,$pipes[1],$pipes[2]];}
    touch($barrier);$results=[];foreach($workers as [$proc,$stdout,$stderr]){$out=stream_get_contents($stdout);$err=stream_get_contents($stderr);fclose($stdout);fclose($stderr);$exit=proc_close($proc);if($exit!==0)throw new RuntimeException('Reservation worker failed: '.$err.$out);$results[]=$out;}@unlink($barrier);
    $wins=count(array_filter($results,fn($x)=>str_starts_with($x,'RESERVED:')));verify($wins===1,'simultaneous requests for one bed yield exactly one hold ('.implode(' | ',array_map('trim',$results)).')');
    $winner=(int)scalar($pdo,"SELECT student_user_id FROM reservations WHERE bed_space_id=? AND status='pending_payment'",[$beds[1]]);$loser=$winner===$ids[1]?$ids[2]:$ids[1];
    try{(new ReservationService($pdo))->reserve($loser,$beds[1]);verify(false,'held bed cannot be reserved again');}catch(RuntimeException){verify(true,'held bed cannot be reserved again');}

    $now=new DateTimeImmutable('2035-01-01 12:00:00',new DateTimeZone('UTC'));$clock=static function()use(&$now):DateTimeImmutable{return $now;};
    $reservations=new ReservationService($pdo,48,$clock);$payments=new ReservationPaymentService($pdo,$clock);
    $expiryReservation=$reservations->reserve($ids[3],$beds[2]);$record=$pdo->query('SELECT created_at,expires_at FROM reservations WHERE id='.(int)$expiryReservation)->fetch();
    verify($record['expires_at']===(new DateTimeImmutable($record['created_at'],new DateTimeZone('UTC')))->modify('+48 hours')->format('Y-m-d H:i:s'),'hold expiry is exactly 48 hours after its creation time');
    $expiredPayment=$payments->submit($ids[3],$expiryReservation,$tag.'-expire-rrr','123.00');
    $now=$now->modify('+48 hours');verify($reservations->expire()===1,'expired hold transitions at the 48-hour boundary');
    verify(scalar($pdo,'SELECT status FROM payments WHERE id=?',[$expiredPayment])==='cancelled','unreviewed payment is closed when its hold expires');
    try{$payments->review($admin,$expiredPayment,'verified');verify(false,'expired payment cannot confirm allocation');}catch(RuntimeException){verify(true,'expired payment cannot confirm allocation');}
    $released=$reservations->reserve($ids[4],$beds[2]);verify((bool)$released,'expired bed is available to another eligible student');

    $cancelReservation=$reservations->reserve($ids[5],$beds[3]);$cancelPayment=$payments->submit($ids[5],$cancelReservation,$tag.'-cancel-rrr','123.00');
    $reservations->cancel($ids[5],$cancelReservation);
    verify(scalar($pdo,'SELECT status FROM reservations WHERE id=?',[$cancelReservation])==='cancelled','student can cancel their active hold');
    verify(scalar($pdo,'SELECT status FROM payments WHERE id=?',[$cancelPayment])==='cancelled','cancellation closes pending payment evidence');
    verify((bool)$reservations->reserve($ids[6],$beds[3]),'cancelled bed is released to another student');
    try{$reservations->cancel($ids[6],$cancelReservation);verify(false,'student cannot cancel another student reservation');}catch(RuntimeException){verify(true,'student cannot cancel another student reservation');}

    $finalReservation=$reservations->reserve($ids[7],$beds[4]);$rejected=$payments->submit($ids[7],$finalReservation,$tag.'-rejected-rrr','123.00');
    verify((int)scalar($pdo,"SELECT COUNT(*) FROM allocations WHERE reservation_id=?",[$finalReservation])===0,'pending payment creates no allocation');
    $payments->review($admin,$rejected,'rejected','Test rejection');verify((int)scalar($pdo,"SELECT COUNT(*) FROM allocations WHERE reservation_id=?",[$finalReservation])===0,'rejected payment creates no allocation');
    $verified=$payments->submit($ids[7],$finalReservation,$tag.'-verified-rrr','123.00');
    $payments->review($admin,$verified,'verified','Test approval');
    verify(scalar($pdo,'SELECT status FROM reservations WHERE id=?',[$finalReservation])==='confirmed','verified payment confirms reservation');
    verify((int)scalar($pdo,"SELECT COUNT(*) FROM allocations WHERE bed_space_id=? AND status='confirmed'",[$beds[4]])===1,'verified payment creates exactly one allocation');
    try{$reservations->reserve($loser,$beds[4]);verify(false,'confirmed bed cannot be held again');}catch(RuntimeException){verify(true,'confirmed bed cannot be held again');}
    $pdo->prepare("INSERT INTO reservations(student_user_id,bed_space_id,status,created_at,expires_at) VALUES(?,?,'cancelled',?,?)")->execute([$loser,$beds[4],$now->format('Y-m-d H:i:s'),$now->modify('+48 hours')->format('Y-m-d H:i:s')]);$otherReservation=(int)$pdo->lastInsertId();
    try{$pdo->prepare("INSERT INTO allocations(student_user_id,bed_space_id,reservation_id,status) VALUES(?,?,?,'confirmed')")->execute([$loser,$beds[4],$otherReservation]);verify(false,'database rejects a second confirmed allocation for a bed');}catch(PDOException){verify(true,'database rejects a second confirmed allocation for a bed');}
    $duplicateReservation=$reservations->reserve($ids[8],$beds[6]);
    try{$payments->submit($ids[8],$duplicateReservation,$tag.'-verified-rrr','123.00');verify(false,'duplicate transaction reference is rejected');}catch(RuntimeException){verify(true,'duplicate transaction reference is rejected');}
    try{$payments->review($ids[1],$rejected,'verified');verify(false,'ordinary student cannot review payment');}catch(RuntimeException){verify(true,'only a super administrator can review payment');}

    echo "Allocation workflow integration checks passed.\n";
} finally {
    if($roomId!==null){$pdo->prepare('DELETE FROM allocations WHERE bed_space_id IN (SELECT id FROM bed_spaces WHERE room_id=?)')->execute([$roomId]);$pdo->prepare('DELETE FROM payments WHERE reservation_id IN (SELECT id FROM reservations WHERE bed_space_id IN (SELECT id FROM bed_spaces WHERE room_id=?))')->execute([$roomId]);$pdo->prepare('DELETE FROM reservations WHERE bed_space_id IN (SELECT id FROM bed_spaces WHERE room_id=?)')->execute([$roomId]);$pdo->prepare('DELETE FROM bed_spaces WHERE room_id=?')->execute([$roomId]);$pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([$roomId]);}
    if($hostelId!==null)$pdo->prepare('DELETE FROM hostels WHERE id=?')->execute([$hostelId]);
    foreach($ids as $id){$pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id=?')->execute([$id]);$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
    unset($pdo);foreach([$testDb,$testDb.'-wal',$testDb.'-shm'] as $file)if(is_file($file))@unlink($file);
}
