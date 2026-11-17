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
  --replace            allow overwriting a non-empty --out directory

Env: SKIA_WIN_VC, SKIA_WIN_SDK, SKIA_WIN_SDK_VERSION, SKIA_CLANG_WIN,
     SKIA_CLANG_WIN_VERSION, SKIA_WIN_TOOLCHAIN_VERSION, SKIA_PYTHON,
     SKIA_GN, SKIA_NINJA (optional overrides; empty means PATH lookup, then prebuilt)

Requires: git, python3. gn / ninja come from PATH when present, otherwise from
bin/fetch-gn and bin/fetch-ninja — the official prebuilts pinned by Skia itself.
EOF
}

rev=""
skia_dir="${SKIA_DIR:-$ROOT/.skia-src}"
out="$ROOT/cpp/skia"
jobs=""
replace=0

for arg in "$@"; do
  case "$arg" in
    --rev=*) rev="${arg#--rev=}" ;;
    --skia-dir=*) skia_dir="${arg#--skia-dir=}" ;;
    --out=*) out="${arg#--out=}" ;;
    --jobs=*) jobs="${arg#--jobs=}" ;;
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

# 工具链解析放到 checkout 之后：PATH 上没有 gn / ninja 时，用 Skia 自带的
# bin/fetch-gn、bin/fetch-ninja 拉官方预编译二进制。这两个脚本把产物放进
# third_party/gn、third_party/ninja —— 正是 depot_tools 包装期望的位置，版本由
# Skia 自己的 DEPS / 脚本钉住，比装 depot_tools 再 bootstrap CIPD 少一整层。

PY="${SKIA_PYTHON:-}"
if [[ -z "$PY" ]]; then
  if command -v python3 >/dev/null 2>&1; then PY=python3; else PY=python; fi
fi
command -v "$PY" >/dev/null 2>&1 || die "python not found (set SKIA_PYTHON)"

win_vc="${SKIA_WIN_VC:-C:/Program Files/Microsoft Visual Studio/2022/Enterprise/VC}"
win_sdk="${SKIA_WIN_SDK:-C:/Program Files/Windows Kits/10}"
win_sdk_version="${SKIA_WIN_SDK_VERSION:-10.0.22621.0}"
clang_win="${SKIA_CLANG_WIN:-C:/Program Files/LLVM}"
clang_win_version="${SKIA_CLANG_WIN_VERSION:-}"
win_toolchain_version="${SKIA_WIN_TOOLCHAIN_VERSION:-}"

printf 'Skia revision : %s\n' "$rev"
printf 'Checkout      : %s\n' "$skia_dir"
printf 'Output        : %s\n' "$out"
printf 'Toolchain     : %s / SDK %s / LLVM %s\n' "$win_vc" "$win_sdk_version" "$clang_win"

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
    command -v "$want" >/dev/null 2>&1 || die "$name '$want' is not runnable (unset the SKIA_* override to use a prebuilt one)"
    printf '%s' "$want"
    return
  fi
  if command -v "$name" >/dev/null 2>&1; then
    printf '%s' "$name"
    return
  fi
  printf 'fetching prebuilt %s pinned by Skia\n' "$name" >&2
  (cd "$skia_dir" && "$PY" "$script") >&2 || die "$script failed"
  if [[ -f "$pexe" ]]; then path="$pexe"; elif [[ -f "$pposix" ]]; then path="$pposix"; else die "$script did not produce $name"; fi
  chmod +x "$path" 2>/dev/null || true
  printf '%s' "$path"
}

GN="$(resolve_tool "${SKIA_GN:-}" gn bin/fetch-gn "$skia_dir/bin/gn.exe" "$skia_dir/bin/gn")"
NINJA="$(resolve_tool "${SKIA_NINJA:-}" ninja bin/fetch-ninja "$skia_dir/third_party/ninja/ninja.exe" "$skia_dir/third_party/ninja/ninja")"
"$GN" --version >/dev/null 2>&1 || die "$GN is not runnable"
"$NINJA" --version >/dev/null 2>&1 || die "$NINJA is not runnable"

(cd "$skia_dir" && "$PY" tools/git-sync-deps)

gn_args="$(cat "$HERE/args.gn.tpl")"
gn_args+=$'\ncc = "clang"'
gn_args+=$'\ncxx = "clang++"'
gn_args+=$'\nclang_win = "'"$clang_win"'"'
gn_args+=$'\nwin_vc = "'"$win_vc"'"'
gn_args+=$'\nwin_sdk = "'"$win_sdk"'"'
gn_args+=$'\nwin_sdk_version = "'"$win_sdk_version"'"'
if [[ -n "$clang_win_version" ]]; then
  gn_args+=$'\nclang_win_version = "'"$clang_win_version"'"'
fi
if [[ -n "$win_toolchain_version" ]]; then
  gn_args+=$'\nwin_toolchain_version = "'"$win_toolchain_version"'"'
fi

"$GN" gen "$skia_dir/out/Release-x64" --args="$gn_args" >/dev/null
if [[ -n "$jobs" ]]; then
  "$NINJA" -C "$skia_dir/out/Release-x64" -j "$jobs" skia modules
else
  "$NINJA" -C "$skia_dir/out/Release-x64" skia modules
fi

if [[ -d "$out" ]] && [[ -n "$(ls -A "$out" 2>/dev/null)" ]]; then
  [[ "$replace" -eq 1 ]] || die "$out is not empty; pass --replace to overwrite"
  rm -rf "$out"
fi
mkdir -p "$out/out/Release-x64"

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
for lib in "$skia_dir"/out/Release-x64/*.lib; do
  [[ -f "$lib" ]] || continue
  cp "$lib" "$out/out/Release-x64/"
  lib_count=$((lib_count + 1))
done
cp "$skia_dir/out/Release-x64/args.gn" "$out/out/Release-x64/args.gn"
[[ "$lib_count" -gt 0 ]] || die "ninja produced no .lib files"

printf 'Done: %d headers, %d libs -> %s\n' "$header_count" "$lib_count" "$out"
printf 'Next: php tools/pack-deps.php\n'
