<?php
declare(strict_types=1);

/**
 * `drift-preview` 的夹具（§A.2.4 的离线形态）：给 `UsersApplication` 一个内存数据源。
 *
 * ```bash
 * php tools/drift-preview.php --app=apps/users/src/UsersApplication.php \
 *     --fixture=apps/users/tests/fixtures/memory_source.php \
 *     --width=900 --height=640 --out=out/users-app.svg
 * ```
 *
 * ★ **它在 `tests/` 下、不进 AOT 产物**（`project.yml` 的 `ignore:`）—— 本条不是洁癖：
 *   夹具必须写顶层 `return`（`drift-preview` 的约定），而 **AOT 编译器的判据里没有
 *   `Stmt_Return`** ⇒ 它一旦落在被 `sources` 扫到的目录里，构建当场中止：
 *   `Fatal error: Unsupported statement: Stmt_Return`。
 *   约定位置来自 §16.7 的示例（`apps/users/tests/fixtures/...`）。
 */

return new App\Users\Store\MemoryUserSource();
