<?php
declare(strict_types=1);

namespace Drift\Dev\Abi;

/**
 * 跨层 ABI 契约：stub 是唯一事实源，C++ 实现与解释器替身都是被校验方。
 *
 * ★ 为什么不引入 IDL / 生成代码：stub 本身就是 tpc 的输入，且带着 500+ 行
 *   契约文档（DPI、ULW、WM_SIZE 非队列消息…）。再立一份清单只会多一个同步点。
 * ★ 为什么不比对参数名 / 默认值：它们不进 ABI。tpc 从 stub 生成声明时自己处理
 *   默认值，C++ 实现侧也不带默认值（见 `php_drift_image_decode`）。
 * ★ 校验维度对齐 Flutter `embedder.h` 的 ABI 规则：函数签名 = 名称 + 参数个数 +
 *   顺序 + 类型（外加返回类型）。
 */
final class Contract
{
    /** tpc 的 C++ 类型 → PHP 声明名；比较统一在 PHP 名上做 */
    private const CC_TO_PHP = [
        'Int' => 'int',
        'Float' => 'float',
        'String' => 'string',
        'Bool' => 'bool',
        'Array' => 'array',
        'void' => 'void',
    ];

    /** ABI 规则版本：校验口径变化时抬它，别让新旧指纹长得一样 */
    private const SCHEMA_VERSION = 'd2abi1';

    /**
     * @return array<string, array{ret:string, params:list<string>}>
     */
    public static function parseStub(string $text): array
    {
        preg_match_all(
            '/function\s+(drift_[a-z_]+)\s*\(([^)]*)\)\s*:\s*([a-z]+)\s*\{\s*\}/s',
            $text,
            $m,
            PREG_SET_ORDER
        );

        return self::index($m, 1, 3, 2);
    }

    /**
     * @return array<string, array{ret:string, params:list<string>}>
     */
    public static function parseCc(string $text): array
    {
        preg_match_all(
            '/(?:^|\n)[ \t]*(Int|String|Array|Bool|Float|void)\s+php_(drift_[a-z_]+)\s*\(([^)]*)\)\s*\{/',
            $text,
            $m,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $x) {
            $out[$x[2]] = [
                'ret' => self::CC_TO_PHP[$x[1]] ?? '?' . $x[1],
                'params' => self::params($x[3], false),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{ret:string, params:list<string>}>
     */
    public static function parseFakes(string $text): array
    {
        preg_match_all(
            '/\bfunction\s+(drift_[a-z_]+)\s*\(([^)]*)\)\s*:\s*([a-z]+)/s',
            $text,
            $m,
            PREG_SET_ORDER
        );

        return self::index($m, 1, 3, 2);
    }

    /**
     * @return array{stub:string, cc:string, fakes:string}
     */
    public static function readTexts(string $root): array
    {
        return [
            'stub' => self::cat($root . '/cpp-src/*.stub.php'),
            'cc' => self::cat($root . '/cpp-src/*.cc'),
            'fakes' => self::cat($root . '/tools/fakes/*.php'),
        ];
    }

    /**
     * @return array{stub:array, cc:array, fakes:array}
     */
    public static function collect(string $root): array
    {
        $t = self::readTexts($root);

        return [
            'stub' => self::parseStub($t['stub']),
            'cc' => self::parseCc($t['cc']),
            'fakes' => self::parseFakes($t['fakes']),
        ];
    }

    /**
     * @return array{
     *   ok:bool, problems:list<string>, counts:array{stub:int,cc:int,fakes:int},
     *   schemaHash:string, fakeMissing:list<string>
     * }
     */
    public static function check(string $root): array
    {
        $c = self::collect($root);

        return self::compare($c['stub'], $c['cc'], $c['fakes']);
    }

    /**
     * @param array<string, array{ret:string, params:list<string>}> $stub
     * @param array<string, array{ret:string, params:list<string>}> $cc
     * @param array<string, array{ret:string, params:list<string>}> $fakes
     * @return array{
     *   ok:bool, problems:list<string>, counts:array{stub:int,cc:int,fakes:int},
     *   schemaHash:string, fakeMissing:list<string>
     * }
     */
    public static function compare(array $stub, array $cc, array $fakes): array
    {
        $problems = [];

        foreach ($stub as $name => $sig) {
            if (!isset($cc[$name])) {
                $problems[] = "{$name}：C++ 缺实现";
                continue;
            }
            $other = $cc[$name];
            if ($sig['ret'] !== $other['ret'] || $sig['params'] !== $other['params']) {
                $problems[] = sprintf(
                    '%s：stub(%s):%s ≠ cc(%s):%s',
                    $name,
                    implode(',', $sig['params']),
                    $sig['ret'],
                    implode(',', $other['params']),
                    $other['ret']
                );
            }
        }
        foreach ($cc as $name => $_) {
            if (!isset($stub[$name])) {
                $problems[] = "{$name}：C++ 多出实现（stub 没声明）";
            }
        }
        foreach ($fakes as $name => $sig) {
            if (!isset($stub[$name])) {
                $problems[] = "{$name}：fakes 多出替身（stub 没声明）";
                continue;
            }
            if ($sig !== $stub[$name]) {
                $problems[] = sprintf(
                    '%s：fakes(%s):%s ≠ stub(%s):%s',
                    $name,
                    implode(',', $sig['params']),
                    $sig['ret'],
                    implode(',', $stub[$name]['params']),
                    $stub[$name]['ret']
                );
            }
        }

        // 替身只需覆盖 smoke 真正走到的函数，缺的不算失败；但必须是可观测的清单。
        $fakeMissing = array_values(array_diff(array_keys($stub), array_keys($fakes)));
        sort($fakeMissing);

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'counts' => ['stub' => count($stub), 'cc' => count($cc), 'fakes' => count($fakes)],
            'schemaHash' => self::schemaHash($stub),
            'fakeMissing' => $fakeMissing,
        ];
    }

    /**
     * 指纹由签名内容导出，不手填、不看 mtime —— 改了签名忘抬版本这类假阴性
     * 正是 COM IID / 裸 version 宏踩过的坑。
     *
     * @param array<string, array{ret:string, params:list<string>}> $stub
     */
    public static function schemaHash(array $stub): string
    {
        $sorted = $stub;
        ksort($sorted);

        return self::SCHEMA_VERSION . ':' . substr(
            hash('sha256', (string) json_encode($sorted, JSON_THROW_ON_ERROR)),
            0,
            16
        );
    }

    /**
     * @param list<array<int, string>> $m
     * @return array<string, array{ret:string, params:list<string>}>
     */
    private static function index(array $m, int $nameAt, int $retAt, int $paramsAt): array
    {
        $out = [];
        foreach ($m as $x) {
            $out[$x[$nameAt]] = [
                'ret' => strtolower($x[$retAt]),
                'params' => self::params($x[$paramsAt], true),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function params(string $raw, bool $lower): array
    {
        $out = [];
        foreach (explode(',', trim($raw)) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', $part) ?: [];
            $type = $tokens[0] ?? '';
            $out[] = $lower ? strtolower($type) : (self::CC_TO_PHP[$type] ?? '?' . $type);
        }

        return $out;
    }

    private static function cat(string $glob): string
    {
        $parts = [];
        foreach (glob($glob) ?: [] as $file) {
            $parts[] = (string) file_get_contents($file);
        }

        return implode("\n", $parts);
    }
}
