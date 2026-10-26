<?php
declare(strict_types=1);
namespace SmartHostel\Services;
final class CompatibilityService {
    public const WEIGHTS=['sleep_time'=>20,'study_time'=>20,'cleanliness'=>20,'noise_tolerance'=>15,'social_style'=>15,'guest_frequency'=>10];
    public const LABELS=['sleep_time'=>'Sleep schedule','study_time'=>'Study schedule','cleanliness'=>'Cleanliness','noise_tolerance'=>'Noise tolerance','social_style'=>'Social style','guest_frequency'=>'Guest frequency'];
    private array $weights;
    public function __construct(?array $weights=null) {
        $this->weights=$weights??self::WEIGHTS;
        foreach($this->weights as $key=>$weight) {
            if(!array_key_exists($key,self::WEIGHTS)||!is_numeric($weight)||!is_finite((float)$weight)||(float)$weight<0) throw new \InvalidArgumentException('Matching weights must use known criteria and non-negative finite values.');
            $this->weights[$key]=(float)$weight;
        }
    }
    public static function eligiblePair(array $a,array $b): bool {
        return ($a['eligibility']??null)==='eligible'
            && ($b['eligibility']??null)==='eligible'
            && isset($a['gender'],$b['gender'])
            && $a['gender']===$b['gender'];
    }
    public static function criteriaLabels(): array { return self::LABELS; }
    public function compare(array $a,array $b): array {
        $sum=0.0; $weight=0.0; $factors=[];
        foreach($this->weights as $key=>$w) {
            if($w===0.0||!isset($a[$key],$b[$key])||$a[$key]===''||$b[$key]==='') continue;
            $max=in_array($key,['sleep_time','study_time'],true)?1439:5;
            $min=in_array($key,['sleep_time','study_time'],true)?0:1;
            $x=filter_var($a[$key],FILTER_VALIDATE_INT);$y=filter_var($b[$key],FILTER_VALIDATE_INT);
            if($x===false||$y===false||$x<$min||$x>$max||$y<$min||$y>$max) continue;
            if(in_array($key,['sleep_time','study_time'],true)) { $d=min(abs($x-$y),1440-abs($x-$y));$sim=max(0.0,1-$d/720); }
            else { $sim=max(0.0,1-abs($x-$y)/4); }
            $sum+=$sim*$w; $weight+=$w; $factors[$key]=['similarity'=>round($sim,3),'weight'=>$w,'weighted_points'=>round($sim*$w,3)];
        }
        return $weight===0.0?['score'=>null,'factors'=>[],'used_weight'=>0,'message'=>'No shared valid answers; no compatibility score is calculated.']:['score'=>(int)round(100*$sum/$weight),'factors'=>$factors,'used_weight'=>$weight,'message'=>'Shared valid answers only are included in both the weighted sum and denominator; missing or invalid answers are excluded.'];
    }
}
