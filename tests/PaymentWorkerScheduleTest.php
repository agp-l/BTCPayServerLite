<?php

declare(strict_types=1);
require __DIR__.'/support/CoreTestSupport.php';
use BtcPayLite\PaymentWorkerSchedule;
$service=PaymentWorkerSchedule::render('service','/srv/payment','/usr/bin/php8.3','worker');
coreCheck(str_contains($service,'User=worker') && str_contains($service,'ExecStart=/usr/bin/php8.3 /srv/payment/payment_worker.php'),'Service paths wrong');
$timer=PaymentWorkerSchedule::render('timer','','','');
coreCheck(str_contains($timer,'OnUnitActiveSec=10min') && str_contains($timer,'OnBootSec=10min')
    && !str_contains($timer,'OnUnitInactiveSec') && str_contains($timer,'Unit=btcpay-lite-payment-worker.service'),'Timer interval wrong');
coreCheck(str_contains(PaymentWorkerSchedule::render('timer','','','',60),'OnUnitActiveSec=1min'), 'Queue drain tick ignored');
foreach ([0, 15, 59, 61, 601] as $tick) {
    try { PaymentWorkerSchedule::render('timer','','','',$tick); throw new LogicException('Invalid tick accepted'); }
    catch (InvalidArgumentException) {}
}
foreach ([['/srv/%h','/usr/bin/php','worker'],['/srv/payment',"/usr/bin/php\nExecStart=bad",'worker'],['/srv/payment','/usr/bin/php','root']] as $args) {
    try { PaymentWorkerSchedule::render('service',...$args); throw new LogicException('Unsafe systemd input accepted'); }
    catch (InvalidArgumentException $e) {}
}
echo "[PASS] Reviewable systemd units and directive/specifier input rejection\n";
