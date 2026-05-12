<?php declare(strict_types=1);

namespace Modules\SwitchVisual\Includes;

class DataFetcher {

    private array $config;

    /** itemid → iface, populated by fetchPorts() for sparkline use */
    private array $in_itemid_map  = [];   // itemid(string) → iface
    private array $out_itemid_map = [];   // itemid(string) → iface
    /** iface → value_type for history API */
    private array $in_value_type  = [];   // iface → int
    private array $out_value_type = [];   // iface → int
    /** iface → port position */
    private array $iface_to_pos   = [];

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function fetchAll(): array {
        $ports        = $this->fetchPorts();
        $port_aliases = $this->fetchAliases();
        $summary      = $this->fetchSummary();

        // Attach per-port combined RX+TX sparklines and compute global aggregate
        $sparks = $this->fetchSparklines();
        foreach ($sparks['per_port'] as $iface => $spark_url) {
            $pos = $this->iface_to_pos[$iface] ?? null;
            if ($pos !== null && isset($ports[$pos])) {
                $ports[$pos]['sparkline'] = (string) $spark_url;
            }
        }
        $global_sparkline = (string) ($sparks['global'] ?? '');
        $peak_rx_raw      = (float)  ($sparks['global_peak_rx'] ?? 0.0);
        $peak_tx_raw      = (float)  ($sparks['global_peak_tx'] ?? 0.0);
        $global_peak_rx   = $peak_rx_raw > 0.0 ? self::fmtBwShort($peak_rx_raw) . 'B/s' : '';
        $global_peak_tx   = $peak_tx_raw > 0.0 ? self::fmtBwShort($peak_tx_raw) . 'B/s' : '';

        // Mark ports with active Zabbix triggers — force state to red
        foreach ($this->fetchTriggers() as $iface => $trig) {
            $pos = $this->iface_to_pos[$iface] ?? null;
            if ($pos !== null && isset($ports[$pos])) {
                $ports[$pos]['has_active_trigger'] = true;
                $ports[$pos]['trigger_desc']       = $trig['desc'];
                $ports[$pos]['state']              = 'red';
            }
        }

        return [
            'ports'            => $ports,
            'summary'          => $summary,
            'port_aliases'     => $port_aliases,
            'global_sparkline' => $global_sparkline,
            'global_peak_rx'   => $global_peak_rx,
            'global_peak_tx'   => $global_peak_tx,
        ];
    }

    // ── Ports ──────────────────────────────────────────────────────────────────

    private function fetchPorts(): array {
        $hostid    = (string) ($this->config['hostid']      ?? '');
        $num_ports = (int)    ($this->config['num_ports']   ?? 24);
        $num_sfp   = (int)    ($this->config['num_sfp']     ?? 2);
        $total     = $num_ports + $num_sfp;
        $in_pat    = (string) ($this->config['bw_in_pattern']   ?? 'ifInOctets[*]');
        $out_pat   = (string) ($this->config['bw_out_pattern']  ?? 'ifOutOctets[*]');
        $stat_pat  = (string) ($this->config['status_pattern']  ?? 'ifOperStatus[*]');
        $spd_pat   = (string) ($this->config['speed_pattern']   ?? 'ifHighSpeed[*]');
        $err_in_pat  = trim((string) ($this->config['err_in_pattern']  ?? 'ifInErrors[*]'));
        $err_out_pat = trim((string) ($this->config['err_out_pattern'] ?? 'ifOutErrors[*]'));

        if ($hostid === '' || strpos($in_pat, '*') === false) return [];

        try {
            $in_items = \API::Item()->get([
                'output'                 => ['itemid', 'key_', 'lastvalue', 'value_type'],
                'hostids'                => [$hostid],
                'search'                 => ['key_' => $in_pat],
                'searchWildcardsEnabled' => true,
                'webitems'               => true,
            ]);
        } catch (\Throwable $e) { return []; }

        if (!is_array($in_items) || $in_items === []) return [];

        $by_iface = [];
        foreach ($in_items as $item) {
            $iface = self::matchWildcard($in_pat, (string) $item['key_']);
            if ($iface === null) continue;
            $by_iface[$iface] = [
                'bw_in'      => (float) $item['lastvalue'],
                'bw_in_key'  => (string) $item['key_'],
                'in_itemid'  => (string) $item['itemid'],
                'in_vtype'   => (int)    $item['value_type'],
            ];
        }

        if ($by_iface === []) return [];

        ksort($by_iface, SORT_NATURAL);

        $auto_detect      = (bool)(int)($this->config['auto_detect_ports'] ?? 0);
        $port_index_start = max(1, (int) ($this->config['port_index_start'] ?? 1));
        $ifaces           = [];

        if ($port_index_start > 1) {
            // Map by absolute SNMP index: iface 4096 with start=4096 → port 1
            foreach (array_keys($by_iface) as $iface) {
                $iface = (string) $iface;
                if (!ctype_digit($iface)) continue;
                $pos = (int) $iface - $port_index_start + 1;
                // Auto-detect: no upper bound; manual: clamp to configured total
                if ($pos >= 1 && ($auto_detect || $pos <= $total)) {
                    $this->iface_to_pos[$iface] = $pos;
                    $ifaces[] = $iface;
                }
            }
        } else {
            // Default: sequential — first N sorted ifaces map to ports 1..N
            $ifaces = $auto_detect
                ? array_map('strval', array_keys($by_iface))
                : array_map('strval', array_slice(array_keys($by_iface), 0, $total));
            foreach ($ifaces as $idx => $iface) {
                $this->iface_to_pos[$iface] = $idx + 1;
            }
        }

        // Auto-detect: recalculate num_ports/num_sfp/total from what was found
        // Write back to config so fetchAliases() reads the same counts.
        if ($auto_detect) {
            $detected_total = count($ifaces);
            $num_sfp        = min($num_sfp, $detected_total);
            $num_ports      = max(0, $detected_total - $num_sfp);
            $total          = $detected_total;
            $this->config['num_ports'] = $num_ports;
            $this->config['num_sfp']   = $num_sfp;
        }

        $keys_needed = [];
        foreach ($ifaces as $iface) {
            if (strpos($out_pat,     '*') !== false) $keys_needed[] = self::sub($out_pat,     $iface);
            if (strpos($stat_pat,    '*') !== false) $keys_needed[] = self::sub($stat_pat,    $iface);
            if (strpos($spd_pat,     '*') !== false) $keys_needed[] = self::sub($spd_pat,     $iface);
            if (strpos($err_in_pat,  '*') !== false) $keys_needed[] = self::sub($err_in_pat,  $iface);
            if (strpos($err_out_pat, '*') !== false) $keys_needed[] = self::sub($err_out_pat, $iface);
        }
        $keys_needed = array_values(array_unique(array_filter($keys_needed)));

        $extra            = [];
        $extra_lastchange = [];   // key_ → Unix timestamp of last value change
        $out_item_meta    = [];   // key_ → [itemid, value_type]
        if ($keys_needed !== []) {
            try {
                $rows = \API::Item()->get([
                    'output'   => ['itemid', 'key_', 'lastvalue', 'value_type', 'lastchange'],
                    'hostids'  => [$hostid],
                    'filter'   => ['key_' => $keys_needed],
                    'webitems' => true,
                ]);
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $extra[(string) $r['key_']]            = (string) $r['lastvalue'];
                        $extra_lastchange[(string) $r['key_']] = (int)    ($r['lastchange'] ?? 0);
                        $out_item_meta[(string) $r['key_']]    = [
                            'itemid'     => (string) $r['itemid'],
                            'value_type' => (int)    $r['value_type'],
                        ];
                    }
                }
            } catch (\Throwable $e) {}
        }

        $ports = [];
        foreach ($ifaces as $idx => $iface) {
            $pos     = $idx + 1;
            $out_key = (strpos($out_pat,  '*') !== false) ? self::sub($out_pat,  $iface) : '';
            $sk      = (strpos($stat_pat, '*') !== false) ? self::sub($stat_pat, $iface) : '';
            $spk     = (strpos($spd_pat,  '*') !== false) ? self::sub($spd_pat,  $iface) : '';

            $status_raw       = $sk !== '' ? ($extra[$sk] ?? null) : null;
            $status           = ($status_raw === null) ? 'up' : (((string) $status_raw === '1') ? 'up' : 'down');
            $speed_negotiated = ($spk !== '' && isset($extra[$spk])) ? (int) $extra[$spk] : 0;
            $bw_out           = ($out_key !== '' && isset($extra[$out_key])) ? (float) $extra[$out_key] : 0.0;

            $erk_in     = (strpos($err_in_pat,  '*') !== false) ? self::sub($err_in_pat,  $iface) : '';
            $erk_out    = (strpos($err_out_pat, '*') !== false) ? self::sub($err_out_pat, $iface) : '';
            $err_in     = ($erk_in  !== '' && isset($extra[$erk_in]))  ? (float) $extra[$erk_in]  : 0.0;
            $err_out    = ($erk_out !== '' && isset($extra[$erk_out])) ? (float) $extra[$erk_out] : 0.0;
            $error_rate = $err_in + $err_out;
            $last_change = $sk !== '' ? ($extra_lastchange[$sk] ?? 0) : 0;

            // Store in/out itemids for sparkline fetching
            $in_itemid = $by_iface[$iface]['in_itemid'];
            $in_vtype  = $by_iface[$iface]['in_vtype'];
            $this->in_itemid_map[$in_itemid]  = $iface;
            $this->in_value_type[$iface]       = $in_vtype;

            if ($out_key !== '' && isset($out_item_meta[$out_key])) {
                $out_itemid = $out_item_meta[$out_key]['itemid'];
                $out_vtype  = $out_item_meta[$out_key]['value_type'];
                $this->out_itemid_map[$out_itemid] = $iface;
                $this->out_value_type[$iface]       = $out_vtype;
            }

            $raw = [
                'iface_name'         => $iface,
                'status'             => $status,
                'speed_negotiated'   => $speed_negotiated,
                'bw_in'              => $by_iface[$iface]['bw_in'],
                'bw_out'             => $bw_out,
                'bw_in_key'          => $by_iface[$iface]['bw_in_key'],
                'bw_out_key'         => $out_key,
                'in_itemid'          => $in_itemid,
                'error_rate'         => $error_rate,
                'last_change'        => $last_change,
                'is_sfp'             => ($pos > $num_ports),
                'has_active_trigger' => false,
                'trigger_desc'       => '',
                'sparkline'          => '',
            ];
            $ports[$pos] = self::resolveState($raw, $this->config);
        }

        for ($i = 1; $i <= $total; $i++) {
            if (!isset($ports[$i])) {
                $ports[$i] = $this->emptyPort($i, $i > $num_ports);
            }
        }
        ksort($ports);
        return $ports;
    }

    // ── Sparklines ─────────────────────────────────────────────────────────────

    /**
     * Fetch RX + TX history for all ports and return a combined SVG
     * sparkline per iface as a base64 data URL.
     *
     * @return array<string, string>  iface → 'data:image/svg+xml;base64,...'
     */
    private function fetchSparklines(): array {
        $sparkline_minutes = max(5, min(360, (int) ($this->config['sparkline_minutes'] ?? 30)));
        $time_from         = time() - ($sparkline_minutes * 60);
        $sparklines = [];   // iface → combined data URL (string)

        $in_history  = $this->batchHistory(array_keys($this->in_itemid_map),  $time_from);
        $out_history = $this->batchHistory(array_keys($this->out_itemid_map), $time_from);

        // Union of all ifaces that have any history
        $ifaces = array_unique(array_merge(
            array_values($this->in_itemid_map),
            array_values($this->out_itemid_map)
        ));

        foreach ($ifaces as $iface) {
            $in_vals  = [];
            $out_vals = [];

            foreach ($this->in_itemid_map as $itemid => $mapped) {
                if ($mapped === $iface && isset($in_history[$itemid])) {
                    $in_vals = $in_history[$itemid];
                    break;
                }
            }
            foreach ($this->out_itemid_map as $itemid => $mapped) {
                if ($mapped === $iface && isset($out_history[$itemid])) {
                    $out_vals = $out_history[$itemid];
                    break;
                }
            }

            if ($in_vals === [] && $out_vals === []) continue;

            $url = self::sparklineDual($in_vals, $out_vals);
            if ($url !== '') {
                $sparklines[$iface] = $url;
            }
        }

        // Compute global aggregate sparkline — reuses the already-fetched history, no extra API calls
        $global = $this->computeGlobalSparkline($in_history, $out_history);
        return [
            'per_port'       => $sparklines,
            'global'         => $global['svg'],
            'global_peak_rx' => $global['peak_rx'],
            'global_peak_tx' => $global['peak_tx'],
        ];
    }

    /**
     * Batch-fetch history for a list of itemids, trying float (type 0) then
     * uint64 (type 3).
     *
     * @return array<string, float[]>  itemid → ordered float values
     */
    private function batchHistory(array $itemids, int $time_from): array {
        if ($itemids === []) return [];

        $result = [];
        foreach ([0, 3] as $vtype) {   // 0=float, 3=uint64
            try {
                $rows = \API::History()->get([
                    'output'    => ['itemid', 'clock', 'value'],
                    'itemids'   => $itemids,
                    'time_from' => $time_from,
                    'history'   => $vtype,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit'     => 2000,
                ]);
                if (!is_array($rows)) continue;
                foreach ($rows as $r) {
                    $result[(string) $r['itemid']][] = (float) $r['value'];
                }
            } catch (\Throwable $e) {}
        }
        return $result;
    }

    /**
     * Generate a side-by-side RX (green, left) + TX (blue, right) sparkline SVG
     * and return it as a base64 data URL.  Each panel has its own Y scale so
     * even a lightly-loaded TX line is readable when RX dominates.
     *
     * @param float[] $in_vals   RX sample values (30-minute window)
     * @param float[] $out_vals  TX sample values (30-minute window)
     */
    private static function sparklineDual(array $in_vals, array $out_vals): string {
        if (count($in_vals) < 2 && count($out_vals) < 2) return '';

        $total_w = 162; $h = 28; $pad = 2;
        $panel_w = 76;  $gap = 10;  // 76 + 10 + 76 = 162

        $make_panel = static function(array $vals, string $stroke, string $fill_rgba, float $ox)
                use ($panel_w, $h, $pad): string {
            $n = count($vals);
            if ($n < 2) return '';
            $max      = max($vals) ?: 1.0;
            $area_pts = [($ox + $pad) . ',' . $h];
            $line_pts = [];
            foreach ($vals as $i => $v) {
                $x          = $ox + $pad + ($i / ($n - 1)) * ($panel_w - 2 * $pad);
                $y          = $h - $pad - ($v / $max) * ($h - 2 * $pad);
                $pt         = round($x, 1) . ',' . round($y, 1);
                $area_pts[] = $pt;
                $line_pts[] = $pt;
            }
            $area_pts[] = ($ox + $panel_w - $pad) . ',' . $h;
            return '<polygon points="'  . implode(' ', $area_pts) . '" fill="' . $fill_rgba . '"/>'
                 . '<polyline points="' . implode(' ', $line_pts)
                 . '" fill="none" stroke="' . $stroke . '" stroke-width="1.5" stroke-linejoin="round"/>';
        };

        $tx_ox   = $panel_w + $gap;
        $lbl_in  = count($in_vals)  >= 2 ? self::fmtBwShort(max($in_vals))  : '';
        $lbl_out = count($out_vals) >= 2 ? self::fmtBwShort(max($out_vals)) : '';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total_w . '" height="' . $h . '">'
             // panel backgrounds
             . '<rect x="0" y="0" width="' . $panel_w . '" height="' . $h . '" fill="#0a0e14" rx="2"/>'
             . '<rect x="' . $tx_ox . '" y="0" width="' . $panel_w . '" height="' . $h . '" fill="#0a0e14" rx="2"/>'
             // chart data
             . $make_panel($in_vals,  '#27c060', 'rgba(39,192,96,0.25)',  0)
             . $make_panel($out_vals, '#4499ff', 'rgba(68,153,255,0.2)',  $tx_ox)
             // channel labels (left side)
             . '<text x="3" y="9" font-size="7" font-family="monospace" fill="#27c060" font-weight="bold">RX</text>'
             . '<text x="' . ($tx_ox + 3) . '" y="9" font-size="7" font-family="monospace" fill="#4499ff" font-weight="bold">TX</text>'
             // peak-value scale (right side)
             . ($lbl_in  !== '' ? '<text x="' . ($panel_w - 2) . '" y="10" font-size="7" font-family="monospace" fill="#38b870" font-weight="bold" text-anchor="end">' . htmlspecialchars($lbl_in)  . '</text>' : '')
             . ($lbl_out !== '' ? '<text x="' . ($tx_ox + $panel_w - 2) . '" y="10" font-size="7" font-family="monospace" fill="#5599ff" font-weight="bold" text-anchor="end">' . htmlspecialchars($lbl_out) . '</text>' : '')
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Sum per-port history (already fetched) into 40 time-normalised bins
     * to produce a global aggregate sparkline for the whole switch.
     * No extra API calls — reuses $in_history / $out_history from fetchSparklines().
     *
     * @param array<string, float[]> $in_history   itemid → ordered float values
     * @param array<string, float[]> $out_history  itemid → ordered float values
     */
    private function computeGlobalSparkline(array $in_history, array $out_history): array {
        $n = 40;
        $in_totals  = array_fill(0, $n, 0.0);
        $out_totals = array_fill(0, $n, 0.0);

        foreach ($in_history as $series) {
            if (count($series) < 2) continue;
            foreach (self::resample($series, $n) as $i => $v) {
                $in_totals[$i] += $v;
            }
        }
        foreach ($out_history as $series) {
            if (count($series) < 2) continue;
            foreach (self::resample($series, $n) as $i => $v) {
                $out_totals[$i] += $v;
            }
        }

        if (max($in_totals) <= 0.0 && max($out_totals) <= 0.0) {
            return ['svg' => '', 'peak_rx' => 0.0, 'peak_tx' => 0.0];
        }
        return self::sparklineGlobal($in_totals, $out_totals);
    }

    /**
     * Linear-interpolation resample of $vals to exactly $n evenly-spaced points.
     *
     * @param  float[] $vals  source series (≥ 1 element)
     * @return float[]        resampled series of length $n
     */
    private static function resample(array $vals, int $n): array {
        $count = count($vals);
        if ($count === 0) return array_fill(0, $n, 0.0);
        if ($count === 1) return array_fill(0, $n, $vals[0]);
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $pos      = ($i / ($n - 1)) * ($count - 1);
            $lo       = (int) $pos;
            $hi       = min($lo + 1, $count - 1);
            $frac     = $pos - $lo;
            $result[] = $vals[$lo] * (1.0 - $frac) + $vals[$hi] * $frac;
        }
        return $result;
    }

    /**
     * Generate a full-width global aggregate sparkline (RX green + TX blue overlaid)
     * as a viewBox SVG data URL.  No fixed pixel width — CSS sets the display size.
     *
     * @param float[] $in_vals   aggregated RX values (40 points)
     * @param float[] $out_vals  aggregated TX values (40 points)
     */
    private static function sparklineGlobal(array $in_vals, array $out_vals): array {
        $n   = count($in_vals);
        $w   = 320; $h = 22; $pad = 2;
        $max = max(max($in_vals ?: [0.0]), max($out_vals ?: [0.0])) ?: 1.0;

        $make_area = static function(array $vals, string $stroke, string $fill)
                use ($n, $w, $h, $pad, $max): string {
            if ($n < 2 || max($vals) <= 0.0) return '';
            $area = [$pad . ',' . $h];
            $line = [];
            foreach ($vals as $i => $v) {
                $x      = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
                $y      = $h - $pad - ($v / $max) * ($h - 2 * $pad);
                $pt     = round($x, 1) . ',' . round($y, 1);
                $area[] = $pt;
                $line[] = $pt;
            }
            $area[] = ($w - $pad) . ',' . $h;
            return '<polygon points="' . implode(' ', $area) . '" fill="' . $fill . '"/>'
                 . '<polyline points="' . implode(' ', $line)
                 . '" fill="none" stroke="' . $stroke . '" stroke-width="1.5" stroke-linejoin="round"/>';
        };

        // No text in the SVG — text stretches with background-image on wide chassis.
        // Peak labels are rendered as HTML elements in the view instead.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
             . '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="#0a0e14" rx="3"/>'
             . $make_area($in_vals,  '#27c060', 'rgba(39,192,96,0.22)')
             . $make_area($out_vals, '#4499ff', 'rgba(68,153,255,0.18)')
             . '</svg>';

        return [
            'svg'     => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'peak_rx' => count($in_vals)  ? max($in_vals)  : 0.0,
            'peak_tx' => count($out_vals) ? max($out_vals) : 0.0,
        ];
    }

    /**
     * Format a raw history value as a compact scale label for SVG.
     * No unit is assumed — values may be bytes/sec, octets, or deltas depending
     * on item configuration.  Just conveys the order of magnitude.
     */
    private static function fmtBwShort(float $val): string {
        $v = abs($val);
        if ($v >= 1e12) return round($v / 1e12, 1) . 'T';
        if ($v >= 1e9)  return round($v / 1e9,  1) . 'G';
        if ($v >= 1e6)  return round($v / 1e6,  1) . 'M';
        if ($v >= 1e3)  return round($v / 1e3,  0) . 'K';
        return max(1, (int) $v) . '';
    }

    // ── Triggers ───────────────────────────────────────────────────────────────

    /**
     * Fetch active triggers for the host and return a map of iface → trigger info
     * for ports that are currently in a problem state.  Only triggers whose items
     * match a configured port key pattern are returned.
     *
     * @return array<string, array{desc: string, priority: int}>
     */
    private function fetchTriggers(): array {
        $hostid = (string) ($this->config['hostid'] ?? '');
        if ($hostid === '' || $this->iface_to_pos === []) return [];

        $patterns = array_values(array_filter([
            $this->config['bw_in_pattern']  ?? '',
            $this->config['bw_out_pattern'] ?? '',
            $this->config['status_pattern'] ?? '',
            $this->config['speed_pattern']  ?? '',
        ], static fn(string $p): bool => strpos($p, '*') !== false));

        if ($patterns === []) return [];

        $problems = [];   // iface → ['desc' => string, 'priority' => int]
        try {
            $triggers = \API::Trigger()->get([
                'output'        => ['triggerid', 'description', 'priority', 'value'],
                'hostids'       => [$hostid],
                'filter'        => ['value' => 1],   // TRIGGER_VALUE_TRUE = problem
                'monitored'     => true,
                'skipDependent' => true,
                'active'        => true,
                'selectItems'   => ['key_'],
            ]);
            if (!is_array($triggers)) return [];

            foreach ($triggers as $trig) {
                $priority = (int)    ($trig['priority']    ?? 0);
                $desc     = (string) ($trig['description'] ?? '');
                $matched  = false;
                foreach ((array) ($trig['items'] ?? []) as $item) {
                    if ($matched) break;
                    $key = (string) ($item['key_'] ?? '');
                    foreach ($patterns as $pat) {
                        $iface = self::matchWildcard($pat, $key);
                        if ($iface !== null) {
                            // Keep highest-priority trigger per iface
                            if (!isset($problems[$iface]) || $priority > $problems[$iface]['priority']) {
                                $problems[$iface] = ['desc' => $desc, 'priority' => $priority];
                            }
                            $matched = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}

        return $problems;
    }

    // ── Aliases ────────────────────────────────────────────────────────────────

    private function fetchAliases(): array {
        $hostid    = (string) ($this->config['hostid']       ?? '');
        $in_pat    = (string) ($this->config['bw_in_pattern']  ?? 'ifInOctets[*]');
        $alias_pat = trim((string) ($this->config['alias_pattern'] ?? ''));
        $num_ports = (int) ($this->config['num_ports'] ?? 24);
        $num_sfp   = (int) ($this->config['num_sfp']   ?? 2);
        $total     = $num_ports + $num_sfp;

        if ($hostid === '' || $alias_pat === '' || strpos($alias_pat, '*') === false) return [];

        $aliases = [];
        try {
            $items = \API::Item()->get([
                'output'                 => ['key_', 'lastvalue'],
                'hostids'                => [$hostid],
                'search'                 => ['key_' => $alias_pat],
                'searchWildcardsEnabled' => true,
                'webitems'               => true,
            ]);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $iface = self::matchWildcard($alias_pat, (string) $item['key_']);
                    if ($iface !== null) {
                        $aliases[(string) $iface] = trim((string) $item['lastvalue']);
                    }
                }
            }
        } catch (\Throwable $e) {}

        if ($aliases === []) return [];

        $by_iface = [];
        try {
            $in_items = \API::Item()->get([
                'output'                 => ['key_'],
                'hostids'                => [$hostid],
                'search'                 => ['key_' => $in_pat],
                'searchWildcardsEnabled' => true,
                'webitems'               => true,
            ]);
            if (is_array($in_items)) {
                foreach ($in_items as $item) {
                    $iface = self::matchWildcard($in_pat, (string) $item['key_']);
                    if ($iface !== null) $by_iface[$iface] = true;
                }
            }
        } catch (\Throwable $e) {}

        ksort($by_iface, SORT_NATURAL);
        $ifaces  = array_map('strval', array_slice(array_keys($by_iface), 0, $total));
        $indexed = [];
        foreach ($ifaces as $idx => $iface) {
            $pos = $idx + 1;
            if (isset($aliases[$iface])) {
                $indexed[$pos] = $aliases[$iface];
            }
        }
        return $indexed;
    }

    // ── Summary ────────────────────────────────────────────────────────────────

    private function fetchSummary(): array {
        $hostid = (string) ($this->config['hostid'] ?? '');
        $result = ['ip' => '', 'uptime' => '', 'serial' => '', 'model' => '', 'cpu' => '', 'temperature' => ''];

        if ($hostid === '') return $result;

        try {
            $hosts = \API::Host()->get([
                'output'           => ['hostid'],
                'hostids'          => [$hostid],
                'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'useip'],
            ]);
            if (is_array($hosts) && !empty($hosts[0]['interfaces'])) {
                foreach ($hosts[0]['interfaces'] as $iface) {
                    if ((int) $iface['main'] === 1) {
                        $result['ip'] = ((int) $iface['useip'] === 1) ? $iface['ip'] : $iface['dns'];
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {}

        $key_map = [];
        foreach (['uptime_key' => 'uptime', 'serial_key' => 'serial', 'model_key' => 'model',
                  'cpu_key' => 'cpu', 'temperature_key' => 'temperature'] as $cfg => $field) {
            $k = trim((string) ($this->config[$cfg] ?? ''));
            if ($k !== '') $key_map[$k] = $field;
        }

        if ($key_map !== []) {
            try {
                $items = \API::Item()->get([
                    'output'   => ['key_', 'lastvalue'],
                    'hostids'  => [$hostid],
                    'filter'   => ['key_' => array_keys($key_map)],
                    'webitems' => true,
                ]);
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $field = $key_map[(string) $item['key_']] ?? null;
                        if ($field === null) continue;
                        $val = (string) $item['lastvalue'];
                        $result[$field] = ($field === 'uptime') ? self::formatUptime((float) $val) : $val;
                    }
                }
            } catch (\Throwable $e) {}
        }

        return $result;
    }

    // ── State resolution ───────────────────────────────────────────────────────

    public static function resolveState(array $raw, array $config = []): array {
        $speed_raw    = (int)   ($raw['speed_negotiated'] ?? 0);
        // ifHighSpeed returns Mbps (e.g. 1000 for 1 Gbps); ifSpeed returns bps (e.g. 1_000_000_000).
        // Normalize to bytes/sec so we can compare directly with bw_in/bw_out (bytes/sec).
        $speed_mbps   = $speed_raw > 1_000_000 ? (int) round($speed_raw / 1_000_000) : $speed_raw;
        $speed_Bps    = $speed_mbps * 125_000;  // 1 Mbps = 125,000 B/s
        $bw_in        = (float) ($raw['bw_in']  ?? 0.0);
        $bw_out       = (float) ($raw['bw_out'] ?? 0.0);
        $util_pct     = ($speed_Bps > 0) ? round(($bw_in  / $speed_Bps) * 100, 2) : 0.0;
        $util_pct_out = ($speed_Bps > 0) ? round(($bw_out / $speed_Bps) * 100, 2) : 0.0;

        if ($raw['status'] === 'down') {
            return array_merge($raw, ['state' => 'gray', 'util_pct' => 0.0, 'util_pct_out' => 0.0, 'warnings' => []]);
        }

        $state    = 'green';
        $warnings = [];
        $err      = (float) ($raw['error_rate'] ?? 0.0);
        $crit     = (float) ($config['crit_err_threshold']  ?? 1.0);
        $warn     = (float) ($config['warn_err_threshold']  ?? 0.1);
        $util_thr = (float) ($config['warn_util_threshold'] ?? 80.0);

        if ($err >= $crit || ($raw['has_active_trigger'] ?? false)) {
            $state = 'red';
        } elseif ($err >= $warn && $err > 0) {
            $state = 'amber';
            $warnings[] = sprintf('Error rate %.2f%%', $err);
        } elseif ($util_thr > 0 && $util_pct >= $util_thr) {
            $state = 'amber';
            $warnings[] = sprintf('Utilization > %.0f%%', $util_thr);
        }

        return array_merge($raw, ['state' => $state, 'util_pct' => $util_pct, 'util_pct_out' => $util_pct_out, 'warnings' => $warnings]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function matchWildcard(string $pattern, string $key): ?string {
        $parts  = explode('*', $pattern, 2);
        $before = preg_quote($parts[0], '/');
        $after  = isset($parts[1]) ? preg_quote($parts[1], '/') : '';
        if (!preg_match('/^' . $before . '(.+)' . $after . '$/', $key, $m)) return null;
        return $m[1];
    }

    private static function sub(string $pattern, string $iface): string {
        return str_replace(['*', '{#PORT}', '{#IFACE}'], $iface, $pattern);
    }

    private function emptyPort(int $pos, bool $is_sfp): array {
        return [
            'iface_name'         => (string) $pos,
            'status'             => 'down',
            'speed_negotiated'   => 0,
            'bw_in'              => 0.0,
            'bw_out'             => 0.0,
            'bw_in_key'          => '',
            'bw_out_key'         => '',
            'error_rate'         => 0.0,
            'util_pct'           => 0.0,
            'state'              => 'gray',
            'warnings'           => [],
            'last_change'        => 0,
            'is_sfp'             => $is_sfp,
            'has_active_trigger' => false,
            'trigger_desc'       => '',
            'sparkline'          => '',
        ];
    }

    private static function formatUptime(float $s): string {
        if ($s <= 0.0) return '';
        $d = (int) ($s / 86400);
        $h = (int) (($s % 86400) / 3600);
        $m = (int) (($s % 3600) / 60);
        if ($d > 0) return "{$d}d {$h}h";
        if ($h > 0) return "{$h}h {$m}m";
        return "{$m}m";
    }
}
