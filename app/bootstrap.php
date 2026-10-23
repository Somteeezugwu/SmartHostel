<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
if(PHP_SAPI!=='cli'&&session_status()!==PHP_SESSION_ACTIVE){$secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax']);session_start();}
function envv(string $key,string $default=''): string { $fromProcess=getenv($key);if($fromProcess!==false)return (string)$fromProcess;static $v=null;if($v===null){$v=[];$f=dirname(__DIR__).'/.env';if(is_file($f))foreach(file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){if(str_starts_with(trim($line),'#')||!str_contains($line,'='))continue;[$k,$x]=explode('=',$line,2);$v[trim($k)]=trim($x," \t\n\r\0\x0B\"");}}return $v[$key]??$default;}
function sqlite_path(): string {
    $configured=envv('SQLITE_DATABASE_PATH','storage/smarthostel.sqlite');
    if($configured===''||str_contains($configured,"\0"))throw new RuntimeException('SQLite database path is invalid.');
    if(!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/',$configured))$configured=dirname(__DIR__).DIRECTORY_SEPARATOR.$configured;
    return $configured;
}
function initialize_sqlite(PDO $pdo): void {
    $version=(int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if($version>1)throw new RuntimeException('This database was created by a newer application version.');
    if($version===1)return;
    $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
    if($schema===false)throw new RuntimeException('SQLite schema is unavailable.');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version=(int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if($version===0){$pdo->exec($schema);$pdo->exec('PRAGMA user_version=1');}
        if($pdo->inTransaction())$pdo->commit();else $pdo->exec('COMMIT');
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();else try{$pdo->exec('ROLLBACK');}catch(Throwable){}throw $e;}
}
function db(): PDO {
    static $p;if($p instanceof PDO)return $p;
    try {
        $path=sqlite_path();$dir=dirname($path);
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Could not create the local database directory.');
        $realDir=realpath($dir);$publicRoot=realpath(dirname(__DIR__).'/public');
        if($realDir===false||$publicRoot===false||str_starts_with(strtolower($realDir.DIRECTORY_SEPARATOR),strtolower($publicRoot.DIRECTORY_SEPARATOR)))throw new RuntimeException('The SQLite database must be stored outside the public web directory.');
        $p=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10]);
        $p->exec('PRAGMA foreign_keys=ON');$p->exec('PRAGMA busy_timeout=10000');$p->exec('PRAGMA journal_mode=WAL');$p->exec('PRAGMA synchronous=NORMAL');
        initialize_sqlite($p);return $p;
    } catch(Throwable $e) {error_log('SQLite initialization failed: '.$e->getMessage());$p=null;throw new RuntimeException('The local database could not be opened or initialized. Check that the database folder is writable and PDO SQLite is enabled.',0,$e);}
}
function begin_write(PDO $pdo): void {
    for($attempt=0;;$attempt++)try{$pdo->exec('BEGIN IMMEDIATE');return;}catch(PDOException $e){$busy=str_contains(strtolower($e->getMessage()),'locked')||str_contains(strtolower($e->getMessage()),'busy');if(!$busy||$attempt>=4)throw $e;usleep(50000*(2**$attempt));}
}
function commit_write(PDO $pdo): void {$pdo->exec('COMMIT');}
function rollback_write(PDO $pdo): void {try{$pdo->exec('ROLLBACK');}catch(Throwable){}}
function out(mixed $v,int $code=200): never {http_response_code($code);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($v,JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function input(): array {$x=json_decode(file_get_contents('php://input'),true);return is_array($x)?$x:$_POST;}
function csrf(): string{return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function check_csrf(): void{$t=$_SERVER['HTTP_X_CSRF_TOKEN']??input()['_csrf']??'';if(!hash_equals(csrf(),(string)$t))out(['error'=>'Session token expired; refresh and retry.'],419);}
function current_user(): ?array{return $_SESSION['user']??null;}
function auth(): array{$u=current_user();if(!$u)out(['error'=>'Please sign in.'],401);$s=db()->prepare('SELECT id,email,role FROM users WHERE id=?');$s->execute([(int)$u['id']]);$fresh=$s->fetch();if(!$fresh){$_SESSION=[];session_destroy();out(['error'=>'Please sign in again.'],401);}$_SESSION['user']=['id'=>(int)$fresh['id'],'email'=>$fresh['email'],'role'=>$fresh['role']];return $_SESSION['user'];}
function role(string ...$roles): array{$u=auth();if(!in_array($u['role'],$roles,true))out(['error'=>'Permission denied.'],403);return $u;}
function audit(PDO $p,int $who,string $action,string $type,?int $id,array $details=[]):void{$s=$p->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)');$s->execute([$who,$action,$type,$id,json_encode($details)]);}
function note(PDO $p,int $uid,string $title,string $message):void{$s=$p->prepare('INSERT INTO notifications(user_id,title,message) VALUES(?,?,?)');$s->execute([$uid,$title,$message]);}
