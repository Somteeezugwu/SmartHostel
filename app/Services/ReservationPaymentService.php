<?php
declare(strict_types=1);
namespace SmartHostel\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Development RRR submission and privileged manual review. This service is not Remita verification. */
final class ReservationPaymentService {
    private \Closure $clock;
    public function __construct(private PDO $pdo,?callable $clock=null) {
        $this->clock=\Closure::fromCallable($clock??static fn():DateTimeImmutable=>new DateTimeImmutable('now',new DateTimeZone('UTC')));
    }
    private function now():DateTimeImmutable{$v=($this->clock)();if(!$v instanceof DateTimeImmutable)throw new RuntimeException('Clock must return a DateTimeImmutable.');return $v->setTimezone(new DateTimeZone('UTC'));}

    /** Remita references commonly appear with optional display hyphens; normalize case and separators. */
    public static function normalizeReference(string $rrr):string {
        $rrr=strtoupper(trim($rrr));
        if($rrr===''||!preg_match('/^[A-Z0-9\s-]+$/',$rrr))throw new RuntimeException('Enter an RRR using letters or numbers only.');
        $rrr=preg_replace('/[\s-]+/','',$rrr)??'';
        if(strlen($rrr)<4||strlen($rrr)>100)throw new RuntimeException('Enter an RRR between 4 and 100 characters.');
        return $rrr;
    }

    public static function developmentReviewEnabled():bool {
        return strtolower(envv('APP_ENV','production'))==='development'&&envv('ALLOW_DEVELOPMENT_PAYMENT_REVIEW','0')==='1';
    }

    private static function requireDevelopmentReview():void {
        if(!self::developmentReviewEnabled())throw new RuntimeException('Development payment review is disabled. Configure APP_ENV=development and ALLOW_DEVELOPMENT_PAYMENT_REVIEW=1 locally.');
    }

    public function submit(int $student,int $reservationId,string $rrr,string $amount):int {
        self::requireDevelopmentReview();$rrr=self::normalizeReference($rrr);
        (new ReservationService($this->pdo,48,fn()=>$this->now()))->expire();
        $now=$this->now()->format('Y-m-d H:i:s');$lookup=$this->pdo->prepare('SELECT bed_space_id FROM reservations WHERE id=? AND student_user_id=?');$lookup->execute([$reservationId,$student]);$bed=$lookup->fetchColumn();if(!$bed)throw new RuntimeException('Reservation not found.');
        \begin_write($this->pdo);
        try {
            $s=$this->pdo->prepare('SELECT r.*,sp.eligibility FROM reservations r JOIN student_profiles sp ON sp.user_id=r.student_user_id WHERE r.id=? AND r.student_user_id=?');$s->execute([$reservationId,$student]);$r=$s->fetch();
            if(!$r||$r['status']!=='pending_payment'||$r['expires_at']<=$now)throw new RuntimeException('Reservation has expired or is unavailable.');if($r['eligibility']!=='eligible')throw new RuntimeException('Student eligibility is no longer approved.');
            $s=$this->pdo->prepare("SELECT COUNT(*) FROM payments WHERE reservation_id=? AND status='pending_review'");$s->execute([$reservationId]);if((int)$s->fetchColumn())throw new RuntimeException('A payment submission is already awaiting review.');
            $s=$this->pdo->prepare("INSERT INTO payments(reservation_id,rrr,amount,mode) VALUES(?,?,?,'development_review')");$s->execute([$reservationId,$rrr,$amount]);$id=(int)$this->pdo->lastInsertId();\commit_write($this->pdo);return $id;
        } catch(Throwable $e) {\rollback_write($this->pdo);if($e instanceof \PDOException&&($e->getCode()==='23000'||str_contains(strtolower($e->getMessage()),'unique constraint failed')))throw new RuntimeException('This transaction reference has already been submitted.',0,$e);throw $e;}
    }

    public function review(int $reviewer,int $paymentId,string $decision,string $note=''):void {
        self::requireDevelopmentReview();
        if(!in_array($decision,['verified','rejected'],true))throw new RuntimeException('Invalid payment decision.');
        $role=$this->pdo->prepare("SELECT role FROM users WHERE id=?");$role->execute([$reviewer]);if($role->fetchColumn()!=='super_admin')throw new RuntimeException('Only a super administrator can review payments.');
        (new ReservationService($this->pdo,48,fn()=>$this->now()))->expire();
        $lookup=$this->pdo->prepare('SELECT r.bed_space_id FROM payments p JOIN reservations r ON r.id=p.reservation_id WHERE p.id=?');$lookup->execute([$paymentId]);$bed=$lookup->fetchColumn();if(!$bed)throw new RuntimeException('Payment not found.');
        $now=$this->now()->format('Y-m-d H:i:s');\begin_write($this->pdo);
        try {
            $s=$this->pdo->prepare('SELECT p.*,r.student_user_id,r.bed_space_id,r.status reservation_status,r.expires_at,sp.eligibility FROM payments p JOIN reservations r ON r.id=p.reservation_id JOIN student_profiles sp ON sp.user_id=r.student_user_id WHERE p.id=?');$s->execute([$paymentId]);$p=$s->fetch();
            if(!$p||$p['status']!=='pending_review')throw new RuntimeException('Payment is no longer pending.');
            if($decision==='verified') {
                if($p['mode']!=='development_review')throw new RuntimeException('Real gateway verification must use the configured Remita adapter.');
                if($p['eligibility']!=='eligible')throw new RuntimeException('Student eligibility is no longer approved.');
                if($p['reservation_status']!=='pending_payment'||$p['expires_at']<=$now)throw new RuntimeException('Reservation expired before payment review.');
                $q=$this->pdo->prepare("SELECT COUNT(*) FROM allocations WHERE bed_space_id=? AND status='confirmed'");$q->execute([$bed]);if((int)$q->fetchColumn())throw new RuntimeException('Bed already allocated.');
                $q=$this->pdo->prepare("SELECT COUNT(*) FROM allocations WHERE student_user_id=? AND status='confirmed'");$q->execute([$p['student_user_id']]);if((int)$q->fetchColumn())throw new RuntimeException('Student already has an allocation.');
                $this->pdo->prepare("UPDATE reservations SET status='confirmed',updated_at=? WHERE id=?")->execute([$now,$p['reservation_id']]);
                $this->pdo->prepare('INSERT INTO allocations(student_user_id,bed_space_id,reservation_id) VALUES(?,?,?)')->execute([$p['student_user_id'],$bed,$p['reservation_id']]);
                $this->pdo->prepare("INSERT INTO notifications(user_id,title,message) VALUES(?,'Allocation confirmed','Your hostel payment review was approved and allocation confirmed.')")->execute([$p['student_user_id']]);
            }
            $this->pdo->prepare('UPDATE payments SET status=?,reviewer_user_id=?,review_note=?,reviewed_at=? WHERE id=?')->execute([$decision,$reviewer,substr(trim($note),0,500),$now,$paymentId]);
            $this->pdo->prepare('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?)')->execute([$reviewer,'payment_'.$decision,'payment',$paymentId,json_encode(['mode'=>$p['mode'],'reservation_id'=>$p['reservation_id']])]);
            \commit_write($this->pdo);
        } catch(Throwable $e) {\rollback_write($this->pdo);throw $e;}
    }
}
