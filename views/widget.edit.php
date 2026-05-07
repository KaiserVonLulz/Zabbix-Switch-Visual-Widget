<?php declare(strict_types=1);

$form = new CWidgetFormView($data);

$form->addField(new CWidgetFieldMultiSelectHostView($data['fields']['hostids']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['bw_in_pattern']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['bw_out_pattern']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['status_pattern']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['speed_pattern']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['err_in_pattern']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['err_out_pattern']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['alias_pattern']));
$form->addField(new CWidgetFieldIntegerBoxView($data['fields']['num_ports']));
$form->addField(new CWidgetFieldIntegerBoxView($data['fields']['num_sfp']));
$form->addField(new CWidgetFieldIntegerBoxView($data['fields']['port_rows']));
$form->addField(new CWidgetFieldIntegerBoxView($data['fields']['scale']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['port_aliases_manual']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['uptime_key']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['serial_key']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['model_key']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['cpu_key']));
$form->addField(new CWidgetFieldTextBoxView($data['fields']['temperature_key']));

$form->show();
