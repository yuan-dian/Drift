<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Drift\App\Restoration\RestorationStore;

$store = new RestorationStore($argv[1]);
$bucket = $store->bucket();

echo json_encode([
    'form' => $bucket->read('form.name'),
    'nav' => $bucket->read('nav.page'),
    'scroll' => $bucket->read('scroll.offset'),
    'selection' => $bucket->read('selection'),
], JSON_UNESCAPED_UNICODE), "\n";
