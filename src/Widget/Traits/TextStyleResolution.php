<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Rendering\Color;
use Drift\Text\FontWeight;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;
use Drift\Widget\BuildContext;

/**
 * 文本样式解析（主题缓存实例 + 显式 size/bold/maxLines/ellipsize 的合并）。
 *
 * ★ 只有一处派生：`Text` 与 `LiveText` 共用。写成两份必漂移 —— 判等说相同、
 *   实际样式不同 ⇒ 复用出旧样式且不报错。
 *
 * @internal
 */
trait TextStyleResolution
{
    private function resolvedStyle(BuildContext $context, TextRole $role): TextStyle
    {
        $theme = $context->theme();

        // 默认路径按角色取主题缓存实例 ⇒ 相邻两帧同一个对象，盒判等走身份短路。
        $explicit = $this->explicitSize();
        $style = $explicit === null ? $theme->textStyle($role) : TextStyle::of($explicit);
        $style = $style
            ->withMaxLines($this->maxLinesValue())
            ->withEllipsize($this->ellipsizeValue());

        $bold = $this->explicitBold();
        if ($bold !== null) {
            $style = $style->withWeight($bold ? FontWeight::Bold : FontWeight::Regular);
        }

        return $style;
    }

    private function resolvedColor(BuildContext $context, TextRole $role): Color
    {
        return $context->theme()->textColor($role);
    }
}
