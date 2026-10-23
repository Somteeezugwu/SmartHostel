<?php
declare(strict_types=1);
namespace SmartHostel\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ReservationService {
    private \Closure $clock;

    /** $clock is injectable for repeatable expiry tests; it must return a UTC DateTimeImmutable. */
    public function __construct(private PDO $pdo, private int $hours=48, ?callable $clock=null) {
        $this->clock=\Closure::fromCallable($clock??static fn():DateTimeImmutable=>new DateTimeImmutable('now',new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable {
        $now=($this->clock)();
        if(!$now instanceof DateTimeImmutable) throw new RuntimeException('Clock must return a DateTimeImmutable.');
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function expire(): int {
        $now=$this->now()->format('Y-m-d H:i:s');$ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)\begin_write($this->pdo);
        try{$s=$this->pdo->prepare("UPDATE reservations SET status='expired',updated_at=? WHERE status='pending_payment' AND expires_at<=?");$s->execute([$now,$now]);$count=$s->rowCount();$p=$this->pdo->prepare("UPDATE payments SET status='cancelled',review_note='Reservation expired',reviewed_at=? WHERE status='pending_review' AND reservation_id IN (SELECT id FROM reservations WHERE status='expired')");$p->execute([$now]);if($ownsTransaction)\commit_write($this->pdo);return $count;}catch(Throwable $e){if($ownsTransaction)\rollback_write($this->pdo);throw $e;}
    }

    public function reserve(int $student,int $bed): int {
        if($this->hours<1||$this->hours>168) throw new RuntimeException('Reservation duration must be from 1 to 168 hours.');
        $now=$this->now();$created=$now->format('Y-m-d H:i:s');$expires=$now->modify('+'.$this->hours.' hours')->format('Y-m-d H:i:s');
        $this->expire();\begin_write($this->pdo);
        try {
            $s=$this->pdo->prepare('SELECT b.id,b.status,rm.status room_status,h.status hostel_status,h.gender_eligibility,sp.gender,sp.eligibility FROM bed_spaces b JOIN rooms rm ON rm.id=b.room_id JOIN hostels h ON h.id=rm.hostel_id JOIN student_profiles sp ON sp.user_id=? WHERE b.id=?');
            $s->execute([$student,$bed]);$r=$s->fetch();
            if(!$r||$r['status']!=='active'||$r['room_status']!=='active'||$r['hostel_status']!=='active') throw new RuntimeException('Bed space unavailable.');
            if($r['eligibility']!=='eligible') throw new RuntimeException('School-fee eligibility must be approved before booking.');
            if($r['gender_eligibility']!=='any'&&$r['gender_eligibility']!==$r['gender']) throw new RuntimeException('Hostel is not eligible for your profile.');
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM allocations WHERE bed_space_id=? AND status='confirmed'");$q->execute([$bed]);
            if((int)$q->fetchColumn()) throw new RuntimeException('Bed already allocated.');
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM allocations WHERE student_user_id=? AND status='confirmed'");$q->execute([$student]);
            if((int)$q->fetchColumn()) throw new RuntimeException('You already have a confirmed allocation.');
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM reservations WHERE bed_space_id=? AND status='pending_payment' AND expires_at>?");$q->execute([$bed,$created]);
            if((int)$q->fetchColumn()) throw new RuntimeException('Bed is temporarily held.');
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM reservations WHERE student_user_id=? AND status='pending_payment' AND expires_at>?");$q->execute([$student,$created]);
            if((int)$q->fetchColumn()) throw new RuntimeException('You already have an active hold.');
            $q=$this->pdo->prepare("INSERT INTO reservations(student_user_id,bed_space_id,status,created_at,expires_at) VALUES(?,?,'pending_payment',?,?)");
            $q->execute([$student,$bed,$created,$expires]);$id=(int)$this->pdo->lastInsertId();\commit_write($this->pdo);return $id;
        } catch(Throwable $e) { \rollback_write($this->pdo);throw $e; }
    }

    public function cancel(int $student,int $reservationId): void {
        $this->expire();$now=$this->now()->format('Y-m-d H:i:s');$lookup=$this->pdo->prepare('SELECT bed_space_id FROM reservations WHERE id=? AND student_user_id=?');$lookup->execute([$reservationId,$student]);$bed=$lookup->fetchColumn();if(!$bed)throw new RuntimeException('Reservation not found.');\begin_write($this->pdo);
        try {
            $s=$this->pdo->prepare('SELECT * FROM reservations WHERE id=? AND student_user_id=?');$s->execute([$reservationId,$student]);$r=$s->fetch();
            if(!$r) throw new RuntimeException('Reservation not found.');
            if($r['status']!=='pending_payment'||$r['expires_at']<=$now) throw new RuntimeException('Only an active unpaid reservation can be cancelled.');
            $this->pdo->prepare("UPDATE reservations SET status='cancelled',updated_at=? WHERE id=?")->execute([$now,$reservationId]);
            $this->pdo->prepare("UPDATE payments SET status='cancelled',review_note='Reservation cancelled by student',reviewed_at=? WHERE reservation_id=? AND status='pending_review'")->execute([$now,$reservationId]);
            $s=$this->pdo->prepare("INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,'reservation_cancelled','reservation',?,'{}')");$s->execute([$student,$reservationId]);
            $s=$this->pdo->prepare("INSERT INTO notifications(user_id,title,message) VALUES(?,'Reservation cancelled','Your reservation was cancelled and the bed space released.')");$s->execute([$student]);
            \commit_write($this->pdo);
        } catch(Throwable $e) { \rollback_write($this->pdo);throw $e; }
    }
}
