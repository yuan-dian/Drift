<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Platform\Dialog;

/**
 * @internal 测试夹具：内存对话框（A5-①，`Dialog` 端口的第一个替身）。
 *
 * ★ **它同时是"端口形状"的判据**：四个方法的返回值语义（`?string` / `void` /
 *   `bool`）在这里被显式摆出来，任何"把取消与成功混成一态"的用法都会先在这里
 *   露出马脚。
 * ★ `nextPath` 用**空串代表取消**（而不是 `?string` 属性）：与
 *   `Win32Dialog::openFile()` 把空串翻成 `null` 的口径对齐，替身与真件同一套
 *   输入语言。
 * ★ 计数用**独立字段**而不是"最后一个值"：`assert(openCalls === 1)` 能抓住
 *   "点一次调两次"（按钮回调重复注册这类真 bug），而读最后一个值抓不到。
 */
final class FakeDialog implements Dialog
{
    /** 下次 `openFile` / `saveFile` 的返回值；**空串 = 用户取消** */
    public string $nextPath = '';

    public int $openCalls = 0;

    public int $saveCalls = 0;

    public int $alertCalls = 0;

    public int $confirmCalls = 0;

    /** 下次 `confirm()` 的答案（默认 false = 用户点了"否" / 关框） */
    public bool $answer = false;

    /** 最近一次调用给的标题 / 正文 / 筛选表（判"递下去的参数对不对"） */
    public string $lastTitle = '';

    public string $lastBody = '';

    /** @var array<array-key, mixed> */
    public array $lastFilters = [];

    public function openFile(string $title, array $filters = []): ?string
    {
        $this->openCalls++;
        $this->lastTitle = $title;
        $this->lastFilters = $filters;

        return $this->nextPath === '' ? null : $this->nextPath;
    }

    /**
     * 下次 `openFiles` 的返回值（**空数组 = 用户取消**）。
     *
     * ★ 与 `$nextPath` 分开：单选与多选是两个方法、两套输入语言。让它们共用一个
     *   字段会掩盖"某个调用方用错了方法"这类错（单选拿到数组 / 多选拿到字符串）。
     *
     * @var list<string>
     */
    public array $nextPaths = [];

    public int $openFilesCalls = 0;

    public function openFiles(string $title, array $filters = []): array
    {
        $this->openFilesCalls++;
        $this->lastTitle = $title;
        $this->lastFilters = $filters;

        return $this->nextPaths;
    }

    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string
    {
        $this->saveCalls++;
        $this->lastTitle = $title;
        $this->lastBody = $defaultName;
        $this->lastFilters = $filters;

        return $this->nextPath === '' ? null : $this->nextPath;
    }

    public function alert(string $title, string $body): void
    {
        $this->alertCalls++;
        $this->lastTitle = $title;
        $this->lastBody = $body;
    }

    public function confirm(string $title, string $body): bool
    {
        $this->confirmCalls++;
        $this->lastTitle = $title;
        $this->lastBody = $body;

        return $this->answer;
    }
}
