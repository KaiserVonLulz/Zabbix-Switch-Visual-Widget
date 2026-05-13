<?php declare(strict_types=1);

$form = new CWidgetFormView($data);

$form->addField(new CWidgetFieldMultiSelectHostView($data['fields']['hostids']));

// ── PORTS (expanded by default) ──────────────────────────────────────────────
$form->addFieldset(
    (new CWidgetFormFieldsetCollapsibleView(_('Ports')))
        ->setExpanded(true)
        ->addField(new CWidgetFieldTextBoxView($data['fields']['bw_in_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['bw_out_pattern']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['bw_bits']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['status_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['speed_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['err_in_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['err_out_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['alias_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['duplex_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['sfp_rx_pwr_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['sfp_tx_pwr_pattern']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['num_ports']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['num_sfp']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['port_rows']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['port_inverted']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['port_sequential']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['scale']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['port_index_start']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['auto_detect_ports']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['port_aliases_manual']))
);

// ── POE (collapsed) ──────────────────────────────────────────────────────────
$form->addFieldset(
    (new CWidgetFormFieldsetCollapsibleView(_('PoE')))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['poe_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['poe_pwr_pattern']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['poe_total_key']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['poe_max_key']))
);

// ── SYSTEM (collapsed) ───────────────────────────────────────────────────────
$form->addFieldset(
    (new CWidgetFormFieldsetCollapsibleView(_('System')))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['uptime_key']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['serial_key']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['model_key']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['cpu_key']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['temperature_key']))
        ->addField(new CWidgetFieldTextBoxView($data['fields']['fan_pattern']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['fan_ok_value']))
);

// ── APPEARANCE (collapsed) ───────────────────────────────────────────────────
$form->addFieldset(
    (new CWidgetFormFieldsetCollapsibleView(_('Appearance')))
        ->addField(new CWidgetFieldColorView($data['fields']['chassis_color']))
);

// ── DISPLAY (collapsed) ──────────────────────────────────────────────────────
$form->addFieldset(
    (new CWidgetFormFieldsetCollapsibleView(_('Display')))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_summary']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_port_numbers']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_port_labels']))
        ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_sparkline']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['sparkline_minutes']))
        ->addField(new CWidgetFieldIntegerBoxView($data['fields']['util_threshold']))
);

$form->show();
