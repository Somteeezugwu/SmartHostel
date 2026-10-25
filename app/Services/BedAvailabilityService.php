<?php
declare(strict_types=1);
namespace SmartHostel\Services;

use PDO;

/** Read-only, student-scoped inventory queries used by hierarchical bed browsing. */
final class BedAvailabilityService {
    public function __construct(private PDO $pdo) {}

    public function hostels(int $studentId): array {
        $sql="SELECT h.id,h.name,h.description,h.location,h.gender_eligibility,
                     COUNT(DISTINCT rm.id) available_rooms,COUNT(DISTINCT b.id) available_beds
              FROM hostels h
              JOIN rooms rm ON rm.hostel_id=h.id AND rm.status='active'
              JOIN bed_spaces b ON b.room_id=rm.id AND b.status='active'
              WHERE h.status='active'
                AND (h.gender_eligibility='any' OR h.gender_eligibility=(SELECT gender FROM student_profiles WHERE user_id=?))
                AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.bed_space_id=b.id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP)
                AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.bed_space_id=b.id AND a.status='confirmed')
              GROUP BY h.id HAVING COUNT(DISTINCT b.id)>0 ORDER BY h.name";
        $s=$this->pdo->prepare($sql);$s->execute([$studentId]);return $s->fetchAll();
    }

    public function rooms(int $studentId,int $hostelId): array {
        $sql="SELECT rm.id,rm.hostel_id,rm.room_number,rm.capacity,h.name hostel,
                     COUNT(DISTINCT b.id) available_beds
              FROM rooms rm JOIN hostels h ON h.id=rm.hostel_id
              JOIN bed_spaces b ON b.room_id=rm.id AND b.status='active'
              WHERE rm.hostel_id=? AND rm.status='active' AND h.status='active'
                AND (h.gender_eligibility='any' OR h.gender_eligibility=(SELECT gender FROM student_profiles WHERE user_id=?))
                AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.bed_space_id=b.id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP)
                AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.bed_space_id=b.id AND a.status='confirmed')
              GROUP BY rm.id HAVING COUNT(DISTINCT b.id)>0 ORDER BY rm.room_number";
        $s=$this->pdo->prepare($sql);$s->execute([$hostelId,$studentId]);return $s->fetchAll();
    }

    public function beds(int $studentId,int $hostelId,int $roomId): array {
        $sql="SELECT b.id,b.bed_label,rm.id room_id,rm.room_number,rm.capacity,
                     h.id hostel_id,h.name hostel,h.location,h.gender_eligibility
              FROM bed_spaces b JOIN rooms rm ON rm.id=b.room_id JOIN hostels h ON h.id=rm.hostel_id
              WHERE h.id=? AND rm.id=? AND b.status='active' AND rm.status='active' AND h.status='active'
                AND (h.gender_eligibility='any' OR h.gender_eligibility=(SELECT gender FROM student_profiles WHERE user_id=?))
                AND NOT EXISTS(SELECT 1 FROM reservations r WHERE r.bed_space_id=b.id AND r.status='pending_payment' AND r.expires_at>CURRENT_TIMESTAMP)
                AND NOT EXISTS(SELECT 1 FROM allocations a WHERE a.bed_space_id=b.id AND a.status='confirmed')
              ORDER BY b.bed_label";
        $s=$this->pdo->prepare($sql);$s->execute([$hostelId,$roomId,$studentId]);return $s->fetchAll();
    }
}
