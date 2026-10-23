<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);}
$dsn=getenv('MYSQL_DSN')?:'';
if($dsn===''||!in_array('mysql',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"Set MYSQL_DSN and ensure pdo_mysql is enabled to use this optional importer. The source database is read only.\n");exit(1);}
$target=db();
$tables=['users','hostels','rooms','bed_spaces','student_profiles','academic_sessions','school_fee_verifications','student_answers','matching_weights','reservations','payments','allocations','notifications','audit_logs'];
foreach($tables as $table){if($table==='matching_weights')continue;$count=(int)$target->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();if($count!==0){fwrite(STDERR,"SQLite target contains data; refusing to merge or overwrite it. Back it up and use a fresh SQLite path.\n");exit(1);}}
$source=new PDO($dsn,getenv('MYSQL_USER')?:'',getenv('MYSQL_PASS')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
$target->exec('PRAGMA foreign_keys=OFF');$counts=[];
try {
 begin_write($target);
 foreach($tables as $table){
  $targetColumns=array_column($target->query('PRAGMA table_info("'.$table.'")')->fetchAll(),'name');
  $rows=$source->query('SELECT * FROM `'.$table.'`')->fetchAll();
  if($table==='matching_weights')$target->exec('DELETE FROM matching_weights');
  $count=0;
  foreach($rows as $row){$data=array_intersect_key($row,array_flip($targetColumns));if(!$data)continue;$columns=array_keys($data);$sql='INSERT INTO "'.$table.'" ('.implode(',',array_map(static fn($c)=>'"'.$c.'"',$columns)).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';$target->prepare($sql)->execute(array_values($data));$count++;}
  $counts[$table]=$count;
 }
 $violations=$target->query('PRAGMA foreign_key_check')->fetchAll();if($violations)throw new RuntimeException('Imported rows contain invalid foreign-key relationships.');
 commit_write($target);
}catch(Throwable $e){rollback_write($target);$target->exec('PRAGMA foreign_keys=ON');fwrite(STDERR,"Import failed; SQLite changes were rolled back. ".$e->getMessage()."\n");exit(1);}
$target->exec('PRAGMA foreign_keys=ON');
foreach($counts as $table=>$count)fwrite(STDOUT,sprintf("%-26s %d rows imported\n",$table,$count));
fwrite(STDOUT,"Import complete. The MySQL source was not modified.\n");
