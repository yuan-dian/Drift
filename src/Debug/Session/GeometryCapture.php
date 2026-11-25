<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 几何采集档位。
 *
 * `changed` 是开发默认：只存签名变了的节点；`all` 给布局回归用；`off` 给常驻录制用。
 */
enum GeometryCapture: string
{
    case Off = 'off';
    case Changed = 'changed';
    case All = 'all';
}
