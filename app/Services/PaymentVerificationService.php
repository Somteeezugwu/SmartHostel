<?php
declare(strict_types=1);
namespace SmartHostel\Services;
interface PaymentVerificationService { public function verify(array $payment):array; }
