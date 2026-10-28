<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Platform\Clipboard;

/**
 * @internal 测试夹具：内存剪贴板（`hasText()` 与内容分开灌，验门控 ④）。
 *
 * ★ 从 `EditApplication.php` 拆出来独立成文件：同文件第二个类 PSR-4 加载不到，
 *   只能靠"先 new 前一个类"的副作用顺带定义 —— 换个调用顺序就 `class not found`。
 */
final class FakeClipboard implements Clipboard
{
    public string $text = '';

    public bool $available = true;

    public function readText(): string
    {
        return $this->text;
    }

    public function writeText(string $text): void
    {
        $this->text = $text;
    }

    public function hasText(): bool
    {
        return $this->available && $this->text !== '';
    }
}
