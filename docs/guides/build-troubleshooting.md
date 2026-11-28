# 构建卡住时怎么判断、怎么收拾

> 本篇解决：`drift-build` / `drift-dev` 看起来不动时，区分「真卡」「只是慢」「上一轮的孤儿在挡路」，以及为什么**不要**顺手 kill。

## 三条互不相干的假死来源

| 症状 | 真相 | 当前处置 |
|---|---|---|
| 构建中日志长时间零输出 | Windows 匿名管道**设不了非阻塞**（`stream_set_blocking()` 返回 `false`），读管道会阻塞到进程退出 | 输出已改为写 `_build/tpc.stdout.log`，脚本按偏移增量透传，终端能看到进度 |
| 十几分钟没动静、`cl.exe` 已退、只剩一个 `php.exe` 涨到 300MB+ | 上游 tpc 的收尾死锁（源码树在 `../typephp`，本仓不替它修） | 停滞看门狗：默认 300s 无输出**且**产物目录无更新 ⇒ `taskkill /T /F` 清整棵树，报明确错误 |
| 卡了一次之后次次卡，CPU 时间在涨、产物不更新 | 上一次被工具层 SIGTERM 截断，`tpc.php` 成孤儿，握着 MSVC 的 PDB 锁 | 每次构建前读 `_build/tpc.pid` 清残留；正常退出 / `exit` / fatal 都会清进程树 |
| 构建 **0.2 秒**就失败，报 `Unable to start curl module` | 宿主注入的变量把环境块顶过 Windows 32767 字节上限（`CODEBUDDY_MCP_CONFIG` 一条就 15KB），`CreateProcess` 截断后 PHP 的 curl 扩展初始化失败 | `Environment::prepare()` 按预算裁剪并打印裁掉了哪些项，见下节 |

## 看门狗的口径

判据是「**最后一次进展**距现在多久」，不是「开工距现在多久」——真全量正常就要 200s+，
总超时必然误杀正常构建。

进展有两个来源，两者同时静默才判死：

1. `tpc.stdout.log` / `tpc.stderr.log` 出现新字节；
2. `build/` 或 `apps/<app>/build/` 下文件数 / mtime 有变化（收尾阶段 tpc 不打印、只落盘）。

阈值：`--stall-timeout=秒` 或 `DRIFT_BUILD_STALL_TIMEOUT`（默认 300，`0` = 关掉）。

处于「有输出」状态时，每 20s 若静默超过 5s 会打一行心跳：

```
[drift-build] … 仍在编译（已 95s，最后进展 3s 前）
```

看到这行说明进程活着、只是这一会儿没说话，**不要动手**。

## 判定为停滞时会看到什么

```
[drift-build] ✗ tpc 停滞 300s（无输出、产物无更新；已耗时 312s）—— 已清掉整棵进程树
[drift-build]   这是上游 tpc 的收尾死锁，不是本仓的编译错误。直接重跑：
[drift-build]   php tools/drift-build.php hello
[drift-build]   上一版产物：已还原
```

三类信息都必须给全：**判了什么死**、**这不是你的代码错了**、**旧产物还在不在**（构建前
旧 exe 会改名成 `<app>.exe.bak`，失败/停滞时还原，成功才删）。

## 为什么不建议手动 kill

`taskkill /PID <tpc>` 只杀父进程，它起的 `cl.exe` / `link.exe` 会留下。那些孙进程握着
PDB 句柄，下一次构建在链接阶段等锁——现场是「CPU 143s 不动、产物无更新」，看起来
和 tpc 死锁一模一样，于是越查越偏。

真需要手动干预时，按树杀：

```bash
taskkill /T /F /PID <pid>      # /T 是重点
```

然后确认干净（`php.exe` 里不该还有跑着的 tpc）：

```bash
tasklist /FI "IMAGENAME eq php.exe" /NH
```

## 环境块超限：构建 0.2 秒就失败

**症状**：`drift-build` 打完工程信息就退出，日志里只有

```
PHP Fatal error:  Unable to start curl module in Unknown on line 0
[drift-build] ✗ 构建失败（exit=-2，{0.2s}）
```

同一条命令换个干净 shell 跑却完全正常 —— 差别只在环境变量。

**原因**：Windows 每个进程的环境块上限 **32767 字节**，超了 `CreateProcess` 会截断。
截断后的环境交给 PHP，curl 扩展在 `MINIT` 阶段读到的值不完整，直接失败。
宿主 IDE / 智能体常注入大块 JSON（本机 `CODEBUDDY_MCP_CONFIG` 一条 15385 字节），
287 个变量合计 34559 字节 ⇒ 必崩。

**当前处置**：`Environment::prepare()` 在把 env 交给子进程前裁剪（`pruneEnv()`）：

1. 先整片去掉宿主前缀（`CODEBUDDY_*` / `WORKBUDDY_*`）—— 构建用不到；
2. 仍超预算再按体积从大到小剔，**但绝不碰** `PATH` / `SystemRoot` / `TEMP` / `PHPRC` /
   `INCLUDE` / `LIB` 等必需项（比较按大写，Windows 环境变量不区分大小写）。

裁剪结果会打一行：

```
[drift-build] 环境已裁剪 124 项（19383 字节，守 Windows 环境块上限）：CODEBUDDY_A2A_DISCOVERY_TTL_MS, …
```

看到这行是**正常**的，不用管；没看到说明环境本来就干净。

**判据**：`php tools/env-budget-check.php`（含反例：注入 20KB 假变量必须被剔掉，
以及用裁剪后的 env 真起一个 PHP 验证 curl 能加载）。

## 相关

- 停滞 / 杀树 / 孤儿的判据：`php tools/build-supervise-check.php`（21 条，含正反例）。
  它现在服务于 `drift-dev` 的壳编译；`drift-build` 走直连终端的极简路径，不带看门狗。
- 编译决策现在**全归 tpc**：`drift-build` 不再有输入指纹、SKIP、清缓存。想彻底重建：
  `--full`（透传 tpc 的 `--force`），或手动删 `build/` 与 `apps/<app>/build/`。
- 环境不满足（缺 MSVC / SDK / PHPX / Skia）时的诊断：`php tools/doctor.php --scope=build`；
  想看实际调用什么：`drift-build` 每次都会先打印 `argv` 与 `cwd`。
