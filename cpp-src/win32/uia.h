/**
 * Win32 无障碍（UIA）—— provider / pattern / 语义树事件。
 * 数据由 `php_drift_win_uia_push()` 推进来（`WindowSlot::uiaNodes`），本模块只应答
 * UIA 客户端。依赖单向：主体调这里，这里不回调主体（DPI 靠 `DriftDpiFn` 注入）。
 */
#pragma once

#include <windows.h>
#include <string>
#include <vector>

#include "window_slot.h"

/** DPI 现查（主体的 `dpiOfWindow` 用 LoadLibraryW 兼容老系统，故注入而不复制） */
using DriftDpiFn = UINT (*)(HWND);

/** 建窗时挂 provider；`slot.uiaProvider` 由这里写 */
void driftUiaAttach(WindowSlot &slot, HWND hwnd, DriftDpiFn dpiFn);

/** 销毁窗口时释放 provider 与树（槽本身保留：退出路径还要读最后几何） */
void driftUiaDetach(WindowSlot &slot);

/**
 * `WM_GETOBJECT` 的落地。处理了返回 true 并写 `*result`，否则 false（交回系统）。
 * 封在模块里是为了让主体看不见 `CProvider` / `IRawElementProviderSimple`。
 */
bool driftUiaHandleGetObject(WindowSlot &slot, HWND hwnd, WPARAM wp, LPARAM lp, LRESULT *result);

/** 换一整棵语义树，并按差异 raise 结构 / 焦点 / 选中事件 */
void driftUiaApply(WindowSlot &slot, const std::vector<UiaNode> &nodes);

/** 语义角色 ⇒ UIA ControlTypeId（PHP 侧不持平台常量） */
long driftUiaControlTypeFor(const std::string &role);
