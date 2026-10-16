<?php
declare(strict_types=1);

/**
 * font-scan-chars —— 扫描全仓 PHP 文件，提取【字符串字面量】里的非 ASCII 字符。
 *
 * ```bash
 * php tools/font-scan-chars.php            # 人读的清单
 * php tools/font-scan-chars.php --json     # 只吐 JSON
 * ```
 *
 * ★ 输出写到 `_build/cjk_used.json`（供 `tools/font-gen-glyphs.py` 消费）。
 *
 * ★ 为什么扫**所有 cp >= 0xA0 的字符**而不只是汉字：确定性拉丁字模只覆盖
 *   `0x20–0x7E`，而示例/应用里大量用到中文标点（`，。（）「」：；`）与箭头
 *   （`↑ ⇒`）—— 只扫 `U+4E00–U+9FFF` 会让这些字符静默无墨（2026-10-16 踩过：
 *   `DemoPage` 的句号和逗号整个消失，画面看起来"少字"）。
 *
 * ★ 只认 `T_CONSTANT_ENCAPSED_STRING`（字符串字面量）⇒ 注释里的中文不参与
 *   （注释不是上屏文本，收进来只会白白撑大字模表）。
 *
 * ★ 这是**生成链的第 ① 步**，产物是 `src/Text/CjkGlyphData.php`（第 ② 步：
 *   `tools/font-gen-glyphs.py`）。真正的"通用 CJK 后端"（任意码位、按需打包）
 *   仍归 M4（O-08）—— 这里只是把**当前用到的**字符补齐（最小补丁，不做语料库）。
 *
 * 退出码：0 = 扫描成功。
 */

/** 不参与扫描的目录（生成物 / 工作目录 / 非项目代码） */
const SKIP_DIRS = ['.git/', '_build/', '_codegen/', '_p0/', 'probes/', '.workbuddy-ai/', 'out/'];

$root = dirname(__DIR__);
$jsonOnly = in_array('--json', $argv, true);
$chars = [];
$files = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);
foreach ($it as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php')) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    foreach (SKIP_DIRS as $skip) {
        if (str_starts_with($rel, $skip)) {
            continue 2;
        }
    }

    $files++;
    $tokens = token_get_all((string) file_get_contents($path));
    foreach ($tokens as $tok) {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $len = mb_strlen($tok[1], 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($tok[1], $i, 1, 'UTF-8');
            // 拉丁字模覆盖 0x20–0x7E；其余可见字符都要补
            // （控制区 0x7F–0x9F 不会出现在字面量里）
            if (mb_ord($ch, 'UTF-8') >= 0xA0) {
                $chars[$ch] = ($chars[$ch] ?? 0) + 1;
            }
        }
    }
}

ksort($chars);
$list = array_keys($chars);
$payload = json_encode($list, JSON_UNESCAPED_UNICODE);

$outDir = $root . '/_build';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/cjk_used.json', $payload);

if ($jsonOnly) {
    fwrite(STDOUT, $payload . "\n");
    exit(0);
}

fwrite(STDOUT, '扫描文件 ' . $files . " 个\n");
fwrite(STDOUT, '字符串字面量里的不同非 ASCII 字符 ' . count($list) . " 个：\n");
fwrite(STDOUT, implode('', $list) . "\n");
fwrite(STDOUT, "已写出 _build/cjk_used.json（下一步：python tools/font-gen-glyphs.py）\n");
exit(0);
