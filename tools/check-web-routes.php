<?php
declare(strict_types=1);

/**
 * Web 调试页的前后端**路由对账**（机器判据）。
 *
 * ★ 为什么需要：`Web::route()` 的 `match` 与前端 `app.js` 里的 `fetch` 是两份手写的
 *   清单。后端加一条路由没人调，不会有任何报错 —— 它只是永远返回 404 都没人知道；
 *   前端调一条后端没有的路由，只有用户点到那个按钮时才会炸，而那一刻往往正是
 *   最需要工具的时候。`/api/repaints` 就是这样活了一整轮的：后端建好了、前端一行
 *   都没写、测试也照不到（路由本身不报错）。
 *
 * ★ 判据四条：
 *   ① 每条 `/api/*` 路由在前端至少被引用一次（否则是死路由）；
 *   ② 前端引用的每条 `/api/*` 都在路由表里（否则是断链）；
 *   ③ `asset()` 吐的每个文件都真的存在（否则页面白屏）；
 *   ④ 前端 `getElementById` 找的每个 id 都在 `index.html` 里（否则同一个白屏）。
 *
 * ★ 不 require autoload：守门器不依赖它要检查的那套代码，只读文本。
 *   解析用正则而不是 PHP 词法，因为路由表就是一张字面量清单 —— 上词法反而是
 *   把简单问题做复杂。凡是被这两个文件写歪到正则认不出的形态，宁可报红让人看。
 *
 * 用法：
 *   php tools/check-web-routes.php              # 对账，exit 0/1
 *   php tools/check-web-routes.php --selftest   # 守门器自证（合成用例）
 */

/**
 * 刻意不给前端用、只对外部消费者（curl / 别的观察者）开的接口。
 * 加进来必须写清理由 —— 这张表存在的意义就是"死路由不能靠沉默活着"。
 *
 * @return array<string, string> 路径 => 理由
 */
function externalRoutes(): array
{
    return [];
}

/**
 * 从 `Web::route()` 抽路由清单。
 *
 * 只看 `route()` 方法体，避免把别的函数里同形状的字面量算进来。两条来源：
 * `match` 的 arm（`'/api/x' => ...`）与提前返回的 POST 分支（`$path === '/api/ctl'`）。
 *
 * @return list<string>
 */
function parseRoutes(string $php): array
{
    $start = strpos($php, 'public function route(');
    if ($start === false) {
        return [];
    }
    $end = strpos($php, 'private function ', $start);
    $body = $end === false ? substr($php, $start) : substr($php, $start, $end - $start);

    $routes = [];
    // match arm：单引号字面量且以 / 开头。
    if (preg_match_all("~'(/[^']*)'~", $body, $m)) {
        foreach ($m[1] as $path) {
            $routes[] = $path;
        }
    }
    // 提前返回的分支：`$path === '/api/ctl'`。
    if (preg_match_all("~\\\$path === '(/[^']*)'~", $body, $m)) {
        foreach ($m[1] as $path) {
            $routes[] = $path;
        }
    }

    return array_values(array_unique($routes));
}

/**
 * `asset()` 吐的文件名。它们必须真在 web 目录里，否则页面加载即 404。
 *
 * @return list<string>
 */
function parseAssets(string $php): array
{
    $files = [];
    if (preg_match_all("~\\\$this->asset\('([^']+)'~", $php, $m)) {
        foreach ($m[1] as $name) {
            $files[] = $name;
        }
    }

    return array_values(array_unique($files));
}

/**
 * 前端引用到的 `/api/*` 路径。
 *
 * 直接扫全文而不是只扫 `fetch(` 的实参：路径常常拼在模板串里、或先存进变量
 * （`img.src = '/api/shot?serial=' + n`）。扫全文的代价是可能多认，但"多认"
 * 只会让死路由更难藏 —— 方向是对的。
 *
 * @return list<string>
 */
function parseRefs(string $js): array
{
    $refs = [];
    if (preg_match_all('~/api/[a-z0-9/]*~', $js, $m)) {
        foreach ($m[0] as $path) {
            $refs[] = rtrim($path, '/');
        }
    }

    return array_values(array_unique($refs));
}

/**
 * 前端用 `getElementById` 找的 id。
 *
 * ★ 同一类漂移的另一半：改 `index.html` 里的 id 而不改 `app.js`，取到 `null`，
 *   下一次 `.textContent = ...` 就把整个页面炸掉（报错还落在 boot 的 catch 里，
 *   标签栏写一行"出错：..."就完了）。这一条和路由对账是同一条原则：
 *   **两份手写清单之间必须有机器对账**。
 *
 * @return list<string>
 */
function parseIds(string $js): array
{
    $ids = [];
    if (preg_match_all("~getElementById\\('([^']+)'\\)~", $js, $m)) {
        foreach ($m[1] as $id) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * `index.html` 里定义的 id。反向不查：`#stage` / `#side` / `#axis` 这些是
 * 布局与 `<details>` 的锚点，本来就不需要 JS 去碰它们。
 *
 * @return list<string>
 */
function parseHtmlIds(string $html): array
{
    $ids = [];
    if (preg_match_all('~\\bid="([^"]+)"~', $html, $m)) {
        foreach ($m[1] as $id) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * 纯对账：给定三张清单，返回问题清单（空 = 通过）。自证与实跑共用这一份。
 *
 * @param list<string>          $routes
 * @param list<string>          $refs
 * @param array<string, string> $external
 * @return list<string>
 */
function diffRoutes(array $routes, array $refs, array $external): array
{
    $api = [];
    foreach ($routes as $path) {
        if (str_starts_with($path, '/api/')) {
            $api[] = $path;
        }
    }
    $refs = array_flip($refs);
    $errors = [];

    foreach ($api as $path) {
        if (!isset($refs[$path]) && !isset($external[$path])) {
            $errors[] = "死路由：{$path} 后端有、前端一次都没引用（要么接上，要么删掉，要么进 externalRoutes 写明理由）";
        }
    }
    $known = array_flip($api);
    foreach (array_keys($refs) as $path) {
        if (!isset($known[$path])) {
            $errors[] = "断链：前端在调 {$path}，后端路由表里没有它";
        }
    }

    return $errors;
}

/**
 * id 对账：前端找的每个 id 都必须在页面里存在。反向不查（见 `parseHtmlIds()`）。
 *
 * @param list<string> $refs
 * @param list<string> $defined
 * @return list<string>
 */
function diffIds(array $refs, array $defined): array
{
    $ok = \array_flip($defined);
    $errors = [];
    foreach ($refs as $id) {
        if (!isset($ok[$id])) {
            $errors[] = "断链：前端 getElementById('{$id}')，index.html 里没有这个 id（取名不报错，页面白屏）";
        }
    }

    return $errors;
}

/** 合成用例自证：四条都要咬得住。 */
function selftest(): int
{
    $failures = 0;
    $check = static function (string $name, bool $ok) use (&$failures): void {
        echo '  ' . ($ok ? '✓' : '✗') . " {$name}\n";
        if (!$ok) {
            $failures++;
        }
    };

    $got = diffRoutes(['/api/a', '/api/b'], ['/api/a', '/api/b'], []);
    $check('两边对齐 ⇒ 绿', $got === []);

    $got = diffRoutes(['/api/a', '/api/b'], ['/api/a'], []);
    $check('后端多一条 ⇒ 咬红死路由', $got === [
        '死路由：/api/b 后端有、前端一次都没引用（要么接上，要么删掉，要么进 externalRoutes 写明理由）',
    ]);

    $got = diffRoutes(['/api/a'], ['/api/a', '/api/ghost'], []);
    $check('前端多一条 ⇒ 咬红断链', $got === [
        '断链：前端在调 /api/ghost，后端路由表里没有它',
    ]);

    $got = diffRoutes(['/api/a', '/api/b'], ['/api/a'], ['/api/b' => '只给外部消费者']);
    $check('external 里有理由 ⇒ 不报', $got === []);

    // 非 /api/ 的路径不进对账（静态资源走 asset() 存在性那条）。
    $got = diffRoutes(['/', '/app.js'], [], []);
    $check('静态路径不参与 /api 对账', $got === []);

    // id 契约：前端找得到、页面里也定义过。
    $missing = diffIds(['tree', 'hint'], ['tree', 'hint', 'stage']);
    $check('id 两边对得上 ⇒ 绿', $missing === []);

    $missing = diffIds(['tree', 'ghost'], ['tree']);
    $check('前端找了页面没有的 id ⇒ 咬红', $missing === [
        '断链：前端 getElementById(\'ghost\')，index.html 里没有这个 id（取名不报错，页面白屏）',
    ]);

    echo $failures === 0
        ? "[check-web-routes --selftest] ✓ 全过\n"
        : "[check-web-routes --selftest] ✗ {$failures} 条红\n";

    return $failures === 0 ? 0 : 1;
}

$root = dirname(__DIR__);
if (in_array('--selftest', $argv, true)) {
    exit(selftest());
}

$webDir = $root . '/tools/Debug/View';
$php = (string) file_get_contents($webDir . '/Web.php');
$js = (string) file_get_contents($webDir . '/web/app.js');
$html = (string) file_get_contents($webDir . '/web/index.html');

$routes = parseRoutes($php);
$refs = parseRefs($js);
$ids = parseIds($js);

echo '[check-web-routes] 后端 ' . count($routes) . " 条路由 / 前端引用 "
    . count($refs) . ' 条 /api 路径 / ' . count($ids) . " 个元素 id\n";

$errors = diffRoutes($routes, $refs, externalRoutes());
$errors = [...$errors, ...diffIds($ids, parseHtmlIds($html))];

foreach (parseAssets($php) as $name) {
    if (!is_file($webDir . '/web/' . $name)) {
        $errors[] = "asset 缺文件：{$name}（Web.php 里在 serve 它，web/ 下没有）";
    }
}

// 路由表里既不是 /api/ 也不是 asset() 见过的路径 ⇒ 有人加了路由却忘了登记它的来源，
// 这类"没有归类的路径"是下一次漂移的温床，直接报出来让人分类。
$assets = array_flip(parseAssets($php));
foreach ($routes as $path) {
    if (str_starts_with($path, '/api/') || $path === '/' || isset($assets[ltrim($path, '/')])) {
        continue;
    }
    $errors[] = "未归类路由：{$path}（既不是 /api/ 也不是 asset() 吐的文件）";
}

if ($errors === []) {
    echo "[check-web-routes] ✓ 前后端对得上\n";
    exit(0);
}

foreach ($errors as $e) {
    fwrite(STDERR, "  - {$e}\n");
}
echo '[check-web-routes] ✗ ' . count($errors) . " 条红\n";
exit(1);
