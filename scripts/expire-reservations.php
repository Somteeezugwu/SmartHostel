<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Services/ReservationService.php';
$count=(new SmartHostel\Services\ReservationService(db(),(int)envv('RESERVATION_HOURS','48')))->expire();fwrite(STDOUT,$count." reservation(s) expired and pending payment reviews released.\n");
