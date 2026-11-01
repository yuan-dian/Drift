<?php
declare(strict_types=1);

/**
 * probe-audit —— 真机探针的**骨架与输入通道**静态检查。
 *
 * 用法：
 *   php tools/probe-audit.php            检查 tools/probe-*.py
 *   php tools/probe-audit.php --selftest 量具自检（规则必须真的会响）
 *
 * ★ 为什么需要它（2026-11-01，两次假红 + 五份输入实现）
 *
 *   探针报错比产品报错贵得多：产品坏了看得见，探针坏了只会留下一句
 *   「滚动条一条都没画」，下一棒照着它追一整轮。
 *
 *   两次假红的共同成因不是某个探针写错，而是**同一件事被允许写多遍**：
 *
 *     输入通道   `SetCursorPos` + `mouse_event` / `PostMessage` / 混合   —— 五份
 *     截图       `BitBlt` + `GetDIBits` 的四种写法（bytes / bytearray / 类）—— 四份
 *     启动       `PHPRC` + `opcache.enable=0` 临时 ini                  —— 九份
 *     结论       `ok/FAIL` 两态（"没测到"无处可写）                    —— 四份
 *
 *   ⇒ 结构上收进 `probe_win.py` / `probe_check.py` / `probe_pixels.py` 之后，
 *   还需要一条**会红的判据**挡住"下一个人再抄一份"。约定挡不住，闸门挡得住。
 *
 * ★ 与 `tools/Lint/Rules/BackendBranch.php` 的分工：那条禁"控件层按后端分叉"，
 *   这条禁"探针自己造输入/截图/结论"。两者都是"不许有第二条实现"。
 */

$root = dirname(__DIR__);
$files = glob($root . '/tools/probe-*.py') ?: [];
sort($files);

/**
 * 规则表：`[名字, 正则, 说明]`。
 *
 * ★ 只列**必须唯一**的东西，不列风格。每一条都对应一次真实事故。
 */
$rules = [
    [
        'input-via-message',
        '/\b(0x0200|0x0201|0x0202|0x020A|0x0100|0x0102|0x0104|WM_MOUSEMOVE|WM_LBUTTONDOWN|WM_LBUTTONUP|WM_MOUSEWHEEL|WM_KEYDOWN|WM_CHAR)\b/',
        '输入不许走 PostMessage —— 用 probe_win.move/click/wheel（真实光标 + mouse_event）。'
        . '这条通道已被证伪两次：TME_LEAVE 与 ScrollBarFade::noteScroll() 认的是真实输入',
    ],
    [
        'duplicate-input',
        '/^def\s+(move|click|wheel|to_screen|screen_to_client|click_at|wheel_at)\s*\(/m',
        '输入/坐标换算已有唯一实现：probe_win（本文件不许再定义一份）',
    ],
    [
        'duplicate-capture',
        '/^def\s+(grab|grab_client|nonwhite_ratio|focus_window)\s*\(|CreateCompatibleBitmap/m',
        '截图已有唯一实现：probe_win.grab；非白占比用 probe_pixels.nonwhite_ratio',
    ],
    [
        'duplicate-launch',
        '/\bPHPRC\b|opcache\.enable/',
        '启动环境（PHPRC + opcache.enable=0）已有唯一实现：probe_win.launch',
    ],
    [
        'duplicate-verdict',
        '/^def\s+check\s*\(/m',
        '结论协议已有唯一实现：probe_check.check（三态：PASS / FAIL / 没测到）',
    ],
    [
        'raw-popen',
        '/\bsubprocess\.Popen\b/',
        '起产物用 probe_win.launch（它会带 PHPRC、报"进程早退"的死因、隔离用户窗口状态）',
    ],
    [
        'no-shared-import',
        '/^import\s+(probe_win|probe_check|probe_pixels)|^from\s+(probe_win|probe_check|probe_pixels)\s+import/m',
        '每个探针都必须至少用一件共享件（否则它一定自己抄了输入/截图/结论）',
        true,   // inverted：**必须命中**
    ],
];

/**
 * 把注释与文档字符串抹成空格（**保留换行**，行号才对得上）。
 *
 * ★ 第一版没做这一步，5 条命中**全打在解释性注释上**：
 *   `probe-motion.py` 的文档里写着"不要用 WM_MOUSEMOVE"、`probe-io-pool.py`
 *   的注释里写着"PHPRC 指向 opcache.enable=0"——它们恰恰是**遵守**规则的证据。
 *   一个会对散文开火的规则，下一棒只会把它关掉；所以先剥离再匹配。
 *
 * ★ 保留 newline 是硬要求：报错要给"文件:行号"，抹掉换行等于把定位能力一起抹了。
 */
function drift_probe_code_only(string $text): string
{
    $blank = static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]);

    // 三引号块（docstring）—— 先长后短，避免 `"""` 被 `'''` 规则误伤
    $text = (string) preg_replace_callback('/"""(?:.|\n)*?"""|\'\'\'(?:.|\n)*?\'\'\'/', $blank, $text);

    // 行注释：`#` 到行尾（本仓探针里 `#` 不出现在字符串字面量内）
    return (string) preg_replace('/#[^\n]*/', '', $text);
}

$errors = 0;
$checked = 0;

foreach ($files as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $text = drift_probe_code_only((string) file_get_contents($file));
    $checked++;

    foreach ($rules as $rule) {
        [$name, $pattern, $why] = $rule;
        $mustMatch = $rule[3] ?? false;

        if ($mustMatch) {
            if (!preg_match($pattern, $text)) {
                printf("%-34s [%s]  %s\n", $rel, $name, $why);
                $errors++;
            }
            continue;
        }

        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as [$hit, $offset]) {
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            printf("%-34s:%d  [%s]  %s（命中 %s）\n", $rel, $line, $name, $why, trim($hit));
            $errors++;
        }
    }
}

printf("\n--- %d 个探针 · %d error ---\n", $checked, $errors);

if (in_array('--selftest', $args = array_slice($argv, 1), true)) {
    // ★ 量具自检：把规则喂给**已知该响/该静**的样本。规则写错（比如正则漏了分组）
    //   时，正式扫描会显示"0 error"—— 那才是最危险的绿。
    // ★ 每条规则都得在自己的样本上响一次 —— 样本只写一两行会让"没写到的规则"
    //   看起来永远绿（第一版 6 条里有 4 条根本没被喂到样本）。
    $dirty = implode("\n", [
        'def grab(hwnd):',
        'def click(hwnd, x, y):',
        'def check(name, ok):',
        'env["PHPRC"] = ini',
        'user32.PostMessageW(h, 0x020A, 0, 0)',
        'proc = subprocess.Popen([exe])',
    ]);
    $clean = "from probe_win import App\n\ndef main():\n    app = App('x.exe', 'T')\n";
    $bad = 0;
    foreach ($rules as $rule) {
        if (!empty($rule[3])) {
            continue;
        }
        if (!preg_match($rule[1], $dirty)) {
            printf("  SELFTEST FAIL  %s 没在脏样本上命中\n", $rule[0]);
            $bad++;
        }
        if (preg_match($rule[1], $clean)) {
            printf("  SELFTEST FAIL  %s 在干净样本上误报\n", $rule[0]);
            $bad++;
        }
    }
    printf("selftest: %d 条规则 %s\n", count($rules) - 1, $bad === 0 ? '双向都对' : "有 {$bad} 条不对");
    exit($bad === 0 && $errors === 0 ? 0 : 1);
}

exit($errors > 0 ? 1 : 0);
