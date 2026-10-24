<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Services/CompatibilityService.php';
use SmartHostel\Services\CompatibilityService;

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(2);}
$path=sys_get_temp_dir().DIRECTORY_SEPARATOR.'smarthostel_seed_'.bin2hex(random_bytes(5)).'_test.sqlite';
putenv('APP_ENV=test');putenv('ALLOW_TEST_USER_SEEDING=1');putenv('SQLITE_DATABASE_PATH='.$path);
$pdo=db();$pdo->prepare("INSERT INTO users(email,password_hash,role) VALUES(?,?,'super_admin')")->execute(['preserved-admin@seed-test.invalid',password_hash('Preserve-this-admin-123!',PASSWORD_DEFAULT)]);
function checkSeed(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function seedOnce():array{$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/scripts/seed-test-users.php');$pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Could not launch seed script.');fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);return [$code,$stdout,$stderr];}
try{
    [$code,$stdout,$stderr]=seedOnce();checkSeed($code===0&&str_contains($stdout,'Created: 80; already present: 0; failed: 0'), 'first run creates 80 accounts');
    [$code,$stdout,$stderr]=seedOnce();checkSeed($code===0&&str_contains($stdout,'Created: 0; already present: 80; failed: 0'), 'repeat run is idempotent');
    $rows=$pdo->query("SELECT u.email,u.password_hash,p.matric_no,p.eligibility,a.sleep_time,a.study_time,a.cleanliness,a.noise_tolerance,a.social_style,a.guest_frequency FROM users u JOIN student_profiles p ON p.user_id=u.id JOIN student_answers a ON a.user_id=u.id WHERE u.email LIKE 'student___@test.smarthostel.local' ORDER BY u.email")->fetchAll();
    checkSeed(count($rows)===80,'all seeded users have profiles and complete questionnaires');
    checkSeed(count(array_unique(array_column($rows,'email')))===80&&count(array_unique(array_column($rows,'matric_no')))===80,'seeded emails and student IDs are unique');
    $hashes=array_values(array_unique(array_column($rows,'password_hash')));
    checkSeed(count($hashes)===1&&password_verify('SmartHostelTest2026!',$hashes[0]),'one shared secure hash verifies the password used by every seeded account');
    checkSeed(count(array_filter($rows,fn($r)=>$r['eligibility']==='eligible'))===80,'development seed profiles are ready for matching');
    $answers=$pdo->query('SELECT * FROM student_answers ORDER BY user_id')->fetchAll();$engine=new CompatibilityService();$same=$engine->compare($answers[0],$answers[2]);$different=$engine->compare($answers[0],$answers[40]);
    checkSeed($same['score']!==null&&$different['score']!==null&&$same['score']>$different['score'],'overlapping profile groups produce different compatibility scores');
    checkSeed((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn()===1,'existing administrator remains intact');
    echo "Test-user seeding checks passed.\n";
}finally{
    unset($pdo);foreach([$path,$path.'-wal',$path.'-shm'] as $file)if(is_file($file))@unlink($file);
}
