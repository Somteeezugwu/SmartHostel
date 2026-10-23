<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(1);} $email=$argv[1]??'';if(!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php scripts/create-admin.php admin@example.edu.ng\n");exit(1);}
$p=db();if((int)$p->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')")->fetchColumn()>0){fwrite(STDERR,"An administrator already exists.\n");exit(1);}fwrite(STDOUT,'New admin password: ');$pw=trim((string)fgets(STDIN));if(strlen($pw)<12){fwrite(STDERR,"Use at least 12 characters.\n");exit(1);}$s=$p->prepare("INSERT INTO users(email,password_hash,role) VALUES(?,?,'super_admin')");$s->execute([$email,password_hash($pw,PASSWORD_DEFAULT)]);fwrite(STDOUT,"Administrator created.\n");
