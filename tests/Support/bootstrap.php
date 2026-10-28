<?php
declare(strict_types=1);

/**
 * PHPUnit 的 bootstrap。
 *
 * ★ 锚死本仓源码：用 `tools/autoload.php`（自建 PSR-4，读 composer.json 的
 *   autoload 表），不用 `vendor/autoload.php`。理由同 `tools/autoload.php`
 *   头注 —— composer.json 是物理边界表的唯一来源；vendor 只提供 PHPUnit 自己的类。
 */

require __DIR__ . '/../../tools/autoload.php';
require __DIR__ . '/functions.php';
