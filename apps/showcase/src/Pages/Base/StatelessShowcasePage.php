<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Base;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\StatelessWidget;

/** 无状态页面：统一接收能力束，页面本身只写 build()。 */
abstract class StatelessShowcasePage extends StatelessWidget
{
    public function __construct(protected ShowcaseCapabilities $caps)
    {
        parent::__construct();
    }
}
