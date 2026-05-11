<?php declare(strict_types=1);

namespace Modules\SwitchVisual;

use Zabbix\Core\CWidget;

class Widget extends CWidget {
    public function getDefaultName(): string {
        return _('Switch Visual Panel');
    }
}
