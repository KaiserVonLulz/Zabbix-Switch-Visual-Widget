<?php declare(strict_types=1);

namespace Modules\SwitchVisual\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldCheckBox;
use Zabbix\Widgets\Fields\CWidgetFieldColor;
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
            (new CWidgetFieldCheckBox('bw_bits', _('Bandwidth items deliver bits/sec (not bytes/sec)')))->setDefault(1)
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
            (new CWidgetFieldTextBox('poe_pattern', _('PoE status pattern (optional, e.g. pethPsePortDetectionStatus[*])')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('poe_pwr_pattern', _('PoE port power pattern (optional, e.g. pethPsePortPowerConsumption[*], milliwatts)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('duplex_pattern', _('Duplex mode pattern (optional, e.g. dot3StatsDuplexStatus[*])')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('sfp_rx_pwr_pattern', _('SFP optical RX power pattern (optional)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('sfp_tx_pwr_pattern', _('SFP optical TX power pattern (optional)')))->setDefault('')
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
            (new CWidgetFieldCheckBox('port_inverted', _('Invert row order (even ports top — e.g. Huawei)')))->setDefault(0)
        );
        $this->addField(
            (new CWidgetFieldCheckBox('port_sequential', _('Sequential rows (first half top, second half bottom — e.g. 1–24 / 25–48)')))->setDefault(0)
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('scale', _('Zoom (50–300%)'), 50, 300))->setDefault(100)
        );
        $this->addField(
            (new CWidgetFieldTextBox('port_aliases_manual', _('Port aliases (e.g. 1=Uplink, 3=ESX01, 4-6=VLAN1)')))->setDefault('')
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
        $this->addField(
            (new CWidgetFieldTextBox('poe_total_key', _('Total PoE consumed item key (optional, e.g. pethMainPseConsumptionPower.1, watts)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('poe_max_key', _('Max PoE capacity item key (optional, e.g. pethMainPsePower.1, watts)')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldTextBox('fan_pattern', _('Fan status pattern (optional, e.g. hpicfFanState[*])')))->setDefault('')
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('fan_ok_value', _('Fan OK value (3=HP good, 1=some vendors — check MIB)'), 1, 255))->setDefault(3)
        );

        $this->addField(
            (new CWidgetFieldColor('chassis_color', _('Chassis color')))->setDefault('404c58')
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('port_index_start', _('Port index start (SNMP offset)'), 1, 999999))->setDefault(1)
        );
        $this->addField(
            (new CWidgetFieldCheckBox('auto_detect_ports', _('Auto-detect port count (may include VLANs/tunnels)')))->setDefault(0)
        );
        $this->addField(
            (new CWidgetFieldCheckBox('show_summary', _('Show summary bar')))->setDefault(1)
        );
        $this->addField(
            (new CWidgetFieldCheckBox('show_port_numbers', _('Show port numbers')))->setDefault(1)
        );
        $this->addField(
            (new CWidgetFieldCheckBox('show_port_labels', _('Show port labels / aliases')))->setDefault(1)
        );
        $this->addField(
            (new CWidgetFieldCheckBox('show_sparkline', _('Show global traffic sparkline')))->setDefault(1)
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('sparkline_minutes', _('Sparkline window (minutes)'), 5, 360))->setDefault(30)
        );
        $this->addField(
            (new CWidgetFieldIntegerBox('util_threshold', _('Utilization warning threshold (%)'), 1, 100))->setDefault(80)
        );

        return $this;
    }
}
