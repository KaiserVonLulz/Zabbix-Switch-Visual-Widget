<?php declare(strict_types=1);

$ports        = $data['ports']        ?? [];
$fields       = $data['fields']       ?? [];
$no_host      = $data['no_host']      ?? false;
$error        = $data['error']        ?? null;
$summary      = $data['summary']      ?? [];
$port_aliases = $data['port_aliases'] ?? [];
$widget_name  = (string) ($data['widget_name'] ?? $data['name'] ?? 'Switch');
$numRj        = (int) ($fields['num_ports'] ?? 24);
$numSfp       = (int) ($fields['num_sfp']   ?? 2);
$scale        = max(0.4, min(3.0, (float) ($fields['scale']     ?? 100) / 100));
$port_rows    = max(1, min(2,   (int)   ($fields['port_rows'] ?? 2)));

$sfx = static function(string $state): string {
    return ['green' => 'g', 'amber' => 'a', 'red' => 'r', 'gray' => 'z', 'blue' => 'g'][$state] ?? 'z';
};

// Compact value formatter — no unit assumed (item may store bytes/sec, octets, or delta)
$fmt_bw = static function(float $val): string {
    $v = abs($val);
    if ($v >= 1e12) return round($v / 1e12, 1) . ' T';
    if ($v >= 1e9)  return round($v / 1e9,  1) . ' G';
    if ($v >= 1e6)  return round($v / 1e6,  1) . ' M';
    if ($v >= 1e3)  return round($v / 1e3,  1) . ' K';
    return max(0, (int) $v) . '';
};

// Duration formatter for "down for X" display
$fmt_dur = static function(int $secs): string {
    if ($secs <= 0) return '';
    $d = (int) ($secs / 86400);
    $h = (int) (($secs % 86400) / 3600);
    $m = (int) (($secs % 3600) / 60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return max(1, $m) . 'm';
};

// Manual aliases override SNMP aliases — format: "1=Uplink, 3=ESX01, 5=Core"
$manual_raw = trim((string) ($fields['port_aliases_manual'] ?? ''));
if ($manual_raw !== '') {
    foreach (explode(',', $manual_raw) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2) {
            $pnum = (int) trim($parts[0]);
            $lbl  = trim($parts[1]);
            if ($pnum > 0 && $lbl !== '') {
                $port_aliases[$pnum] = $lbl;
            }
        }
    }
}

// .sw-outer contains the dynamic zoom — defined separately below
$css = <<<'CSS'
.sw-chassis{
    background:linear-gradient(175deg,#606b78 0%,#404c58 16%,#2c333c 100%);
    border:1px solid #1a2028;border-radius:8px;padding:10px 14px 8px 14px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.15),0 6px 20px rgba(0,0,0,.4);
    display:inline-flex;flex-direction:column;gap:8px;
}
.sw-hdr{display:flex;justify-content:space-between;align-items:baseline;gap:14px;
    padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.18);}
.sw-hdr-name{font-family:monospace;font-size:13px;font-weight:700;
    color:#e8f2fc;letter-spacing:.04em;}
.sw-hdr-ip{font-family:monospace;font-size:11px;color:#8aaec8;font-weight:600;}
.sw-port-area{display:flex;align-items:flex-start;gap:14px;}
.sw-rj-block{display:flex;flex-direction:column;gap:4px;}
.sw-sfp-block{display:flex;flex-direction:column;gap:4px;
    border-left:2px solid #3d4d5a;padding-left:12px;}
.sw-row{display:flex;flex-direction:row;gap:3px;align-items:flex-start;}
.sw-pw{display:flex;flex-direction:column;align-items:center;gap:2px;cursor:default;}
.sw-p{width:18px;height:15px;border-radius:3px 3px 2px 2px;border:2px solid #2d3340;
    background:linear-gradient(180deg,#141820 0%,#0b0b14 100%);
    display:flex;align-items:center;justify-content:center;
    box-shadow:inset 0 2px 3px rgba(0,0,0,.5),inset 0 -1px 0 rgba(255,255,255,.04);}
.sw-p-g{border-color:#1f9048;background:linear-gradient(180deg,#071a0e 0%,#030e08 100%);}
.sw-p-a{border-color:#a07d00;background:linear-gradient(180deg,#130e00 0%,#0a0700 100%);}
.sw-p-r{border-color:#a02828;background:linear-gradient(180deg,#150404 0%,#0a0202 100%);}
.sw-p-z{border-color:#364050;background:linear-gradient(180deg,#141820 0%,#0e1018 100%);}
.sw-led{width:11px;height:5px;border-radius:2px;background:#1c2030;}
.sw-led-g{background:#2ad468;box-shadow:0 0 6px rgba(39,192,96,.9),0 0 2px rgba(42,212,104,.6);}
.sw-led-a{background:#e89000;box-shadow:0 0 6px rgba(232,144,0,.9),0 0 2px rgba(224,128,0,.6);}
.sw-led-r{background:#e83838;box-shadow:0 0 6px rgba(232,56,56,.9),0 0 2px rgba(224,48,48,.6);}
.sw-led-z{background:#1c2030;}
.sw-sfp{width:26px;height:19px;border-radius:2px;border:2px solid #1a406a;
    background:linear-gradient(180deg,#0c1520 0%,#060d14 100%);
    display:flex;align-items:center;justify-content:center;
    box-shadow:inset 0 2px 3px rgba(0,0,0,.5),inset 0 -1px 0 rgba(255,255,255,.04);}
.sw-sfp-g{border-color:#1f9048;background:linear-gradient(180deg,#071a0e 0%,#030e08 100%);}
.sw-sfp-a{border-color:#a07d00;background:linear-gradient(180deg,#130e00 0%,#0a0700 100%);}
.sw-sfp-r{border-color:#a02828;background:linear-gradient(180deg,#150404 0%,#0a0202 100%);}
.sw-sfp-z{border-color:#1a406a;background:linear-gradient(180deg,#0c1520 0%,#060d14 100%);}
.sw-sfp-dot{width:8px;height:8px;border-radius:50%;background:#1e3050;flex-shrink:0;}
.sw-sfp-dot-g{background:#2ad468;box-shadow:0 0 5px rgba(39,192,96,.9);}
.sw-sfp-dot-a{background:#e89000;box-shadow:0 0 5px rgba(232,144,0,.9);}
.sw-sfp-dot-r{background:#e83838;box-shadow:0 0 5px rgba(232,56,56,.9);}
.sw-sfp-dot-z{background:#1e3050;}
.sw-num{font-size:9px;font-family:monospace;font-weight:700;line-height:1;}
.sw-num-g{color:#30c870;}.sw-num-a{color:#d09000;}.sw-num-r{color:#c03030;}.sw-num-z{color:#5a7090;}
.sw-alias{font-size:7px;font-family:monospace;color:#c8e0f4;line-height:1;font-weight:700;
    min-height:7px;max-width:32px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center;}
.sw-sum{border-top:1px solid rgba(255,255,255,.12);padding-top:6px;
    display:flex;flex-wrap:wrap;gap:0;font-family:monospace;}
.sw-sum-k{font-size:10px;color:#6888a0;font-weight:600;}
.sw-sum-v{font-size:10px;color:#b0ccdf;font-weight:700;margin-right:12px;}
.sw-empty{padding:18px 24px;font-size:12px;font-family:monospace;color:#c8d8e8;
    text-align:center;background:rgba(255,255,255,.07);border-radius:4px;}
.sw-err{color:#ff6060;font-weight:bold;}
.sw-warn{color:#ffd060;font-weight:bold;}
.sw-ub{height:2px;width:18px;background:#141c28;border-radius:1px;}
.sw-ubf{height:2px;border-radius:1px;}
.sw-ubf-g{background:#27c060;}.sw-ubf-a{background:#e89000;}.sw-ubf-r{background:#e83838;}.sw-ubf-z{background:#141c28;}
.sw-ubw0{width:0;}.sw-ubw1{width:10%;}.sw-ubw2{width:20%;}.sw-ubw3{width:30%;}
.sw-ubw4{width:40%;}.sw-ubw5{width:50%;}.sw-ubw6{width:60%;}.sw-ubw7{width:70%;}
.sw-ubw8{width:80%;}.sw-ubw9{width:90%;}.sw-ubw10{width:100%;}
.sw-spd-slow{opacity:.6;}
.sw-warn-icon{font-size:11px;color:#ffcc00;font-weight:700;cursor:pointer;
    padding:1px 6px;border-radius:3px;background:rgba(255,200,0,.18);flex-shrink:0;}
CSS;

// Dynamic rule: zoom is set from widget field
$css .= '.sw-outer{display:block;width:100%;padding:4px;box-sizing:border-box;zoom:' . $scale . ';}';

// Tooltip CSS — injected inside each hint so it applies in Zabbix's hint popup container
$tip_css = '.swt-wrap{font-family:monospace;min-width:170px;padding:8px 10px;'
         .     'background:#111820;border:1px solid #2a3a4a;border-radius:5px;'
         .     'box-shadow:0 4px 16px rgba(0,0,0,.7);}'
         . '.swt-name{font-size:12px;font-weight:700;color:#e8f4ff;margin-bottom:7px;'
         .     'border-bottom:1px solid #2a3a4a;padding-bottom:5px;}'
         . '.swt-g{display:grid;grid-template-columns:max-content 1fr;gap:4px 14px;font-size:11px;}'
         . '.swt-k{color:#7ab0d0;font-weight:600;}'
         . '.swt-v{color:#e8f4ff;font-weight:700;}'
         . '.swt-w{color:#ffcc44;font-size:10px;font-weight:700;margin-top:5px;}'
         . '.swt-sl{margin-top:9px;}'
         . '.swt-sl-lbl{font-size:9px;color:#7ab0d0;font-weight:600;margin-bottom:3px;}'
         . '.swt-spark{width:162px;height:28px;display:block;border-radius:2px;}';

// ── Tooltip builder ────────────────────────────────────────────────────────────
$make_tip = static function(int $pos, array $port, string $alias) use ($tip_css, $fmt_bw, $fmt_dur): CDiv {
    $state     = $port['state'] ?? 'gray';
    $speed     = (int) ($port['speed_negotiated'] ?? 0);
    $iface     = (string) ($port['iface_name'] ?? ('Port ' . $pos));
    $label     = $alias !== '' ? $alias . '  (' . $iface . ')' : $iface;
    $sparkline = (string) ($port['sparkline'] ?? '');

    // Unique CSS class per port so sparkline data URLs don't collide
    $cid       = 'swt-s' . $pos;
    $spark_css = $sparkline !== ''
        ? '.' . $cid . '{background-image:url("' . $sparkline . '");background-size:162px 28px;background-repeat:no-repeat;}'
        : '';

    // Normalize speed to Mbps: ifSpeed returns bps (1e9 for 1 Gbps); ifHighSpeed returns Mbps (1000 for 1 Gbps)
    $speed_mbps = $speed > 1000000 ? (int) round($speed / 1e6) : $speed;

    // Speed label: show negotiated speed with unit
    $spd_label = 'N/A';
    if ($speed_mbps > 0) {
        $spd_label = $speed_mbps >= 1000 ? round($speed_mbps / 1000, 0) . ' Gbps' : $speed_mbps . ' Mbps';
    }

    // Port type includes SFP/RJ45 marker
    $type_label = (!empty($port['is_sfp']) ? 'SFP' : 'RJ45') . ($speed_mbps >= 10000 ? ' 10G' : ($speed_mbps >= 1000 ? ' 1G' : ($speed_mbps >= 100 ? ' 100M' : '')));

    // Stats grid
    $grid_rows = [
        'Type'   => $type_label,
        'Status' => strtoupper($state),
        'Speed'  => $spd_label,
        'RX'     => $fmt_bw((float) ($port['bw_in']  ?? 0.0)),
        'TX'     => $fmt_bw((float) ($port['bw_out'] ?? 0.0)),
        'Util'   => number_format((float) ($port['util_pct'] ?? 0.0), 1) . '%',
    ];
    // Show "Down for" when port is inactive and we have a timestamp
    if ($state === 'gray') {
        $lc = (int) ($port['last_change'] ?? 0);
        if ($lc > 0) {
            $grid_rows['Down for'] = $fmt_dur(time() - $lc);
        }
    }

    $grid = (new CDiv())->addClass('swt-g');
    foreach ($grid_rows as $k => $v) {
        $grid->addItem((new CSpan($k . ':'))->addClass('swt-k'));
        $grid->addItem((new CSpan($v))->addClass('swt-v'));
    }

    // Side-by-side RX+TX sparkline — labels embedded in SVG (inside-out before addItem)
    $spark_section = null;
    if ($sparkline !== '') {
        $lbl  = (new CDiv('Traffic — 30 min'))->addClass('swt-sl-lbl');
        $bar  = (new CDiv())->addClass('swt-spark ' . $cid);
        $spark_section = (new CDiv())->addClass('swt-sl');
        $spark_section->addItem($lbl);
        $spark_section->addItem($bar);
    }

    // Assemble tooltip (inside-out: all children complete before addItem)
    $name_div = (new CDiv($label))->addClass('swt-name');

    $tip = (new CDiv())->addClass('swt-wrap');
    $tip->addItem(new CTag('style', true, $tip_css . $spark_css));
    $tip->addItem($name_div);
    $tip->addItem($grid);
    foreach ((array) ($port['warnings'] ?? []) as $w) {
        $tip->addItem((new CDiv('! ' . $w))->addClass('swt-w'));
    }
    if ($spark_section !== null) {
        $tip->addItem($spark_section);
    }
    return $tip;
};

// ── Build chassis content inside-out ──────────────────────────────────────────
// Rule: every CDiv must be fully populated BEFORE being passed to addItem().

$chassis_children = [];

if ($error !== null) {

    $chassis_children[] = (new CDiv('Error: ' . htmlspecialchars($error)))->addClass('sw-empty sw-err');

} elseif ($no_host) {

    $chassis_children[] = (new CDiv('Select a host in widget settings.'))->addClass('sw-empty');

} elseif (empty($ports)) {

    $chassis_children[] = (new CDiv(
        'No ports found — verify item key patterns match your Zabbix items.'
    ))->addClass('sw-empty sw-warn');

} else {

    // ── Collect active-trigger ports for chassis warning indicator ────────────
    $warn_list = [];
    foreach ($ports as $wpos => $wport) {
        if (!empty($wport['has_active_trigger'])) {
            $walias = $port_aliases[$wpos] ?? '';
            $wiface = (string) ($wport['iface_name'] ?? $wpos);
            $wname  = $walias !== '' ? $walias . ' (' . $wiface . ')' : $wiface;
            $wdesc  = trim((string) ($wport['trigger_desc'] ?? ''));
            $warn_list[] = $wname . ($wdesc !== '' ? ': ' . $wdesc : '');
        }
    }

    // ── Header ────────────────────────────────────────────────────────────────
    $hdr = (new CDiv())->addClass('sw-hdr');
    $hdr->addItem((new CDiv($widget_name))->addClass('sw-hdr-name'));

    if ($warn_list !== []) {
        $wtip = (new CDiv())->addClass('swt-wrap');
        $wtip->addItem(new CTag('style', true, $tip_css));
        $wtip->addItem((new CDiv('Active Problems (' . count($warn_list) . ')'))->addClass('swt-name'));
        foreach ($warn_list as $wline) {
            $wtip->addItem((new CDiv('⚠ ' . $wline))->addClass('swt-w'));
        }
        $wicon = (new CDiv('⚠ ' . count($warn_list)))->addClass('sw-warn-icon');
        if (method_exists($wicon, 'setHint')) {
            $wicon->setHint($wtip, '', false);
        }
        $hdr->addItem($wicon);
    }

    if (!empty($summary['ip'])) {
        $hdr->addItem((new CDiv('IP: ' . $summary['ip']))->addClass('sw-hdr-ip'));
    }

    // ── RJ45 rows ─────────────────────────────────────────────────────────────
    $rj_rows = [];
    for ($r = 0; $r < $port_rows; $r++) {
        $rj_rows[$r] = (new CDiv())->addClass('sw-row');
    }

    for ($i = 1; $i <= $numRj; $i++) {
        $port      = $ports[$i] ?? null;
        $state     = $port !== null ? ($port['state'] ?? 'gray') : 'gray';
        $s         = $sfx($state);
        $alias     = $port_aliases[$i] ?? '';
        $speed_val  = (int) ($port['speed_negotiated'] ?? 0);
        $speed_mbps = $speed_val > 1000000 ? (int) round($speed_val / 1e6) : $speed_val;
        $spd_cls    = $speed_mbps > 0 && $speed_mbps <= 100 ? ' sw-spd-slow' : '';
        $util_pct  = max((float) ($port['util_pct'] ?? 0.0), (float) ($port['util_pct_out'] ?? 0.0));
        $util_w    = ($state === 'gray') ? 0 : min(10, (int) round($util_pct / 10));

        $card = (new CDiv())->addClass('sw-p sw-p-' . $s . $spd_cls);
        $card->addItem((new CDiv())->addClass('sw-led sw-led-' . $s));

        $util_fill = (new CDiv())->addClass('sw-ubf sw-ubf-' . $s . ' sw-ubw' . $util_w);
        $util_bar  = (new CDiv())->addClass('sw-ub');
        $util_bar->addItem($util_fill);

        $pw = (new CDiv())->addClass('sw-pw');
        $pw->addItem($card);
        $pw->addItem($util_bar);
        $pw->addItem((new CDiv((string) $i))->addClass('sw-num sw-num-' . $s));
        $pw->addItem((new CDiv($alias))->addClass('sw-alias'));
        if ($port !== null && method_exists($pw, 'setHint')) {
            $pw->setHint($make_tip($i, $port, $alias), '', false);
        }
        // 2-row: odd ports top, even ports bottom; 1-row: all in single row
        $rj_rows[($port_rows > 1 && $i % 2 === 0) ? 1 : 0]->addItem($pw);
    }

    $rj_block = (new CDiv())->addClass('sw-rj-block');
    foreach ($rj_rows as $row) {
        $rj_block->addItem($row);
    }

    // ── Port area ─────────────────────────────────────────────────────────────
    $port_area = (new CDiv())->addClass('sw-port-area');
    $port_area->addItem($rj_block);

    // ── SFP rows ──────────────────────────────────────────────────────────────
    if ($numSfp > 0) {
        $sfp_rows = [];
        for ($r = 0; $r < $port_rows; $r++) {
            $sfp_rows[$r] = (new CDiv())->addClass('sw-row');
        }

        for ($i = 1; $i <= $numSfp; $i++) {
            $pos       = $numRj + $i;
            $port      = $ports[$pos] ?? null;
            $state     = $port !== null ? ($port['state'] ?? 'gray') : 'gray';
            $s         = $sfx($state);
            $alias     = $port_aliases[$pos] ?? '';
            $util_pct = (float) ($port['util_pct'] ?? 0.0);
            $util_w   = ($state === 'gray') ? 0 : min(10, (int) round($util_pct / 10));

            $dot  = (new CDiv())->addClass('sw-sfp-dot sw-sfp-dot-' . $s);
            $card = (new CDiv())->addClass('sw-sfp sw-sfp-' . $s);
            $card->addItem($dot);

            $util_fill = (new CDiv())->addClass('sw-ubf sw-ubf-' . $s . ' sw-ubw' . $util_w);
            $util_bar  = (new CDiv())->addClass('sw-ub');
            $util_bar->addItem($util_fill);

            $pw = (new CDiv())->addClass('sw-pw');
            $pw->addItem($card);
            $pw->addItem($util_bar);
            $pw->addItem((new CDiv((string) $pos))->addClass('sw-num sw-num-' . $s));
            $pw->addItem((new CDiv($alias))->addClass('sw-alias'));
            if ($port !== null && method_exists($pw, 'setHint')) {
                $pw->setHint($make_tip($pos, $port, $alias), '', false);
            }
            $sfp_rows[($port_rows > 1 && $i % 2 === 0) ? 1 : 0]->addItem($pw);
        }

        $sfp_block = (new CDiv())->addClass('sw-sfp-block');
        foreach ($sfp_rows as $row) {
            $sfp_block->addItem($row);
        }
        $port_area->addItem($sfp_block);
    }

    // ── Summary bar ───────────────────────────────────────────────────────────
    $up = 0; $dn = 0;
    foreach ($ports as $p) {
        ($p['status'] ?? '') === 'down' ? $dn++ : $up++;
    }
    $sum_rows = [['Ports', $up . ' up / ' . $dn . ' dn']];
    if (!empty($summary['uptime']))      $sum_rows[] = ['Up',    $summary['uptime']];
    if (!empty($summary['model']))       $sum_rows[] = ['Model', $summary['model']];
    if (!empty($summary['serial']))      $sum_rows[] = ['S/N',   $summary['serial']];
    if (!empty($summary['cpu']))         $sum_rows[] = ['CPU',   $summary['cpu'] . '%'];
    if (!empty($summary['temperature'])) $sum_rows[] = ['Temp',  $summary['temperature'] . '°C'];
    $sum_rows[] = ['At', date('H:i:s')];

    $sum = (new CDiv())->addClass('sw-sum');
    foreach ($sum_rows as [$k, $v]) {
        $sum->addItem((new CSpan($k . ': '))->addClass('sw-sum-k'));
        $sum->addItem((new CSpan($v))->addClass('sw-sum-v'));
    }

    $chassis_children[] = $hdr;
    $chassis_children[] = $port_area;
    $chassis_children[] = $sum;
}

// ── Assemble: chassis fully built, then added to outer ───────────────────────
$chassis = (new CDiv())->addClass('sw-chassis');
foreach ($chassis_children as $child) {
    $chassis->addItem($child);
}

$outer = (new CDiv())->addClass('sw-outer');
$outer->addItem($chassis);

(new CWidgetView($data))->addItem(new CTag('style', true, $css))->addItem($outer)->show();
