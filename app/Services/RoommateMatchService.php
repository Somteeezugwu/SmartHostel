<?php
declare(strict_types=1);
namespace SmartHostel\Services;

use PDO;

/** Eligible, pre-allocation compatibility discovery using completed questionnaire data. */
final class RoommateMatchService {
    public function __construct(private PDO $pdo,private CompatibilityService $engine) {}

    public function find(int $studentId): array {
        $labels=CompatibilityService::criteriaLabels();
        $allocation=$this->pdo->prepare("SELECT h.name AS hostel,rm.room_number,b.bed_label FROM allocations a JOIN bed_spaces b ON b.id=a.bed_space_id JOIN rooms rm ON rm.id=b.room_id JOIN hostels h ON h.id=rm.hostel_id WHERE a.student_user_id=? AND a.status='confirmed' LIMIT 1");
        $allocation->execute([$studentId]);
        $studentAllocation=$allocation->fetch()?:null;
        $studentAllocated=$studentAllocation!==null;
        $s=$this->pdo->prepare('SELECT p.user_id,p.first_name,p.last_name,p.faculty,p.department,p.academic_level,p.gender,p.eligibility,a.sleep_time,a.study_time,a.cleanliness,a.noise_tolerance,a.social_style,a.guest_frequency FROM student_profiles p LEFT JOIN student_answers a ON a.user_id=p.user_id WHERE p.user_id=?');
        $s->execute([$studentId]);$me=$s->fetch();
        if(!$me)return ['matches'=>[],'reason'=>'profile_missing','message'=>'Complete your student profile before searching.','questionnaire_complete'=>false,'missing_preferences'=>array_values($labels),'student_allocated'=>$studentAllocated,'student_allocation'=>$studentAllocation];
        if($me['eligibility']!=='eligible')return ['matches'=>[],'reason'=>'eligibility_pending','message'=>'School-fee eligibility must be approved before matching.','questionnaire_complete'=>false,'missing_preferences'=>array_values($labels),'student_allocated'=>$studentAllocated,'student_allocation'=>$studentAllocation];
        $mine=[];$missing=[];
        foreach($labels as $key=>$label){$mine[$key]=$me[$key]??null;if(!$this->valid($key,$mine[$key]))$missing[]=$label;}
        if($missing)return ['matches'=>[],'reason'=>'questionnaire_incomplete','message'=>'Complete all six lifestyle preferences to see roommate recommendations.','questionnaire_complete'=>false,'missing_preferences'=>$missing,'student_allocated'=>$studentAllocated,'student_allocation'=>$studentAllocation];

        $q=$this->pdo->prepare("SELECT p.user_id,p.first_name,p.last_name,p.faculty,p.department,p.academic_level,p.gender,p.eligibility,
            a.sleep_time,a.study_time,a.cleanliness,a.noise_tolerance,a.social_style,a.guest_frequency,
            ac.bed_space_id as current_bed_id,
            b.bed_label,rm.room_number,rm.id as room_id,h.name as hostel_name,h.gender_eligibility as hostel_gender,
            (SELECT COUNT(*) FROM bed_spaces openBed WHERE openBed.room_id=rm.id AND openBed.status='active' AND rm.status='active' AND h.status='active' AND (h.gender_eligibility='any' OR h.gender_eligibility=p.gender) AND NOT EXISTS(SELECT 1 FROM allocations openAllocation WHERE openAllocation.bed_space_id=openBed.id AND openAllocation.status='confirmed') AND NOT EXISTS(SELECT 1 FROM reservations openHold WHERE openHold.bed_space_id=openBed.id AND openHold.status='pending_payment' AND openHold.expires_at>CURRENT_TIMESTAMP) AND NOT EXISTS(SELECT 1 FROM room_switch_requests pendingSwitch WHERE pendingSwitch.to_bed_space_id=openBed.id AND pendingSwitch.status='pending')) AS available_room_beds
            FROM student_profiles p
            JOIN users u ON u.id=p.user_id
            LEFT JOIN student_answers a ON a.user_id=p.user_id
            LEFT JOIN allocations ac ON ac.student_user_id=p.user_id AND ac.status='confirmed'
            LEFT JOIN bed_spaces b ON b.id=ac.bed_space_id
            LEFT JOIN rooms rm ON rm.id=b.room_id
            LEFT JOIN hostels h ON h.id=rm.hostel_id
            WHERE p.user_id<>? AND u.role='student' AND p.eligibility='eligible' AND p.gender=?");
        $q->execute([$studentId,$me['gender']]);$peers=$q->fetchAll();
        if(!$peers)return ['matches'=>[],'reason'=>'no_eligible_students','message'=>'No other eligible students are available for roommate matching yet.','questionnaire_complete'=>true,'missing_preferences'=>[]];

        $complete=0;$matches=[];
        foreach($peers as $peer){
            $answers=[];$candidateComplete=true;
            foreach($labels as $key=>$label){$answers[$key]=$peer[$key]??null;if(!$this->valid($key,$answers[$key]))$candidateComplete=false;}
            if(!$candidateComplete)continue;
            $complete++;
            $score=$this->engine->compare($mine,$answers);
            if($score['score']===null)continue;
            $factorList=[];
            foreach($score['factors'] as $key=>$factor)$factorList[]=['name'=>$labels[$key],'similarity'=>$factor['similarity'],'weight'=>$factor['weight'],'weighted_points'=>$factor['weighted_points']];
            usort($factorList,static fn($a,$b)=>$b['weighted_points']<=>$a['weighted_points']);
            $targetBedAvailable=(int)($peer['available_room_beds']??0)>0;
            $switchPossible=$targetBedAvailable && $peer['current_bed_id']!==null && $peer['room_id']!==null;
            $match=['user_id'=>(int)$peer['user_id'],'first_name'=>$peer['first_name'],'last_name'=>$peer['last_name'],'faculty'=>$peer['faculty'],'department'=>$peer['department'],'academic_level'=>$peer['academic_level'],'hostel_name'=>$peer['hostel_name'],'room_number'=>$peer['room_number'],'bed_label'=>$peer['bed_label'],'score'=>$score['score'],'factors'=>$factorList,'room_available'=>$targetBedAvailable,'switch_possible'=>$switchPossible,'current_bed_id'=>$peer['current_bed_id'] ?? null,'room_id'=>$peer['room_id'] ?? null,'allocated'=>$peer['current_bed_id'] !== null,'compatibility_summary'=>$this->summarizeFactors($factorList),'gender'=>$peer['gender']];
            $matches[]=$match;
        }
        usort($matches,static fn($a,$b)=>($b['score']<=>$a['score']) ?: ($b['switch_possible'] <=> $a['switch_possible']) ?: strcmp(($a['hostel_name']??''),($b['hostel_name']??'')));
        if($matches)return ['matches'=>$matches,'reason'=>null,'message'=>null,'questionnaire_complete'=>true,'missing_preferences'=>[],'eligible_candidates'=>count($peers),'complete_candidates'=>$complete,'student_allocated'=>$studentAllocated,'student_allocation'=>$studentAllocation];
        if(!$complete)return ['matches'=>[],'reason'=>'candidates_incomplete','message'=>'Other eligible students exist, but none have completed all six lifestyle preferences yet.','questionnaire_complete'=>true,'missing_preferences'=>[],'eligible_candidates'=>count($peers),'complete_candidates'=>0,'student_allocated'=>$studentAllocated,'student_allocation'=>$studentAllocation];
        return ['matches'=>[],'reason'=>'no_compatible_candidates','message'=>'No compatible candidates with shared enabled preferences are available yet.','questionnaire_complete'=>true,'missing_preferences'=>[],'eligible_candidates'=>count($peers),'complete_candidates'=>$complete,'student_allocated'=>$studentAllocated,'student_allocation'=>$studentAllocation];
    }

    private function valid(string $key,mixed $value): bool {
        if($value===null||$value==='')return false;
        $n=filter_var($value,FILTER_VALIDATE_INT);if($n===false)return false;
        return in_array($key,['sleep_time','study_time'],true)?$n>=0&&$n<=1439:$n>=1&&$n<=5;
    }

    private function summarizeFactors(array $factors): string {
        if(!$factors)return 'No strong shared factors are registered yet.';
        $parts=[];
        foreach(array_slice($factors,0,3) as $factor){
            $parts[]=$factor['name'].' '.((int)round($factor['similarity']*100)).'%';
        }
        return implode(' • ', $parts);
    }
}
