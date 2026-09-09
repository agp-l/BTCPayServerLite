<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\PaymentWorkerSchedule;
$service=PaymentWorkerSchedule::render('service','/srv/payment','/usr/bin/php8.3','worker');
coreCheck(str_contains($service,'User=worker') && str_contains($service,'ExecStart=/usr/bin/php8.3 /srv/payment/payment_worker.php'),'Service paths wrong');
$timer=PaymentWorkerSchedule::render('timer','','','');
coreCheck(str_contains($timer,'OnUnitInactiveSec=15s') && str_contains($timer,'Unit=btcpay-lite-payment-worker.service'),'Timer interval wrong');
foreach ([['/srv/%h','/usr/bin/php','worker'],['/srv/payment',"/usr/bin/php\nExecStart=bad",'worker'],['/srv/payment','/usr/bin/php','root']] as $args) {
    try { PaymentWorkerSchedule::render('service',...$args); throw new LogicException('Unsafe systemd input accepted'); }
    catch (InvalidArgumentException $e) {}
}
echo "[PASS] Reviewable systemd units and directive/specifier input rejection\n";
