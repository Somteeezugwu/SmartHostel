<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Services/CompatibilityService.php';

use SmartHostel\Services\CompatibilityService;

function check(bool $condition,string $name):void { if(!$condition)throw new RuntimeException('Failed: '.$name); echo "PASS: $name\n"; }

$engine=new CompatibilityService();
$same=['sleep_time'=>1380,'study_time'=>600,'cleanliness'=>4,'noise_tolerance'=>3,'social_style'=>5,'guest_frequency'=>2];
check($engine->compare($same,$same)['score']===100,'identical preferences score 100');
check($engine->compare(['sleep_time'=>1380],['sleep_time'=>60])['score']===83,'schedule similarity wraps across midnight');

$conflictA=['sleep_time'=>0,'study_time'=>0,'cleanliness'=>1,'noise_tolerance'=>1,'social_style'=>1,'guest_frequency'=>1];
$conflictB=['sleep_time'=>720,'study_time'=>720,'cleanliness'=>5,'noise_tolerance'=>5,'social_style'=>5,'guest_frequency'=>5];
check($engine->compare($conflictA,$conflictB)['score']===0,'maximum conflicts score 0');

$partial=$engine->compare(['sleep_time'=>600,'cleanliness'=>5],['sleep_time'=>660,'cleanliness'=>null]);
check($partial['score']===92&&$partial['used_weight']===20.0,'incomplete answers exclude unmatched criteria and their weights');
$none=$engine->compare(['sleep_time'=>null],['sleep_time'=>600]);
check($none['score']===null&&$none['used_weight']===0,'no shared answers returns no score');
check($engine->compare(['cleanliness'=>5.5],['cleanliness'=>5])['score']===null,'invalid non-integer answers are excluded');

$a=['eligibility'=>'eligible','gender'=>'female'];$b=['eligibility'=>'eligible','gender'=>'female'];
check(CompatibilityService::eligiblePair($a,$b),'eligible same-gender students may be compared');
check(!CompatibilityService::eligiblePair($a,['eligibility'=>'ineligible','gender'=>'female']),'ineligible student is excluded');
check(!CompatibilityService::eligiblePair($a,['eligibility'=>'eligible','gender'=>'male']),'gender eligibility restriction is applied before scoring');

$reverseA=$engine->compare($same,$conflictA);$reverseB=$engine->compare($conflictA,$same);
check($reverseA['score']===$reverseB['score']&&$reverseA['factors']===$reverseB['factors'],'score and factors are symmetric');

$custom=new CompatibilityService(['cleanliness'=>20,'noise_tolerance'=>80]);
$weighted=$custom->compare(['cleanliness'=>1,'noise_tolerance'=>4],['cleanliness'=>5,'noise_tolerance'=>4]);
check($weighted['score']===80,'database-configured weights are normalized over applicable criteria');

echo "All compatibility checks passed.\n";
