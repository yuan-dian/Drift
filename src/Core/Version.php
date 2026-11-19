<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 框架版本常量。**由 tools/gen-version.php 从根目录 VERSION / DEPS_VERSION 生成，勿手改**。
 *
 * 真相源是那两个纯文本文件（Chromium chrome/VERSION、Flutter engine.version 的形态）：
 * shell / CI 能直接 cat，而运行时代码需要编译期就确定的值 —— AOT 产物里没有仓库根
 * 目录可以读，所以这里存一份派生副本，由 bin/drift verify 保证它不漂。
 *
 * @api
 */
final class Version
{
    public const string VERSION = '0.1.0';
    public const string BASE = '0.1.0';
    public const int PREVIEW = 0;
    public const string TAG = 'v0.1.0';
    public const string DEPS_TAG = 'deps-v2';

    private function __construct()
    {
    }
}
