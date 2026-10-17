<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 字体文件定位：按候选目录找一份随应用分发的字体。
 *
 * ```php
 * $path = FontLocator::find('NotoSansSC-Regular.ttf');
 * ```
 *
 * ## 为什么要它（而不是各处拼路径）
 *
 * "字体放哪儿"是**分发形态**的知识，只该有一处答案（与 `drift_skia.cc` 的
 * `ensureFont()` 同源）。散在 `Application` / 桥 / 示例里，改一次要改三处，
 * 漏一处就是"某个形态下字体找不到、布局悄悄退回位图宽度"。
 *
 * 顺序（**分发形态优先**）：
 *   ① `<cwd>/fonts` —— exe 旁的 `fonts/`（打包形态：`app.exe` + `fonts/*.ttf`）
 *   ② `<cwd>/cpp/fonts` —— 仓库根直接跑（解释器面 / 开发时）
 *   ③ `<src>/../../cpp/fonts` —— 源码树（`__DIR__` 在 AOT 下是构建期路径，
 *      找不到也无害，只是多试一次）
 *
 * ★ **找不到不报错**：返回 `null`，调用方降级到确定性位图面（`soft` 后端本来
 *   就用它）。但**降级要留痕** —— 见 `TtfFace::fromFile()` 的 Diagnostic。
 *
 * @api
 */
final class FontLocator
{
    /**
     * @return list<string> 候选目录（按优先级，末尾无斜杠）
     */
    public static function candidates(): array
    {
        $dirs = [];
        $cwd = getcwd();

        if ($cwd !== false && $cwd !== '') {
            $dirs[] = $cwd . DIRECTORY_SEPARATOR . 'fonts';
            $dirs[] = $cwd . DIRECTORY_SEPARATOR . 'cpp' . DIRECTORY_SEPARATOR . 'fonts';
        }

        // src/Text → 仓库根（`__DIR__` 两级的上一级）
        $dirs[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cpp' . DIRECTORY_SEPARATOR . 'fonts';

        return $dirs;
    }

    /**
     * 在候选目录里找第一份存在的 `$fileName`。
     *
     * @param list<string>|null $dirs 显式目录（`null` ⇒ `candidates()`；测试用）
     */
    public static function find(string $fileName, ?array $dirs = null): ?string
    {
        $list = $dirs ?? self::candidates();

        foreach ($list as $dir) {
            $path = $dir . DIRECTORY_SEPARATOR . $fileName;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
