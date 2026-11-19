#ifndef DRIFT_X11_SYSTEM_APPEARANCE_H
#define DRIFT_X11_SYSTEM_APPEARANCE_H

#include <chrono>

namespace drift {
namespace x11 {

/**
 * X11 系统外观快照。
 *
 * ★ 不直接照搬 Win32 的 GetSysColor()：X11 没有"系统色"统一 API。这里只回答
 *   两个能跨平台兑现的问题——亮/暗与高对比度；具体色值交给 ThemeData 的
 *   highContrast 兜底配色，不伪造一套用户没选过的颜色。
 */
struct SystemAppearanceSnapshot
{
    bool dark = false;
    bool highContrast = false;
};

/** 读当前快照（XSettings → 环境变量兜底）；纯读，不改全局轮询状态。 */
SystemAppearanceSnapshot readSystemAppearance();

/**
 * 轮询快照是否在两次调用之间变化。
 *
 * ★ 节流到 250ms：XGetSelectionOwner + XGetWindowProperty 是一次 X 往返，
 *   EventLoop 每帧都问会浪费；桌面主题切换是秒级事件，250ms 足够跟手。
 *   首次调用只建立基线，不报变化，避免启动即抖一帧。
 */
bool pollSystemAppearanceChanged();

/** 最近一次已发布快照；轮询尚未发生时按需真读一次。 */
SystemAppearanceSnapshot currentSystemAppearance();

}   // namespace x11
}   // namespace drift

#endif
