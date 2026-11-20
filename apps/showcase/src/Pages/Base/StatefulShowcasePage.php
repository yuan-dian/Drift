<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Base;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\StatefulWidget;

/** 有状态页面：统一接收能力束，createState() 再原样递下去。 */
abstract class StatefulShowcasePage extends StatefulWidget
{
    public function __construct(protected ShowcaseCapabilities $caps)
    {
        parent::__construct();
    }
}
