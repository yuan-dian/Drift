<?php
declare(strict_types=1);

namespace Drift\DevTools\Cli;

/**
 * 用户输入不合法。出口把它翻成 stderr + 退出码 2。
 *
 * ★ 单独一个文件是 PSR-4 的要求：类名与文件名必须对得上，否则任何"先 throw
 *   再走到 parse"的路径都会 class not found。原先它挤在 `Opt.php` 里，靠所有
 *   入口都恰好先 `parse` 过才没出事。
 */
final class CliError extends \RuntimeException
{
}
