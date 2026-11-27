<?php
declare(strict_types=1);

namespace Drift\DevTools\Cli;

use Drift\DevTools\Host\Source;
use Drift\DevTools\Session\SessionQueries;
use Throwable;

/**
 * 八个"取证据"出口共用的一段生命周期。
 *
 * 原来每个出口都自己写：解析 → 取 `from/out/at/remote/label/attach/session` →
 * 无页面提示 → `autoAttach` → `script()` → `resolve()` → `export()` → 查询 →
 * 渲染 → `finally close()`。这段有十步，漏任何一步都不会报错，只会让某个出口
 * 的行为悄悄和别的出口分叉（最贵的一次是 `find` 少了 `export`，`--out` 静默
 * 不落盘）。抽成一处之后，"同一份证据在八个出口里读到同样的事实"有了结构保证。
 *
 * 不收的出口是**有意**的：`web` 常驻、`loop` 多轮、三个干预出口只连活会话且
 * 错误码不同、`targets` / `capabilities` 根本不跑会话。把它们塞进来会让壳长出
 * 一堆分支，反而比原来的重复更难读。
 *
 * @internal 出口用；不是给用户调用的 API
 */
final class SessionScope
{
    private function __construct(
        public readonly Parsed $args,
        public readonly Source $source,
    ) {
    }

    /**
     * 开一个会话并把通用步骤走完。失败一律在 stderr 说清楚并退出 2。
     *
     * 少数出口（`brief`）要在开会话**之前**校验自己的必填组合，所以允许它把
     * 已经解析好的参数传进来 —— 解析仍只发生一次，只是提前了。
     *
     * @param list<string> $argv 含脚本名
     */
    public static function open(string $verb, array $argv, ?Parsed $parsed = null): self
    {
        $args = $parsed ?? Commands::parseOrExit($verb, $argv);
        $from = $args->supports('from') ? $args->str('from') : null;
        $out = $args->supports('out') ? $args->str('out') : null;
        $label = $args->label();
        $attach = $args->bool('attach');
        $session = $args->supports('session') ? $args->str('session') : null;

        // ★ 顺序是判据：先让 `autoAttach()` 看有没有唯一活会话，再判"什么都没给"。
        //   反过来（A74 一度如此）会让"当前目录恰有一个活会话就自动接"永远到不了 ——
        //   无参数运行在发现会话之前就被挡下，而 `debug web` 不走这条壳、仍能自动接，
        //   于是同一台机器上两个出口对"有没有会话"给出相反答案。
        try {
            $attach = Source::autoAttach($attach, $from, $label, $session);
        } catch (Throwable $e) {
            \fwrite(\STDERR, $e->getMessage() . "\n");
            exit(2);
        }

        if ($label === null && $from === null && !$attach) {
            // 提示从注册表派生：出口脚本文档块里的「用法：」是唯一声明。
            // 手写第二份用法迟早会漏掉新增参数，而且漏了没人会发现。
            if ($session !== null) {
                // `--session` 只负责"多个里挑一个"，没有 `--attach` 就不知道要连谁。
                \fwrite(\STDERR, "debug-{$verb} 的 --session 只用来在多个活会话里挑一个，要配合 --attach\n");
            } else {
                \fwrite(\STDERR, "debug-{$verb} 要一个页面：给位置参数、--page=…，或 --from / --attach\n");
            }
            $examples = Commands::examples($verb);
            if ($examples !== null) {
                \fwrite(\STDERR, "用法：\n{$examples}\n");
            }
            exit(2);
        }

        $drive = $from === null && !$attach ? $args->script() : null;
        $source = null;
        try {
            $source = Source::resolve(
                from: $from,
                attach: $attach,
                session: $session,
                remote: $args->bool('remote'),
                drive: $drive,
                at: $args->at(),
                out: $out,
                recordOptions: $args->recordOptions(),
            );
            // 帧已经跑完，记录齐了。没有 --out 的出口这里是个 no-op。
            $source->export();

            return new self($args, $source);
        } catch (Throwable $e) {
            $source?->close();
            \fwrite(\STDERR, $e->getMessage() . "\n");
            exit(2);
        }
    }

    public function queries(): SessionQueries
    {
        return $this->source->queries();
    }

    public function label(): string
    {
        return $this->source->label();
    }

    public function json(): bool
    {
        return $this->args->bool('json');
    }

    /** @param array<string, mixed> $params */
    public function query(string $name, array $params = []): array
    {
        return $this->queries()->query($name, $params);
    }

    /**
     * 结构化与文本两条路共用同一个答案。
     *
     * @param array<string, mixed> $answer
     * @param callable(array<string, mixed>): iterable<string> $text
     * @param array<string, mixed>|null $jsonPayload 不用 `['label' => …] + $answer` 的出口
     */
    public function emit(array $answer, callable $text, ?array $jsonPayload = null): void
    {
        if ($this->json()) {
            $payload = $jsonPayload ?? (['label' => $this->label()] + $answer);
            \fwrite(\STDOUT, (string) \json_encode(
                $payload,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
            ) . "\n");

            return;
        }
        foreach ($text($answer) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }

    /**
     * 跑业务段并统一收口：异常 → stderr + 2；连接一律关；回调给的退出码照传。
     *
     * @param callable(self): int $body
     */
    public function run(callable $body): never
    {
        try {
            $code = $body($this);
        } catch (Throwable $e) {
            \fwrite(\STDERR, $e->getMessage() . "\n");
            $code = 2;
        } finally {
            $this->source->close();
        }
        exit($code);
    }
}
