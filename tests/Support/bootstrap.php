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

// 桥的解释器替身（与 `tools/smoke.php` 共用同一份 —— 见 tools/fakes/*.php 头注）。
// ★ 必须在测试类加载之前 require：`SkiaCanvas` / `Win32WindowHost` 直调全局
//   `drift_*` 函数，缺了就是 "Call to undefined function"（而不是可断言的假数据）。
require_once __DIR__ . '/../../tools/fakes/skia.php';
require_once __DIR__ . '/../../tools/fakes/win32.php';
