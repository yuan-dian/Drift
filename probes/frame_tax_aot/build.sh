#!/usr/bin/env bash
# 第 2 道门的构建（tpc `-m bin`）。
#
# ★ 三件套的约定与 `probes/fiber_scope/build.sh` 逐条相同：
#   PHP 不认 MSYS 的 /d/... 路径 ⇒ 交给 PHP 的一律 cygpath -m；
#   MSVC 的 INCLUDE/LIB 给分号+反斜杠（给 cl.exe），PATH 给冒号（给 Git Bash）。
#
# ★ 与 fiber_scope 的唯一差别是 `sources` 多引了 `../../tools`
#   （`Drift\Dev\` 的映射），因为要编译 `FrameTaxMeasure` / `SurfaceProbe`。
#   ⇒ 构建比 fiber_scope 慢一些（多几十个翻译单元），但仍不进 gate。
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
HERE_W="$(cygpath -m "$HERE")"
ROOT_W="$(cygpath -m "$ROOT")"
PHP="${PHP:-D:/TypePHP/typephp-windows-x64/php.exe}"
TPC="${TPC:-D:/TypePHP/typephp/bin/tpc.php}"
MSVC='D://Microsoft Visual Studio\18\Community\VC\Tools\MSVC\14.51.36231'
KITS='D://Windows Kits\10'
SDK='10.0.26100.0'
export PHP_HOME="D:/TypePHP/typephp-windows-x64"
export PHPX_HOME="D:/TypePHP/typephp-windows-x64/phpx"
export PHPRC="$ROOT_W/_build/ini"
export INCLUDE="$MSVC\\include;$KITS\\Include\\$SDK\\ucrt;$KITS\\Include\\$SDK\\shared;$KITS\\Include\\$SDK\\um"
export LIB="$MSVC\\lib\\x64;$KITS\\Lib\\$SDK\\ucrt\\x64;$KITS\\Lib\\$SDK\\um\\x64"
export PATH="$(cygpath -u "$MSVC")/bin/Hostx64/x64:$(cygpath -u "$KITS")/bin/$SDK/x64:$PATH"

cd "$ROOT" || exit 1
"$PHP" "$TPC" --project "$HERE_W/project.yml" -O2 -m bin || exit $?

# ★ 构建成功**立刻**验几何等价性 —— 性能数字建立在"算对了"之上。
#   `DEBT` §36.8 的话：慢 2× 可接受，算错尺寸不可用。既然顺序如此，
#   就没有理由让一个"没验过算得对不对"的产物存在超过 3 秒。
#   ★ 它只跑 2.5s，而构建是 50s —— 占比 5%，没有省的理由。
#   ★ `SKIP_EQUIV=1` 可跳过（只想要二进制、或机器上没装 PHP 的场合）。
if [ "${SKIP_EQUIV:-0}" != "1" ]; then
    echo ""
    echo "── 几何等价性（AOT vs 解释器）──"
    "$PHP" "$ROOT_W/tools/aot-equivalence.php" --exe="$ROOT_W/frame_tax_aot_probe.exe" || exit $?
fi
