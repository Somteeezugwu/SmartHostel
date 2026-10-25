<?php
declare(strict_types=1);
namespace SmartHostel\Services;

use PDO;

/** Eligible, pre-allocation compatibility discovery using completed questionnaire data. */
final class RoommateMatchService {
    private const LABELS=[
        'sleep_time'=>'Sleep schedule','study_time'=>'Study schedule','cleanliness'=>'Cleanliness',
        'noise_tolerance'=>'Noise tolerance','social_style'=>'Social style','guest_frequency'=>'Guest frequency'
    ];
    public function __construct(private PDO $pdo,private CompatibilityService $engine) {}

    public function find(int $studentId): array {
        $s=$this->pdo->prepare('SELECT p.gender,p.eligibility,a.sleep_time,a.study_time,a.cleanliness,a.noise_tolerance,a.social_style,a.guest_frequency FROM student_profiles p LEFT JOIN student_answers a ON a.user_id=p.user_id WHERE p.user_id=?');
        $s->execute([$studentId]);$me=$s->fetch();
        if(!$me)return ['matches'=>[],'reason'=>'profile_missing','message'=>'Complete your student profile before searching.','questionnaire_complete'=>false,'missing_preferences'=>array_values(self::LABELS)];
        if($me['eligibility']!=='eligible')return ['matches'=>[],'reason'=>'eligibility_pending','message'=>'School-fee eligibility must be approved before matching.','questionnaire_complete'=>false,'missing_preferences'=>array_values(self::LABELS)];
        $mine=[];$missing=[];
        foreach(self::LABELS as $key=>$label){$mine[$key]=$me[$key]??null;if(!$this->valid($key,$mine[$key]))$missing[]=$label;}
        if($missing)return ['matches'=>[],'reason'=>'questionnaire_incomplete','message'=>'Complete all six lifestyle preferences to see roommate recommendations.','questionnaire_complete'=>false,'missing_preferences'=>$missing];

        $q=$this->pdo->prepare("SELECT p.user_id,p.first_name,p.academic_level,p.gender,p.eligibility,a.sleep_time,a.study_time,a.cleanliness,a.noise_tolerance,a.social_style,a.guest_frequency
            FROM student_profiles p JOIN users u ON u.id=p.user_id
            LEFT JOIN student_answers a ON a.user_id=p.user_id
            WHERE p.user_id<>? AND u.role='student' AND p.eligibility='eligible' AND p.gender=?");
        $q->execute([$studentId,$me['gender']]);$peers=$q->fetchAll();
        if(!$peers)return ['matches'=>[],'reason'=>'no_eligible_students','message'=>'No other eligible students are available for roommate matching yet.','questionnaire_complete'=>true,'missing_preferences'=>[]];
        $complete=0;$matches=[];
        foreach($peers as $peer){
            $answers=[];$candidateComplete=true;
            foreach(self::LABELS as $key=>$label){$answers[$key]=$peer[$key]??null;if(!$this->valid($key,$answers[$key]))$candidateComplete=false;}
            if(!$candidateComplete)continue;
            $complete++;$score=$this->engine->compare($mine,$answers);
            if($score['score']===null)continue;
            $factors=[];
            foreach($score['factors'] as $key=>$factor)$factors[]=['name'=>self::LABELS[$key],'similarity'=>$factor['similarity'],'weight'=>$factor['weight'],'weighted_points'=>$factor['weighted_points']];
            usort($factors,static fn($a,$b)=>$b['weighted_points']<=>$a['weighted_points']);
            $matches[]=['first_name'=>$peer['first_name'],'academic_level'=>$peer['academic_level'],'score'=>$score['score'],'factors'=>$factors];
        }
        usort($matches,static fn($a,$b)=>$b['score']<=>$a['score']);
        $matches=array_slice($matches,0,12);
        if($matches)return ['matches'=>$matches,'reason'=>null,'message'=>null,'questionnaire_complete'=>true,'missing_preferences'=>[],'eligible_candidates'=>count($peers),'complete_candidates'=>$complete];
        if(!$complete)return ['matches'=>[],'reason'=>'candidates_incomplete','message'=>'Other eligible students exist, but none have completed all six lifestyle preferences yet.','questionnaire_complete'=>true,'missing_preferences'=>[],'eligible_candidates'=>count($peers),'complete_candidates'=>0];
        return ['matches'=>[],'reason'=>'no_compatible_candidates','message'=>'No compatible candidates with shared enabled preferences are available yet.','questionnaire_complete'=>true,'missing_preferences'=>[],'eligible_candidates'=>count($peers),'complete_candidates'=>$complete];
    }

    private function valid(string $key,mixed $value): bool {
        if($value===null||$value==='')return false;
        $n=filter_var($value,FILTER_VALIDATE_INT);if($n===false)return false;
        return in_array($key,['sleep_time','study_time'],true)?$n>=0&&$n<=1439:$n>=1&&$n<=5;
    }
}
