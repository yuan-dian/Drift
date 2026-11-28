# 编译脚本评审（2026-11-28）

对象：`tools/drift-build.php`（564 行）、`tools/drift-dev.php`（794 行），以及它们消费的
`tools/Build/StalePolicy.php`、`tools/Doctor/Environment.php`。

结论先说：**脚本不是"写得乱"，是只写了成功路径**。缓存策略抽成纯函数、selftest 有正反例、
注释把每个坑的来历都记了 —— 这些质量都在平均线以上。缺的是**进程生命周期**：没有看门狗、
没有中断清理、没有实时进度。于是上游 tpc 的偶发收尾死锁，被放大成"卡一次之后连着卡"。

---

## 1. P0 — 直接造成"老是卡"

### P0-1 等待循环没有看门狗

`tools/drift-build.php:511-519`：

```php
while (true) {
    $status = proc_get_status($process);
    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    if (!$status['running']) {
        break;
    }
    usleep(50000);
}
```

只判"进程还在不在"，没有任何墙钟上限，也没有"多久没进展"的判据。tpc 在收尾阶段
（`.obj` 已全落盘、`cl.exe`/`link.exe` 已退、只剩一个 `php.exe` 涨到 300MB+）卡住时，
这里就是无限等待。历史实测：watermark 卡过 17 分钟、users 卡过 9 分钟、今天上午全量卡了
4 分钟无产物。**这不是慢，是永远等不到。**

修法：加"停滞超时"而不是"总超时"（全量构建正常就要 200s+，总超时会误杀）：

- 进展信号取两个：stdout/stderr 出现了新字节；`build/` 与 `apps/<app>/build/` 下文件
  mtime 有更新。两者同时静默 `--stall-timeout`（默认 300s）⇒ 判停滞。
- 停滞时 `taskkill /T /F /PID <tpc pid>` 杀整棵树，再抛明确错误：
  `tpc 停滞 300s（最后输出：...）已清理进程树；重跑 --incremental`。
- 阈值可配（`--stall-timeout=秒` / `DRIFT_BUILD_STALL_TIMEOUT`），因为别的机器全量可能更久。

判据：用一个假 tpc（`php -r 'sleep(10)'` 冒充）跑，10s 内必须被杀且退出码非 0；
真构建全量（>200s 但有输出）不能被误杀。

### P0-2 中断不清理子进程树，制造孤儿

两处叠加：

- `tools/drift-dev.php:706-707` 用 `passthru()` 同步调构建。它不返回子进程句柄，
  父进程一死就无从清理。
- `tools/drift-build.php:496-505` 的 `proc_open()` 没传 `bypass_shell`，Windows 上
  命令会经一层 `cmd.exe`；父进程被工具层 90–120s 的 SIGTERM 截断后，`cmd.exe` 与
  `tpc.php` 都活着，成为孤儿。

孤儿 `tpc.php` 握着 MSVC 的 PDB 句柄，下一次构建在链接阶段等锁 —— 历史实测 10 分钟、
CPU 143s 不动、产物无更新。**"卡一次，后面都卡，直到手动清进程"就是这个机制。**

修法：

- `drift-dev` 的构建调用改成 `proc_open()` + 显式句柄，复用已有的 `stop()` 思路；
- `drift-build` 的 `proc_open` 加 `['bypass_shell' => true]`，少一层 `cmd.exe`；
- 正常退出、`exit`、`register_shutdown_function` 三条路径都 `taskkill /T /F /PID`；
- 把"上次构建的 tpc PID"落到 `_build/tpc.pid`，启动新构建前先检查该 PID 是否还活着，
  活着就先杀 —— 这是**对已经发生的孤儿**的兜底，比重启机器现实。

判据：起一个假 tpc（`sleep(600)`），把 `drift-build` 父进程 SIGTERM 掉，1s 内
`tasklist` 里不能残留该子进程；再跑一次构建，`_build/tpc.pid` 的旧 PID 已被清理。

### P0-3 构建输出不可观测

`tools/drift-build.php:510-537` 把 stdout/stderr 全攒进 `$output`，循环里**一个字都不打**；
成功时只打一行总结（553-558），失败时才回显匹配 `error C` / `LNK` / `Fatal` 的行；
而 tpc 的 `Compiling [███…]` 进度条在 533 行被显式过滤掉。

后果：构建中日志长时间 0 字节。再叠上 `php … | tail -20` 的整段缓冲，就出现"11 分钟零输出"。
于是**真卡与假卡无法区分**，人只能猜、只能杀 —— 而杀一次就触发 P0-2。

修法：

- 循环里把新读到的字节直接 `fwrite(STDOUT, ...)`，保留 `\r` 进度条（终端会原地刷新；
  落日志时再按 `\r` 归一）；
- 每 15–30s 打一行心跳：`[drift-build] 仍在编译 … 已 95s，最后进展 3s 前`；
- `Compiling [███]` 不过滤，只在写日志时压缩。

判据：构建期间 `tail -f /tmp/build.log` 必须有持续输出；停滞时能看到最后一行进度。

---

## 2. P1 — 正确性与易用性

### P1-1 `taskkill /IM dev.exe` 会误杀别的实例

`tools/drift-dev.php:354-359`：

```php
$requestClose = ... exec('taskkill /IM dev.exe 2>&1');
$forceKill = ... exec('taskkill /IM dev.exe /F 2>&1');
```

按**映像名**杀，不是按本次启动的进程。两个终端各跑一个 `drift-dev`（或 showcase 与 dev
同时开着）时，关掉一个会把另一个也关掉；更糟的是另一个实例的 `finally` 不跑，业务状态丢失。
注释说"pid 不可靠"是事实（`proc_get_status()['pid']` 对 AOT 进程查不到），但 `/IM` 的代价
没写。

修法：启动前用 `tasklist /FI "IMAGENAME eq dev.exe" /FO CSV` 记下现存 PID 集合，启动后再取一次，
差集就是本次的进程；停止时只杀这个 PID。取不到差集时退回 `/IM`，但打一行警告。

判据：同时起两个 `dev.exe`，停其中一个，另一个仍在。

### P1-2 `--jobs N` 会把 N 当成应用名

`tools/drift-build.php:263-269` 的位置参数解析先于选项过滤：

```php
foreach ($argvRest as $argument) {
    if ($argument !== '' && $argument[0] !== '-') {
        $target = $argument;   // ← `--jobs` 后面的 2 也会进来
        break;
    }
}
```

已复现：

```
$ php tools/drift-build.php --jobs 2 hello --dry
[drift-build] ✗ 找不到工程文件：…/apps/2/project.yml
```

`hello --jobs 2` 正常，`--jobs 2 hello` 报错。`--jobs=2` 两种顺序都正常 —— 也就是说
bug 只在"等号形式被写成空格形式"时出现，正是 usage 里没写、但人最自然的写法。

修法：解析选项时先剥掉 `--jobs` 及其值（以及所有 `--xxx` 的值），再取第一个位置参数；
或者先收集所有非选项，再排除"紧跟在需要值的选项后面的那个"。

判据：`--jobs 2 hello --dry` 与 `hello --jobs 2 --dry` 决策一致。

### P1-3 默认并发与已知本机挂死矛盾

`tools/drift-build.php:243-245` 的注释：

> 本机 `cl.exe` 并发编译偶发挂死（CPU≈0、8MB、管道写满死锁）时用 `--jobs 1` 串行绕开。

但默认值仍是 `0`（不传 `-j`），tpc 自己的默认是 4。**知道会挂死，却把安全值留成手动开关。**
今天上午正是 `--incremental --jobs 1` 152s 成功。

修法二选一：

- 本机/CI 明确不支持并发 → 默认 `-j 1`，需要并发的显式 `--jobs 4`；
- 保留并发，但把"已知挂死环境"做成检测（如 `DRIFT_BUILD_JOBS` 默认 1），并在挂死时自动降级重试一次。

判据：不传 `--jobs` 时命令行里出现 `-j 1`（可用 `--dry --print-command` 暴露）。

### P1-4 旧 exe 先删后编译，启动失败即丢产物

`tools/drift-build.php:488-496`：先 `unlink($exe)`，再 `proc_open()`。若 `proc_open` 失败
（环境变量、PHP 路径、权限），用户既没有新 exe，也没有旧 exe。

修法：先把旧 exe 改名为 `{$exe}.bak`，构建成功且判据通过后删备份；失败则还原。
或者 `proc_open` 成功后再删旧 exe（tpc 的输出名固定，不能直接写临时名）。

判据：模拟 `proc_open` 失败，`{$exe}.bak` 被还原成 `{$exe}`。

### P1-5 根 `php.ini` 是生成物，却受版本控制且每次构建重写

`tools/drift-build.php:156-172` 每次构建都写 `{$root}/php.ini`，内容是**构建机绝对路径**：

```
extension_dir = "D:\TypePHP\typephp-windows-x64/ext/"
```

而 `git ls-files php.ini` 显示它**在库里**，`.gitignore` 也没忽略它。后果：

- 每次构建 `git status` 都脏；
- 换机器 / 换盘符后 extension_dir 指向不存在路径；
- 不同人构建后互相覆盖，提交进仓库就是给别人埋 `undefined function mb_substr()`。
- `drift-dev.php:247-248` 又把这份文件当运行时 ini 的底 —— 生成物反过来成了输入。

修法：`php.ini` 从索引移除并进 `.gitignore`；要保留模板就改名 `php.ini.dist` 并在
`Environment` 里"不存在才从模板复制"。判据：连续构建两次，`git status` 干净。

---

## 3. P2 — 健壮性与维护性

### P2-1 `wipeCache()` 不检查删除结果

`tools/drift-build.php:117-150` 的 `unlink()` / `rmdir()` 都没有 `@` 也没有返回值判断。
Windows 上文件被占用（上一次的 `cl.exe` 还没退、编辑器开着 `.cc`）时，PHP 打 warning、
循环继续，缓存处于**半清**状态，后续构建行为不可预测；而 warning 又被 `drift-build` 的
输出收集吞掉。

修法：收集失败项，任一失败就 `fail("缓存清不干净：...（可能被占用）")`，别带着半清状态往下走。

### P2-2 `--dry` 仍要求完整工具链

`tools/drift-build.php:321` 的 `Environment::prepare($root)` 在 `434` 的 `--dry` 分支之前。
usage 说 `--dry` "只算输入清单与这次的决策"，但在没装 MSVC / tpc 的机器上会直接失败。
而 `--dry` 恰恰是最该在"环境有问题"时用的诊断。

修法：`--dry` 用 `prepare($root, withToolchain: false)`；缺失项降级为警告，仍打印决策。

### P2-3 `--print-runtime` 在编译之后

`tools/drift-dev.php` 的 704-714 是编译分支，729 才处理 `--print-runtime`。壳指纹一变，
"只想看三样路径"的命令会先跑 3 分钟编译。

修法：`--print-runtime` 在编译分支之前处理（它只需要 `$exe` 与 `$iniDir`，`runtimeIni()`
不依赖编译结果）。

### P2-4 孤儿 docblock

`tools/drift-dev.php:212-216` 是一段完整的 `/** 运行期 ini：... */`，紧跟着 217 行又开始
另一个 `/** 生成壳用的 ini ... */`，前者没有附着任何函数。读代码的人会以为有两层文档，
或以为第一个是 `runtimeIni` 的。

修法：删掉 212-216，把"关 opcache 的两个理由"并进 217 那段。

### P2-5 没有 `--print-command`

构建失败时最常见的第一个问题是"到底调了什么命令、用了哪些环境变量"。现在只能靠读代码反推。
建议 `--dry --print-command` 打印 argv 与关键 env（`PHPRC` / `PHPX_HOME` / `INCLUDE` 的
前几项），与 `doctor` 的输出互补。

---

## 4. P3 — 一致性与注释

- **重复的纯工具函数**：`win()` 在两个脚本各一份（`drift-build.php:53`、`drift-dev.php:256`）；
  `fail()` / 输出前缀也各一份。头注释说"有意不抽公共类"指的是工具链差异，但纯字符串函数
  没有差异可藏，抽到 `tools/Build/` 下没有争议。
- **应用名正则不一致**：build 是 `[A-Za-z0-9_-]+`，dev 是 `[A-Za-z0-9_]+`（少连字符），
  且 dev 还 `strtolower()` 了。同名应用在两个入口的行为可能不同。
- **`--full` 与 `--incremental` 同时给**：`StalePolicy` 里 `forceFull` 静默胜出，CLI 无提示。
  应打一行"`--full` 优先，`--incremental` 被忽略"。
- **`--jobs` 非法值静默忽略**：`--jobs abc` 直接不设，和没写一样。应报错。
- **`--prebuilt` + `--rebuild` 冲突**：prebuilt 分支先命中，rebuild 被忽略且无提示。
- **`drift-build.php:29` 的"约 20 行重复"**：需要重新核对，`drift-codegen` 现在是否还重复那么多；
  注释里的数字会过期，改成"有重复（见头注释）"更稳。

---

## 5. 与成熟工具对照，哪些值得抄

| 工具 | 做法 | 对应到本仓 |
|---|---|---|
| MSBuild | `/m:1` 串行、`/bl` 二进制日志、`/v:m` 流式 | 默认 `-j 1`；结构化日志可选 |
| Ninja | 每个 job 一行进度、`-d explain` 解释为什么重跑 | 心跳 + `--dry` 已经有 explain 雏形 |
| CMake/CTest | `--timeout` 单项超时 | `--stall-timeout` 停滞超时（比总超时合适） |
| Gradle | `--no-daemon`、守护进程空闲超时 | 构建进程不留后台残留 |
| Cargo | `CARGO_BUILD_JOBS=1` 可绕、错误流式 | `DRIFT_BUILD_JOBS` 环境变量 |
| VS / MSBuild | `taskkill /T /F /PID` 收尾进程树 | P0-2 的修法 |

**不建议做的**：不要引入 `pcntl`（Windows 没有）；不要做"自动无限重试"（把真 bug 藏起来）；
不要给 `drift-build` 加并行调度器 —— tpc 已经有一层，重写只会多一个真相源。

---

## 6. 最短可交付

按依赖排序，前两条就能把"老是卡"按住：

1. **棒 0（P0-3 + P0-1）**：实时透传 + 停滞看门狗 + `taskkill /T`。改动集中在
   `drift-build.php` 的 510-525 一段，判据可用假 tpc 构造。
2. **棒 1（P0-2）**：`drift-dev` 的 `passthru` 换 `proc_open`，`drift-build` 加
   `bypass_shell` 与 `_build/tpc.pid` 兜底。
3. **棒 2（P1-2 + P1-4 + P1-5）**：`--jobs` 解析、旧 exe 备份、`php.ini` 移出 git。
   P1-1（`/IM` 误杀）与 P1-3（默认并发）各自独立，可以并行。

不改上游 tpc：收尾死锁在 `D:\TypePHP\typephp`，本仓能做的是**检测、清理、给明确错误**，
不是替它修。这一点写进 `docs/guides/debugging.md` 或 PITFALLS 的"构建卡住"一节即可。

---

## 7. 处理结果（2026-11-28 当天落地）

`Supervisor`（`tools/Build/Supervisor.php`）+ 两个入口改造，P0/P1/P2 全做，P3 除"抽公共
字符串函数"外全做。

**过程中改了两个方案本身**，都是被判据逼出来的：

1. **输出不能走管道，必须走文件**。原方案只加看门狗，但第一次跑判据就发现：Windows 上
   `stream_set_blocking($pipe, false)` 返回 `false`，`fread` 阻塞到 EOF ⇒ 看门狗循环压根
   没在转（配 1s 也等满 30s）。所以改的不是"检测逻辑"，而是**整条读法**换成日志文件 +
   偏移增量。★ 这个坑 2026-10-23 就在 PITFALLS「起窗探针」里记过，做的时候没想起来。
2. **目录扫描间隔要跟着阈值缩放**。判据里 `stall=1s` + 扫描间隔 3s ⇒ 中间两秒必误判。
   现在 `max(0.3, min(3, stall/3))`。

另外补了一条评审没写、但实测必须有的：**清缓存要报进度**。本机对构建产物做实时扫描时，
单个 `.obj` 删除 **11s**、2797 个文件就是几小时 —— 没有输出时这与死锁完全一样。

| 条目 | 状态 | 落点 |
|---|---|---|
| P0-1 停滞看门狗 | ✅ | `Supervisor::run()`；`--stall-timeout` / `DRIFT_BUILD_STALL_TIMEOUT` |
| P0-2 孤儿清理 | ✅ | `_build/tpc.pid` + `reclaimStale()` + `register_shutdown_function`；`bypass_shell` |
| P0-3 输出可观测 | ✅ | 写 `_build/tpc.stdout.log`，偏移增量透传 + 20s 心跳 |
| P1-1 `/IM` 误杀 | ✅ | `devExePids()` 差集 → `taskkill /PID`；认不出才退回 `/IM` 并警告 |
| P1-2 `--jobs N` 解析 | ✅ | 统一选项表，位置参数单独收集；判据：两种顺序决策一致 |
| P1-3 默认并发 | ✅ | 默认 `-j 1`，`--jobs` / `DRIFT_BUILD_JOBS` 覆盖 |
| P1-4 旧产物先删 | ✅ | `.bak` 改名 → 成功删、失败/停滞还原 |
| P1-5 `php.ini` 入库 | ✅ | `git rm --cached` + `.gitignore`；`runtimeIni` 增加自拼兜底 |
| P2-1 清缓存不检查 | ✅ | 失败项收集 + `fail()`；另加 3s 进度 |
| P2-2 `--dry` 要工具链 | ✅ | `prepare($root, withToolchain: !$dry)`，缺失降级为警告 |
| P2-3 `--print-runtime` 位置 | ✅ | 提到编译分支之前 |
| P2-4 孤儿 docblock | ✅ | 合并进 `runtimeIni()` |
| P2-5 `--print-command` | ✅ | argv + PHPRC / PHP_HOME / PHPX_HOME / INCLUDE / LIB |
| P3 应用名正则 | ✅ | 两边统一 `[A-Za-z0-9_-]+` |
| P3 静默冲突提示 | ✅ | `--full`+`--incremental`、`--prebuilt`+`--rebuild` |
| P3 `--jobs abc` | ✅ | 报错，不再静默忽略 |
| P3 "约 20 行"注释 | ✅ | 改为"有重复（见头注释）" |
| P3 抽公共字符串函数 | ⏸ | `win()` 各一份，两处都只有一行；抽类的收益低于它带来的第三个文件 |

判据：`tools/build-supervise-check.php`（21 条，含正反例，含"pid 指向自己不许自杀"），
接入 `tests/Feature/Tools/BuildSuperviseCheckTest.php` 随 feature 套件跑。

**未解决**：`drift-dev` 的 `drain()` 仍读 dev.exe 的管道，同样受"非阻塞无效"影响 ——
窗口长时间不输出时 watcher 会卡在 `drain()` 里。改成文件会丢掉"管道 EOF = 已退出"这条
比 `proc_get_status()` 可靠的判据（AOT 进程 `running` 会误报），所以这次不动，
记在 PITFALLS「2026-11-28」段。

**本轮未能验证**：真机全量构建（`hello --full`）。本机当前对构建产物的删除操作要 11s/文件，
清缓存阶段按小时计，与脚本无关（`time rm -f` 直接可复现）。改用假 tpc + `--incremental`
端到端验了四条：静默⇒2s 判死且无残留、持续输出⇒不误判且进度实时上屏、失败⇒旧产物还原、
成功⇒产物与运行时 ini 分发。
