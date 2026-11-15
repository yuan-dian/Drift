<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Drift\App\Restoration\RestorationStore;

$store = new RestorationStore($argv[1]);
$bucket = $store->bucket();
$bucket->write('form.name', '张三');
$bucket->write('nav.page', 'signup');
$bucket->write('scroll.offset', 300);
$bucket->write('selection', [2, 5]);
$store->persist();

echo "ok\n";
