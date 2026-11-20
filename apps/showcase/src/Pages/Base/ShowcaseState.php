<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Base;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\State;

/** 示例台页面状态：统一持有能力束；State 基类没有构造器，不能调 parent。 */
abstract class ShowcaseState extends State
{
    public function __construct(protected ShowcaseCapabilities $caps)
    {
    }
}
