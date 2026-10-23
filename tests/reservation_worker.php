<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Services/ReservationService.php';
use SmartHostel\Services\ReservationService;
$path=getenv('SQLITE_DATABASE_PATH')?:'';
if(!str_ends_with(strtolower($path),'_test.sqlite')){fwrite(STDERR,"Refusing to run against a database not marked *_test.sqlite.\n");exit(2);}
$pdo=db();
$student=(int)($argv[1]??0);$bed=(int)($argv[2]??0);$barrier=$argv[3]??'';
$deadline=microtime(true)+10;while(!is_file($barrier)&&microtime(true)<$deadline)usleep(10000);if(!is_file($barrier)){fwrite(STDERR,"Concurrency test barrier timed out.\n");exit(2);}
try{$id=(new ReservationService($pdo))->reserve($student,$bed);echo "RESERVED:$id\n";exit(0);}catch(Throwable $e){echo "REJECTED:".$e->getMessage()."\n";exit(0);}
