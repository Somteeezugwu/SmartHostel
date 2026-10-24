<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"This development utility is CLI only.\n");
    exit(2);
}

$environment=strtolower(trim(envv('APP_ENV')));
$enabled=strtolower(trim(envv('ALLOW_TEST_USER_SEEDING')));
if(!in_array($environment,['development','dev','test','testing'],true)||!in_array($enabled,['1','true','yes'],true)){
    fwrite(STDERR,"Seeding refused. Set APP_ENV=development (or test) and ALLOW_TEST_USER_SEEDING=1 in the process environment or local .env file.\n");
    exit(2);
}

const SHARED_TEST_PASSWORD='SmartHostelTest2026!';
$created=0;$already=0;$failed=0;
try{
    $pdo=db();
    $required=['users','student_profiles','student_answers'];
    $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($required as $table)if(!in_array($table,$tables,true))throw new RuntimeException('Required database table is missing: '.$table);
    $columns=$pdo->query('PRAGMA table_info(student_answers)')->fetchAll(PDO::FETCH_COLUMN,1);
    $answerFields=['user_id','sleep_time','study_time','cleanliness','noise_tolerance','social_style','guest_frequency'];
    foreach($answerFields as $field)if(!in_array($field,$columns,true))throw new RuntimeException('Questionnaire schema is missing field: '.$field);

    $femaleNames=['Amara','Chika','Dara','Efe','Fiyin','Gbemi','Hauwa','Ifeoma','Kamsi','Lara','Morenike','Nneka','Pemi','Remi','Sade','Tomi','Uju','Wura','Yewande','Zainab'];
    $maleNames=['Akin','Boma','Chidi','Dimeji','Emeka','Farouk','Gideon','Haruna','Ikenna','Jide','Kelechi','Lanre','Mubarak','Nnamdi','Obinna','Segun','Tunde','Uche','Yomi','Zubair'];
    $lastNames=['Adebayo','Afolayan','Ajayi','Akinyemi','Balogun','Bello','Chukwu','Danladi','Eze','Garba','Ibrahim','James','Kalu','Mohammed','Nwosu','Okafor','Olawale','Salami','Usman','Yakubu'];
    $faculties=['Arts and Humanities','Engineering','Science','Social Sciences','Education','Law','Management Sciences','Health Sciences'];
    $departments=['English','Computer Science','Biochemistry','Economics','Mathematics','Mass Communication','Public Administration','Microbiology'];
    $groups=[
        ['sleep_time'=>1320,'study_time'=>540,'cleanliness'=>5,'noise_tolerance'=>1,'social_style'=>2,'guest_frequency'=>1],
        ['sleep_time'=>1380,'study_time'=>600,'cleanliness'=>4,'noise_tolerance'=>2,'social_style'=>3,'guest_frequency'=>2],
        ['sleep_time'=>60,'study_time'=>960,'cleanliness'=>3,'noise_tolerance'=>4,'social_style'=>4,'guest_frequency'=>4],
        ['sleep_time'=>120,'study_time'=>1140,'cleanliness'=>2,'noise_tolerance'=>5,'social_style'=>5,'guest_frequency'=>5],
    ];
    $timeOffsets=[0,0,15,-15,30];$studyOffsets=[0,60,-60,120,-120];$ratingOffsets=[0,0,1,-1,0];
    $exists=$pdo->prepare('SELECT id,role FROM users WHERE email=?');
    $insertUser=$pdo->prepare('INSERT INTO users(email,password_hash,role) VALUES(?,?,\'student\')');
    $insertProfile=$pdo->prepare("INSERT INTO student_profiles(user_id,matric_no,first_name,last_name,faculty,department,course,academic_level,gender,eligibility) VALUES(?,?,?,?,?,?,?,?,?,'eligible')");
    $insertAnswers=$pdo->prepare('INSERT INTO student_answers(user_id,sleep_time,study_time,cleanliness,noise_tolerance,social_style,guest_frequency) VALUES(?,?,?,?,?,?,?)');
    $hash=password_hash(SHARED_TEST_PASSWORD,PASSWORD_DEFAULT);
    if(!password_verify(SHARED_TEST_PASSWORD,$hash))throw new RuntimeException('The test password hash did not verify.');

    begin_write($pdo);
    try{
        for($i=1;$i<=80;$i++){
            $email=sprintf('student%03d@test.smarthostel.local',$i);
            $exists->execute([$email]);
            if($exists->fetch()){$already++;continue;}
            $group=intdiv($i-1,20);$variant=($i-1)%5;$base=$groups[$group];
            $base['sleep_time']=($base['sleep_time']+$timeOffsets[$variant]+1440)%1440;
            $base['study_time']=($base['study_time']+$studyOffsets[$variant]+1440)%1440;
            foreach(['cleanliness','noise_tolerance','social_style','guest_frequency'] as $field)$base[$field]=max(1,min(5,$base[$field]+$ratingOffsets[$variant]));
            $gender=$i%2===0?'female':'male';$personNumber=intdiv($i-1,2);$givenNames=$gender==='female'?$femaleNames:$maleNames;
            $first=$givenNames[$personNumber%count($givenNames)];$last=$lastNames[(($personNumber%20)*7+intdiv($personNumber,20))%count($lastNames)];$uid=null;
            try{
                $insertUser->execute([$email,$hash]);$uid=(int)$pdo->lastInsertId();
                $insertProfile->execute([$uid,sprintf('SH-TEST-%04d',$i),$first,$last,$faculties[($i-1)%count($faculties)],$departments[($i-1)%count($departments)],'General Studies',((intdiv($i-1,8)%4)+1).'00 level',$gender]);
                $insertAnswers->execute([$uid,$base['sleep_time'],$base['study_time'],$base['cleanliness'],$base['noise_tolerance'],$base['social_style'],$base['guest_frequency']]);
                $created++;
            }catch(Throwable $e){$failed++;throw new RuntimeException('Could not create '.$email.': '.$e->getMessage(),0,$e);}
        }
        commit_write($pdo);
    }catch(Throwable $e){rollback_write($pdo);throw $e;}

    fwrite(STDOUT,"Test-user seeding complete. Created: $created; already present: $already; failed: $failed.\n");
    fwrite(STDOUT,"Login: student001@test.smarthostel.local through student080@test.smarthostel.local\nShared password: ".SHARED_TEST_PASSWORD."\n");
    fwrite(STDOUT,"Development test profiles are marked eligible for matching demonstrations; no school-fee verification or payment record was created.\n");
}catch(Throwable $e){
    if($failed===0)$failed=1;
    fwrite(STDERR,"Test-user seeding failed. Created: 0 committed; already present: $already; failed: $failed. ".$e->getMessage()."\n");
    exit(1);
}
