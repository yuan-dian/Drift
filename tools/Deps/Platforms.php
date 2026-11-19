<?php
declare(strict_types=1);

namespace Drift\Dev\Deps;

/**
 * 依赖包的唯一平台表。
 *
 * deps-v1 只有 win-x64 一个资产；deps-v2 起每个平台一个资产，共享同一份
 * deps.lock.json 的版本锚点与 skiaRevision。将来加 macOS 只在这里添一行，
 * 并把对应平台的 Skia 产物接进构建链 —— pack/fetch/release 不再改平台常量。
 */
final class Platforms
{
    public const WIN = 'win-x64';
    public const LINUX = 'linux-x64';
    public const MACOS = 'macos-arm64';

    /**
     * @return array<string, array{id:string,os:string,asset:string,skiaDir:string,libExt:string,minLibs:int,published:bool}>
     */
    public static function all(): array
    {
        return [
            self::WIN => [
                'id' => self::WIN,
                'os' => 'Windows',
                'asset' => 'drift-deps-win-x64.zip',
                'skiaDir' => 'cpp/skia',
                'libExt' => 'lib',
                // = build/base.yml 实际链接的 skia .lib 数（assert-workflows 对账）。
                // deps-v1 的 20 是未裁剪时旧值；v2 裁掉 PDF/skshaper 后 harfbuzz /
                // jsonreader 不再独立产出，链接清单降到 10，这里同步。CI 真编 13 个。
                'minLibs' => 10,
                'published' => true,
            ],
            self::LINUX => [
                'id' => self::LINUX,
                'os' => 'Linux',
                'asset' => 'drift-deps-linux-x64.zip',
                'skiaDir' => 'cpp/skia-linux',
                'libExt' => 'a',
                'minLibs' => 10,
                'published' => true,
            ],
            // 预留位：产物与 CI 通道就绪后把 published 改成 true 即可。
            self::MACOS => [
                'id' => self::MACOS,
                'os' => 'Darwin',
                'asset' => 'drift-deps-macos-arm64.zip',
                'skiaDir' => 'cpp/skia-macos',
                'libExt' => 'a',
                'minLibs' => 10,
                'published' => false,
            ],
        ];
    }

    /** @return list<array{id:string,os:string,asset:string,skiaDir:string,libExt:string,minLibs:int,published:bool}> */
    public static function published(): array
    {
        return array_values(array_filter(self::all(), static fn (array $p): bool => $p['published']));
    }

    public static function current(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => self::WIN,
            'Linux' => self::LINUX,
            'Darwin' => self::MACOS,
            default => throw new \RuntimeException('unsupported host OS: ' . PHP_OS_FAMILY),
        };
    }

    /**
     * @return array{id:string,os:string,asset:string,skiaDir:string,libExt:string,minLibs:int,published:bool}
     */
    public static function get(string $id): array
    {
        $all = self::all();
        if (!isset($all[$id])) {
            throw new \InvalidArgumentException('unknown deps platform: ' . $id . '; known: ' . implode(', ', array_keys($all)));
        }
        return $all[$id];
    }

    /** deps-v1 是不可变单包；v2 起才允许多平台表。 */
    public static function multiPlatformTag(string $depsTag): bool
    {
        return preg_match('/^deps-v(\d+)$/', $depsTag, $m) === 1 && (int) $m[1] >= 2;
    }
}
