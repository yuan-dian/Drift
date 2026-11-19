#!/usr/bin/env bash
# Rebuild cpp/skia (headers + static libs) from a pinned Skia commit.
# Fonts, licenses, packing and publishing belong to pack-deps.php / fetch-deps.php.
# Machine-specific toolchain paths stay out of the repo: pass them via SKIA_*.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"

die() { printf 'error: %s\n' "$*" >&2; exit 1; }

usage() {
  cat <<'EOF'
Usage: bash tools/skia/build-skia.sh [options]

  --rev=<40-hex>       Skia commit; defaults to SKIA_REVISION (rejects "unpinned")
  --skia-dir=<path>    Skia checkout (default: <repo>/.skia-src)
  --out=<path>         output tree (default: <repo>/cpp/skia)
  --jobs=<n>           ninja parallelism
  --platform=<name>    'windows' (MSVC/SDK/.lib) or 'linux' (clang/.a);
                       default: auto-detect from `uname -s`
  --dry-run            print platform decision + final gn args, do NOT clone/sync/build
  --replace            allow overwriting a non-empty --out directory

Env (windows only): SKIA_WIN_VC, SKIA_WIN_SDK, SKIA_WIN_SDK_VERSION,
  SKIA_CLANG_WIN, SKIA_CLANG_WIN_VERSION, SKIA_WIN_TOOLCHAIN_VERSION.
Env (both): SKIA_PYTHON, SKIA_GN, SKIA_NINJA (empty means PATH, then prebuilt).

Requires: git, python3. gn / ninja come from PATH when present, otherwise from
bin/fetch-gn and bin/fetch-ninja — the official prebuilts pinned by Skia itself.
EOF
}

rev=""
skia_dir="${SKIA_DIR:-$ROOT/.skia-src}"
out="$ROOT/cpp/skia"
jobs=""
platform=""
dry_run=0
replace=0

for arg in "$@"; do
  case "$arg" in
    --rev=*) rev="${arg#--rev=}" ;;
    --skia-dir=*) skia_dir="${arg#--skia-dir=}" ;;
    --out=*) out="${arg#--out=}" ;;
    --jobs=*) jobs="${arg#--jobs=}" ;;
    --platform=*) platform="${arg#--platform=}" ;;
    --dry-run) dry_run=1 ;;
    --replace) replace=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $arg" ;;
  esac
done

if [[ -z "$rev" ]]; then
  [[ -f "$ROOT/SKIA_REVISION" ]] || die "SKIA_REVISION is missing"
  rev="$(tr -d '\r\n' < "$ROOT/SKIA_REVISION")"
fi
[[ "$rev" =~ ^[0-9a-f]{40}$ ]] || die "expected a 40-hex Skia commit, got '$rev' (unpinned means the legacy package has no traceable source)"

# ── 平台决策 ──────────────────────────────────────────────────────────────
# ★ 显式 --platform 优先，否则 uname 自动识别。Git Bash/MSYS 也算 Windows。
#   这一步必须最早完成：后面 gn args、库后缀、工具链解析全都依赖它。
platform_source="auto (uname -s)"
if [[ -n "$platform" ]]; then
  platform_source="--platform=$platform"
else
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*|Windows_NT*) platform="windows" ;;
    Linux) platform="linux" ;;
    *) die "unsupported host '$(uname -s)'; pass --platform=windows|linux explicitly" ;;
  esac
fi
[[ "$platform" == "windows" || "$platform" == "linux" ]] \
  || die "unknown platform '$platform' (expected windows|linux)"

# gn 的输出目录名与平台无关（只是源码树里的一个子目录），两平台共用同一个
# out/Release-x64；真正分开的是 --out 目标目录（调用方给）与库后缀。
out_dir="out/Release-x64"
if [[ "$platform" == "windows" ]]; then
  lib_ext="lib"
else
  lib_ext="a"
fi

PY="${SKIA_PYTHON:-}"
if [[ -z "$PY" ]]; then
  if command -v python3 >/dev/null 2>&1; then PY=python3; else PY=python; fi
fi
command -v "$PY" >/dev/null 2>&1 || die "python not found (set SKIA_PYTHON)"

# Windows 工具链路径只服务 Windows 分支；Linux 下不解析、不进 gn args。
win_vc="${SKIA_WIN_VC:-C:/Program Files/Microsoft Visual Studio/2022/Enterprise/VC}"
win_sdk="${SKIA_WIN_SDK:-C:/Program Files/Windows Kits/10}"
win_sdk_version="${SKIA_WIN_SDK_VERSION:-10.0.22621.0}"
clang_win="${SKIA_CLANG_WIN:-C:/Program Files/LLVM}"
clang_win_version="${SKIA_CLANG_WIN_VERSION:-}"
win_toolchain_version="${SKIA_WIN_TOOLCHAIN_VERSION:-}"

# ── gn args 构造（唯一一处；dry-run 与真构建共用同一函数）────────────────
# ★ args.gn.tpl 是平台无关策略 + Windows 的 -MT；Linux 下必须删掉 -MT：
#   -MT 在 MSVC 是"静态 CRT"，在 clang 却是"给依赖文件指定 target"，缺参数直接报错。
#   不在 tpl 里做条件（那会让 Windows 产物变化），而是在读取后按平台过滤。
build_gn_args() {
  local args
  args="$(cat "$HERE/args.gn.tpl")"

  # ★ 两岸共用的 10 个安全裁剪开关（deps-v2 裁决）：Drift 只做 core 软件光栅 +
  #   图片解码，链接清单（build/base.yml）明确不含 pdf / skottie / svg / sksg /
  #   skparagraph / skshaper / skunicode / icu / perfetto / piex / dng_sdk。
  #   Ganesh 也在其中：drift_skia.cc 唯一画布是 SkCanvas::MakeRasterDirectN32
  #   软件光栅，全仓零 GrDirectContext / GL / D3D 上下文调用，Windows 编
  #   src/gpu/ganesh/gl/ 属于白编；关掉后 Skia 官方 gn 自动把 skia_use_gl 置 false。
  #   Linux 已实测（Ganesh 单独关 2008→1731，叠加 9 开关 1731→907 目标、
  #   26→13 库、44.3→9.7MB），raster / codec / freetype 全部不受影响。
  #   ★ harfbuzz / expat / jsonreader 不在此列：Windows 链接清单仍用它们（文本栈/SVG）。
  for flag in \
    'skia_enable_pdf = false' \
    'skia_enable_skottie = false' \
    'skia_enable_svg = false' \
    'skia_enable_skparagraph = false' \
    'skia_enable_skshaper = false' \
    'skia_use_icu = false' \
    'skia_use_perfetto = false' \
    'skia_use_piex = false' \
    'skia_use_dng_sdk = false' \
    'skia_enable_ganesh = false'; do
    args+=$'\n'"$flag"
  done
  if [[ "$platform" == "linux" ]]; then
    args="$(printf '%s\n' "$args" | sed '/extra_cflags = \[ "-MT" \]/d')"
  else
    args+=$'\ncc = "clang"'
    args+=$'\ncxx = "clang++"'
    args+=$'\nclang_win = "'"$clang_win"'"'
    args+=$'\nwin_vc = "'"$win_vc"'"'
    args+=$'\nwin_sdk = "'"$win_sdk"'"'
    args+=$'\nwin_sdk_version = "'"$win_sdk_version"'"'
    if [[ -n "$clang_win_version" ]]; then
      args+=$'\nclang_win_version = "'"$clang_win_version"'"'
    fi
    if [[ -n "$win_toolchain_version" ]]; then
      args+=$'\nwin_toolchain_version = "'"$win_toolchain_version"'"'
    fi
  fi
  printf '%s' "$args"
}

gn_args="$(build_gn_args)"

printf 'Skia revision : %s\n' "$rev"
printf 'Platform      : %s (%s)\n' "$platform" "$platform_source"
printf 'Checkout      : %s\n' "$skia_dir"
printf 'Output        : %s (libs: *.%s)\n' "$out" "$lib_ext"
if [[ "$platform" == "windows" ]]; then
  printf 'Toolchain     : %s / SDK %s / LLVM %s\n' "$win_vc" "$win_sdk_version" "$clang_win"
else
  printf 'Toolchain     : clang (Skia default) / system libc\n'
fi

# ★ dry-run 放在任何网络动作之前：只验证平台决策与 gn args，不 clone、不
#   git-sync-deps、不取 gn/ninja。本地无 Skia 源码也能检查分叉是否生效。
if [[ "$dry_run" -eq 1 ]]; then
  printf '==> DRY-RUN: would clone/fetch %s @ %s\n' "$skia_dir" "$rev"
  printf '==> DRY-RUN: would git-sync-deps\n'
  printf '==> DRY-RUN (cd %s && gn gen %s --args=<below>)\n' "$skia_dir" "$out_dir"
  printf '%s\n' "$gn_args"
  printf '==> DRY-RUN: would ninja -C %s skia modules, copy headers + *.%s\n' "$out_dir" "$lib_ext"
  exit 0
fi

if [[ ! -d "$skia_dir/.git" ]]; then
  git clone --no-checkout https://skia.googlesource.com/skia "$skia_dir"
fi
git -C "$skia_dir" fetch origin --quiet
git -C "$skia_dir" checkout --detach "$rev" --quiet

# gn / ninja：SKIA_GN / SKIA_NINJA 显式给了就用；否则 PATH 上找；再没有才下载。
# 先问一次版本，把「工具链不可用」暴露在 git-sync-deps 之前——那一步要几百行输出，
# 混在里面看着像编译错。
resolve_tool() { # <env-value> <name> <fetch-script> <windows-path> <posix-path>
  local want="$1" name="$2" script="$3" pexe="$4" pposix="$5" path
  if [[ -n "$want" ]]; then
    command -v "$want" >/dev/null 2>&1 || die "$name '$want' is not runnable (unset the SKIA_* override to use the pinned prebuilt)"
    printf '%s' "$want"
    return
  fi
  # 只认 Skia 自己钉住的版本，不再回落到 PATH：托管镜像自带的 ninja 版本不可控，
  # 本地有 depot_tools 时又会悄悄换成另一套，构建产物就不一致了。
  if [[ -f "$pexe" ]]; then
    path="$pexe"
  elif [[ -f "$pposix" ]]; then
    path="$pposix"
  else
    printf 'fetching prebuilt %s pinned by Skia\n' "$name" >&2
    (cd "$skia_dir" && "$PY" "$script") >&2 || die "$script failed"
    if [[ -f "$pexe" ]]; then path="$pexe"; elif [[ -f "$pposix" ]]; then path="$pposix"; else die "$script did not produce $name"; fi
  fi
  chmod +x "$path" 2>/dev/null || true
  printf '%s' "$path"
}

GN="$(resolve_tool "${SKIA_GN:-}" gn bin/fetch-gn "$skia_dir/bin/gn.exe" "$skia_dir/bin/gn")"
NINJA="$(resolve_tool "${SKIA_NINJA:-}" ninja bin/fetch-ninja "$skia_dir/third_party/ninja/ninja.exe" "$skia_dir/third_party/ninja/ninja")"
"$GN" --version >/dev/null 2>&1 || die "$GN is not runnable"
"$NINJA" --version >/dev/null 2>&1 || die "$NINJA is not runnable"

printf '==> syncing Skia deps\n'
# emsdk 只服务 wasm 目标，这里编的是原生 x64 静态库；跳过它能省一次 500 MB 的
# 工具链下载（还会往日志里灌一堆 MSYSTEM 警告）。
export GIT_SYNC_DEPS_SKIP_EMSDK=1
(cd "$skia_dir" && "$PY" tools/git-sync-deps) || die "git-sync-deps failed (see the last dep it printed)"

# ★ 必须在 skia_dir 里跑：gn 靠「当前目录或任一父目录有 .gn」确定 source root，
#   从仓库根调用会直接 "Can't find source root."，跟参数对不对无关。
#   Skia 官方 CI 也是这么做的 —— infra/bots/recipe_modules/build/default.py 的
#   compile_fn 里 `with api.context(cwd=skia_dir)` 包住 gn gen 与 ninja。
#   不要吞 gn 的输出：参数不对（比如 SDK 版本写成 wdf）时报错全在这里，
#   吞掉之后剩下的只有一个无信息的 exit 1。
printf '==> gn gen\n'
(cd "$skia_dir" && "$GN" gen "$out_dir" --args="$gn_args") || die "gn gen failed"
printf '==> ninja (%s)\n' "$NINJA"
if [[ -n "$jobs" ]]; then
  (cd "$skia_dir" && "$NINJA" -C "$out_dir" -j "$jobs" skia modules) || die "ninja failed"
else
  (cd "$skia_dir" && "$NINJA" -C "$out_dir" skia modules) || die "ninja failed"
fi

if [[ -d "$out" ]] && [[ -n "$(ls -A "$out" 2>/dev/null)" ]]; then
  [[ "$replace" -eq 1 ]] || die "$out is not empty; pass --replace to overwrite"
  rm -rf "$out"
fi
mkdir -p "$out/$out_dir"

# headers.txt pins the exact header set shipped in the legacy package, so a CI
# rebuild produces the same layout instead of dragging in the whole Skia tree.
header_count=0
while IFS= read -r rel; do
  [[ -z "$rel" ]] && continue
  rel="${rel%$'\r'}"
  src="$skia_dir/$rel"
  [[ -f "$src" ]] || die "$rel is missing at $rev; refresh tools/skia/headers.txt for this Skia revision"
  mkdir -p "$(dirname "$out/$rel")"
  cp "$src" "$out/$rel"
  header_count=$((header_count + 1))
done < "$HERE/headers.txt"

lib_count=0
for lib in "$skia_dir"/"$out_dir"/*."$lib_ext"; do
  [[ -f "$lib" ]] || continue
  cp "$lib" "$out/$out_dir/"
  lib_count=$((lib_count + 1))
done
cp "$skia_dir/$out_dir/args.gn" "$out/$out_dir/args.gn"
[[ "$lib_count" -gt 0 ]] || die "ninja produced no .$lib_ext files"

printf 'Done: %d headers, %d libs (*.%s) -> %s\n' "$header_count" "$lib_count" "$lib_ext" "$out"
printf 'Next: php tools/pack-deps.php\n'
