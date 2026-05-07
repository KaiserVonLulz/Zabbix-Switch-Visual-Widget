<?php declare(strict_types=1);

namespace Modules\SwitchVisual\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectHost;
use Zabbix\Widgets\Fields\CWidgetFieldIntegerBox;
use Zabbix\Widgets\Fields\CWidgetFieldTextBox;

class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        $this->addField(
            (new CWidgetFieldMultiSelectHost('hostids', _('Host')))->setMultiple(false)
        );

        $this->addField(
            (new CWidgetFieldTextBox('bw_in_pattern', _('BW In item pattern')))->setDefault('ifInOctets[*]')
        );
        $this->addField(
            (new CWidgetFieldTextBox('bw_out_pattern', _('BW Out item pattern')))->setDefault('ifOutOctets[*]')
        );
        $this->addField(
            (new CWidgetFieldTextBox('status_pattern', _('Status item pattern')))->setDefault('ifOperStatus[*]')
        );
        $this->addField(
            (new CWidgetFieldTextBox('speed_pattern', _('Speed item pattern')))->setDefault('ifHighSpeed[*]')
        );
        $this->addField(
            (new CWidgetFieldTextBox('err_in_pattern', _('Errors In item pattern')))->setDefault('ifInErrors[*]')
        );
        $this->addField(
            (new CWidgetFieldTextBox('err_out_pattern', _('Errors Out item pattern')))->setDefault('ifOutErrors[*]')
        );

        $this->addField(
            (new CWidgetFieldTextBox('alias_pattern', _('Interface alias pattern (optional)')))->setDefault('')
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('num_ports', _('RJ45 ports'), 1, 48))->setDefault(24)
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('num_sfp', _('SFP ports'), 0, 8))->setDefault(2)
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('port_rows', _('Port rows (1 or 2)'), 1, 2))->setDefault(2)
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('scale', _('Zoom (50–300%)'), 50, 300))->setDefault(100)
        );
        $this->addField(
            (new CWidgetFieldTextBox('port_aliases_manual', _('Port aliases (e.g. 1=Uplink, 3=ESX01)')))->setDefault('')
        );

        $this->addField(
            (new CWidgetFieldTextBox('uptime_key', _('Uptime item key (optional)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('serial_key', _('Serial number item key (optional)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('model_key', _('Model item key (optional)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('cpu_key', _('CPU % item key (optional)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('temperature_key', _('Temperature item key (optional)')))->setDefault('')
        );

        return $this;
    }
}
