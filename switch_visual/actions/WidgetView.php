<?php declare(strict_types=1);

namespace Modules\SwitchVisual\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        try {
            $f      = $this->fields_values;
            $hostid = $this->extractHostId($f['hostids'] ?? []);

            $ports            = [];
            $summary          = ['ip' => '', 'uptime' => '', 'serial' => '', 'model' => '', 'cpu' => '', 'temperature' => ''];
            $port_aliases     = [];
            $global_sparkline = '';
            $global_peak_rx   = '';
            $global_peak_tx   = '';
            $error            = null;

            if ($hostid !== '') {
                require_once __DIR__ . '/../includes/DataFetcher.php';
                $fetcher = new \Modules\SwitchVisual\Includes\DataFetcher([
                    'hostid'          => $hostid,
                    'num_ports'       => (int)    ($f['num_ports']       ?? 24),
                    'num_sfp'         => (int)    ($f['num_sfp']         ?? 2),
                    'bw_in_pattern'   => trim((string) ($f['bw_in_pattern']   ?? 'ifInOctets[*]')),
                    'bw_out_pattern'  => trim((string) ($f['bw_out_pattern']  ?? 'ifOutOctets[*]')),
                    'status_pattern'  => trim((string) ($f['status_pattern']  ?? 'ifOperStatus[*]')),
                    'speed_pattern'   => trim((string) ($f['speed_pattern']   ?? 'ifHighSpeed[*]')),
                    'alias_pattern'   => trim((string) ($f['alias_pattern']   ?? '')),
                    'err_in_pattern'  => trim((string) ($f['err_in_pattern']  ?? 'ifInErrors[*]')),
                    'err_out_pattern' => trim((string) ($f['err_out_pattern'] ?? 'ifOutErrors[*]')),
                    'uptime_key'      => trim((string) ($f['uptime_key']      ?? '')),
                    'serial_key'      => trim((string) ($f['serial_key']      ?? '')),
                    'model_key'       => trim((string) ($f['model_key']       ?? '')),
                    'cpu_key'         => trim((string) ($f['cpu_key']         ?? '')),
                    'temperature_key'   => trim((string) ($f['temperature_key']   ?? '')),
                    'port_index_start'   => max(1, (int) ($f['port_index_start']   ?? 1)),
                    'sparkline_minutes'  => max(5, (int) ($f['sparkline_minutes']  ?? 30)),
                    'auto_detect_ports'  => (bool)(int)($f['auto_detect_ports']   ?? 0),
                ]);
                $result           = $fetcher->fetchAll();

                // When auto-detect is on, sync rendered port counts with what DataFetcher actually found
                if (!empty($f['auto_detect_ports'])) {
                    $det_rj = $det_sfp = 0;
                    foreach ($result['ports'] as $port) {
                        ($port['is_sfp'] ?? false) ? $det_sfp++ : $det_rj++;
                    }
                    $f['num_ports'] = $det_rj;
                    $f['num_sfp']   = $det_sfp;
                }
                $ports            = $result['ports'];
                $summary          = $result['summary'];
                $port_aliases     = $result['port_aliases'];
                $global_sparkline = (string) ($result['global_sparkline'] ?? '');
                $global_peak_rx   = (string) ($result['global_peak_rx']   ?? '');
                $global_peak_tx   = (string) ($result['global_peak_tx']   ?? '');
            }

            // Widget name: try request input first (live name), then fields, then default.
            $widget_name = trim((string) $this->getInput('name', ''));
            if ($widget_name === '' && method_exists($this->widget, 'getName')) {
                $widget_name = trim((string) $this->widget->getName());
            }
            if ($widget_name === '') {
                $widget_name = 'Switch Visual';
            }

            $this->setResponse(new CControllerResponseData([
                'name'             => $widget_name,
                'widget_name'      => $widget_name,
                'hostid'           => $hostid,
                'no_host'          => ($hostid === ''),
                'error'            => $error,
                'ports'            => $ports,
                'summary'          => $summary,
                'port_aliases'     => $port_aliases,
                'global_sparkline' => $global_sparkline,
                'global_peak_rx'   => $global_peak_rx,
                'global_peak_tx'   => $global_peak_tx,
                'fields'           => $f,
                'user'             => ['debug_mode' => $this->getDebugMode()],
            ]));

        } catch (\Throwable $e) {
            $this->setResponse(new CControllerResponseData([
                'name'             => 'Switch Visual',
                'no_host'          => false,
                'error'            => get_class($e) . ': ' . $e->getMessage()
                                     . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
                'ports'            => [],
                'summary'          => [],
                'port_aliases'     => [],
                'global_sparkline' => '',
                'global_peak_rx'   => '',
                'global_peak_tx'   => '',
                'custom_name'      => '',
                'fields'           => [],
                'user'             => ['debug_mode' => false],
            ]));
        }
    }

    private function extractHostId($value): string {
        $stack = [$value];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (is_array($current)) {
                foreach ($current as $k => $v) {
                    if (is_scalar($k)) {
                        $key = trim((string) $k);
                        if (ctype_digit($key) && (int) $key > 0) return $key;
                    }
                    $stack[] = $v;
                }
                continue;
            }
            if (is_scalar($current)) {
                $text = trim((string) $current);
                if (ctype_digit($text) && (int) $text > 0) return $text;
            }
        }
        return '';
    }
}
