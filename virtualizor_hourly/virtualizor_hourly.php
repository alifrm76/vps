<?php
/**
 * Virtualizor Hourly - WHMCS Server Module (Provisioner)
 * Version: 1.3.0
 * WHMCS 8+
 *
 * Server settings (WHMCS > System Settings > Servers):
 * - Hostname/IP = Virtualizor Master
 * - Username    = Admin API Key
 * - Password    = Admin API Pass
 * - Port        = 4085 (Admin API default)
 *
 * Product Custom Field required: "VPSID" (text) to store Virtualizor vpsid.
 */

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/VirtualizorAdminClient.php';

/* ==================== Meta ==================== */

function virtualizor_hourly_MetaData()
{
    return [
        'DisplayName'       => 'Virtualizor Hourly (Admin API)',
        'APIVersion'        => '1.3',
        'RequiresServer'    => true,
        'DefaultNonSSLPort' => '4085',
        'DefaultSSLPort'    => '4085',
    ];
}

function virtualizor_hourly_ConfigOptions()
{
    return [
        'Virtualization Type (virt)' => [
            'Type' => 'dropdown',
            'Options' => [
                'kvm' => 'KVM',
                'xcp' => 'XCP-ng',
                'xen' => 'Xen',
                'lxc' => 'LXC',
                'openvz7' => 'OpenVZ 7',
                'vzo' => 'Virtuozzo',
            ],
            'Default' => 'kvm',
        ],
        'Plan ID (plid)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '',
            'Description' => 'Optional. If set, resources are taken from this Virtualizor plan.',
        ],
        'OS Template ID (osid)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '',
            'Description' => 'Optional; only for initial Create. Rebuild reads live list from server.',
        ],
        'Storage ID (stid) or UUID' => [
            'Type' => 'text', 'Size' => '36', 'Default' => '',
        ],
        'Server Group ID (server_group)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '',
        ],
        'Slave Server ID (serid)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '',
        ],
        'IPv4 Count (num_ips)' => [
            'Type' => 'text', 'Size' => '4', 'Default' => '1',
        ],
        'RAM (MB)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '2048',
        ],
        'CPU Cores' => [
            'Type' => 'text', 'Size' => '5', 'Default' => '2',
        ],
        'Disk (GB)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '30',
        ],
        'Bandwidth (GB)' => [
            'Type' => 'text', 'Size' => '10', 'Default' => '0',
            'Description' => '0 = unlimited',
        ],
        'Enable VNC' => [
            'Type' => 'yesno', 'Default' => '',
        ],
        'Verify SSL' => [
            'Type' => 'yesno', 'Default' => 'on',
        ],
        // Hourly bandwidth billing
        'Hourly Bandwidth Billing (enable)' => [
            'Type' => 'yesno', 'Default' => '',
            'Description' => 'Enable credit deduction per GB consumed (via cron/hourly.php).',
        ],
        'Price per GB' => [
            'Type' => 'text', 'Size' => '8', 'Default' => '0.10',
        ],
        'Suspend if Credit Below (amount)' => [
            'Type' => 'text', 'Size' => '8', 'Default' => '0.00',
        ],
    ];
}

/* ==================== Schema bootstrap ==================== */

function vihoz_ensureSchema(): void
{
    try {
        $schema = Capsule::schema();

        if (!$schema->hasTable('mod_vz_hourly_state')) {
            $schema->create('mod_vz_hourly_state', function($t){
                $t->integer('service_id')->unsigned()->primary();
                $t->decimal('last_bw_used_gb', 12, 6)->default(0);
                $t->integer('last_check_ts')->unsigned()->default(0);
                $t->enum('last_status', ['active','suspended','unknown'])->default('unknown');
            });
        }

        if (!$schema->hasTable('mod_vz_hourly_ledger')) {
            $schema->create('mod_vz_hourly_ledger', function($t){
                $t->bigIncrements('id');
                $t->integer('service_id')->unsigned();
                $t->integer('client_id')->unsigned();
                $t->integer('vpsid')->unsigned()->nullable();
                $t->decimal('delta_gb', 12, 6);
                $t->decimal('price_per_gb', 12, 6);
                $t->decimal('debit_amount', 12, 6);
                $t->decimal('client_credit_after', 12, 6)->nullable();
                $t->dateTime('checked_at');
                $t->string('note', 255)->nullable();
                $t->index(['service_id','checked_at']);
            });
        }

        if (!$schema->hasTable('mod_vz_hourly_snapshots')) {
            $schema->create('mod_vz_hourly_snapshots', function($t){
                $t->bigIncrements('id');
                $t->integer('service_id')->unsigned();
                $t->integer('client_id')->unsigned();
                $t->integer('vpsid')->unsigned();
                $t->integer('snap_ts')->unsigned();
                $t->decimal('used_bandwidth_gb', 12, 6);
                $t->index(['service_id','snap_ts']);
            });
        }
    } catch (\Throwable $e) {
        logModuleCall('virtualizor_hourly', 'ensureSchema', [], $e->getMessage(), null, []);
    }
}

/* ==================== Test Connection ==================== */

function virtualizor_hourly_TestConnection(array $params)
{
    vihoz_ensureSchema();
    try {
        $api = vihoz_api($params);
        $res = $api->request('servers', [], []);
        if (isset($res['servers'])) {
            return ['success' => true];
        }
        $msg = $res['error'] ?? 'Unknown response';
        return ['error' => 'Virtualizor API failed: ' . (is_string($msg) ? $msg : json_encode($msg))];
    } catch (\Throwable $e) {
        return ['error' => 'Exception: ' . $e->getMessage()];
    }
}

/* ==================== Provision Actions ==================== */

function virtualizor_hourly_CreateAccount(array $params)
{
    try {
        $api = vihoz_api($params);

        $clientEmail = $params['clientsdetails']['email'] ?? null;
        $firstName   = $params['clientsdetails']['firstname'] ?? '';
        $lastName    = $params['clientsdetails']['lastname'] ?? '';
        $serviceId   = (int)$params['serviceid'];
        if (!$clientEmail) return 'Missing client email';

        // 1) کاربر Virtualizor را بساز/پیدا کن و UID بگیر
        $uid = vihoz_findOrCreateUser($api, $clientEmail, vihoz_randomPass(16), $firstName, $lastName);

        // 2) پارامترها
        $cfg   = vihoz_collectCreateParams($params);
        $virt  = $cfg['virt'];
        $osid  = (int)$cfg['osid'];
        $plid  = $cfg['plid'] ? (int)$cfg['plid'] : null;
        $stid  = $cfg['stid'];
        $sgid  = $cfg['server_group'] ? (int)$cfg['server_group'] : null;
        $serid = $cfg['serid'] ? (int)$cfg['serid'] : null;
        $numIp = (int)$cfg['num_ips'];

        // اگر از تب سرویس پلن انتخاب شده، اولویت با آن
        $servicePlid = vihoz_getServiceMeta($serviceId, 'VZ_PLANID');
        if ($servicePlid !== null && $servicePlid !== '') {
            $plid = (int)$servicePlid;
        }

        $hostname = $params['domain'] ?: ('vps' . $serviceId . '.local');
        $rootpass = $params['password'] ?: vihoz_randomPass(18);

        // 3) پست addvs – نکته مهم: addvps=1 و uid ضروری!
        $post = [
            'addvps'    => 1,          // ← لازم
            'virt'      => $virt,
            'uid'       => (int)$uid,  // ← لازم
            'hostname'  => $hostname,
            'rootpass'  => $rootpass,
        ];
        if ($osid > 0) $post['osid'] = $osid;

        if ($plid) {
            $post['plid'] = $plid;
        } else {
            $post['ram']       = (int)$cfg['ram'];
            $post['bandwidth'] = (int)$cfg['bandwidth'];
            $post['cores']     = (int)$cfg['cores'];
            $spaceGB = (int)$cfg['disk'];
            if ($spaceGB > 0) {
                $post['space'] = [$spaceGB];
                if (!empty($stid)) $post['stid'] = $stid;
            }
        }

        if ($serid) {
            $post['node_select'] = $serid;
        } elseif ($sgid) {
            $post['server_group'] = $sgid;
        }

        if ($numIp > 0) $post['num_ips'] = $numIp;

        if (!empty($cfg['vnc'])) {
            $post['vnc']     = 1;
            $post['vncpass'] = vihoz_randomPass(10);
        }

        // 4) درخواست
        $res = $api->request('addvs', $post, []);
        logModuleCall('virtualizor_hourly', 'CreateAccount(addvs)', $post, $res, null, []);
        

        // 5) بررسی خطا
        if (!empty($res['error'])) {
            return 'Virtualizor error: ' . (is_string($res['error']) ? $res['error'] : json_encode($res['error']));
        }

        // 6) استخراج vpsid از انواع خروجی
        $vpsid = null;
        if (isset($res['vpsinfo']['vpsid']))           $vpsid = (int)$res['vpsinfo']['vpsid'];
        elseif (isset($res['vs_info']['vpsid']))       $vpsid = (int)$res['vs_info']['vpsid'];
        elseif (isset($res['newvs']) && is_scalar($res['newvs']))          $vpsid = (int)$res['newvs'];
        elseif (isset($res['newvs']) && is_array($res['newvs'])) { // گاهی آرایه برمی‌گردد
            $first = reset($res['newvs']);
            if (is_scalar($first)) $vpsid = (int)$first;
        }
        // 7) اگر هنوز هم vpsid پیدا نشد، fallback: آخرین VPS کاربر با همین hostname را پیدا کن
        if (!$vpsid) {
            $list = $api->request('listvs', [], ['uid' => (int)$uid, 'reslen' => 50]);
            logModuleCall('virtualizor_hourly', 'CreateAccount(listvs-fallback)', ['uid'=>$uid,'hostname'=>$hostname], $list, null, []);
            if (!empty($list['vs']) && is_array($list['vs'])) {
                foreach ($list['vs'] as $id => $v) {
                    $hn = $v['hostname'] ?? $v['vps_name'] ?? '';
                    if ($hn === $hostname) { $vpsid = (int)$id; break; }
                }
            }
        }

        if (!$vpsid) {
            return 'Cannot find vpsid in response';
        }

        // 8) ذخیره در سرویس
        vihoz_setServiceVpsId($serviceId, $vpsid);

        // IP و پسورد در سرویس
        if (!empty($res['vpsinfo']['ips']) && is_array($res['vpsinfo']['ips'])) {
            $firstIp = reset($res['vpsinfo']['ips']);
            vihoz_updateServiceRow($serviceId, [
                'dedicatedip' => $firstIp,
                'username'    => (string)$vpsid,
                'password'    => $rootpass,
            ]);
        } else {
            vihoz_updateServiceRow($serviceId, [
                'username' => (string)$vpsid,
                'password' => $rootpass,
            ]);
        }

        return 'success';

    } catch (\Throwable $e) {
        logModuleCall('virtualizor_hourly', 'CreateAccountException', [], $e->getMessage(), null, []);
        return 'Exception: ' . $e->getMessage();
    }
}


function virtualizor_hourly_SuspendAccount(array $params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';
        $res = $api->request('vs', [], ['suspend' => (int)$vid]);
        return (!empty($res['done']) || empty($res['error'])) ? 'success' : 'Error suspending: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function virtualizor_hourly_UnsuspendAccount(array $params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';
        $res = $api->request('vs', [], ['unsuspend' => (int)$vid]);
        return (!empty($res['done']) || empty($res['error'])) ? 'success' : 'Error unsuspending: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function virtualizor_hourly_TerminateAccount(array $params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';
        $res = $api->request('vs', [], ['delete' => (int)$vid]);
        if (empty($res['error'])) {
            vihoz_setServiceVpsId((int)$params['serviceid'], '');
            vihoz_updateServiceRow((int)$params['serviceid'], ['dedicatedip'=>'','username'=>'','password'=>'']);
            return 'success';
        }
        return 'Error deleting: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function virtualizor_hourly_ChangePackage(array $params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';

        $cfg = vihoz_collectCreateParams($params);
        $servicePlid = vihoz_getServiceMeta((int)$params['serviceid'], 'VZ_PLANID');
        $plid = $servicePlid !== null && $servicePlid !== '' ? (int)$servicePlid : (!empty($cfg['plid']) ? (int)$cfg['plid'] : 0);

        $post = ['vpsid' => (int)$vid];
        if ($plid > 0) {
            $post['plid'] = $plid;
        } else {
            $post['ram']       = (int)$cfg['ram'];
            $post['cores']     = (int)$cfg['cores'];
            $post['bandwidth'] = (int)$cfg['bandwidth'];
            $spaceGB = (int)$cfg['disk'];
            if ($spaceGB > 0) $post['space'] = [$spaceGB];
        }

        $res = $api->request('managevps', $post, ['vpsid' => (int)$vid]);
        return (empty($res['error'])) ? 'success' : 'Error: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function virtualizor_hourly_ChangePassword(array $params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';

        $newpass = $params['password'];
        if (!$newpass) return 'Missing new password';

        $res = $api->request('managevps', ['vpsid'=>(int)$vid,'rootpass'=>$newpass], ['vpsid'=>(int)$vid]);
        if (empty($res['error'])) {
            vihoz_updateServiceRow((int)$params['serviceid'], ['password' => $newpass]);
            return 'success';
        }
        return 'Error: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

/* ==================== Client / Admin UI ==================== */

function virtualizor_hourly_ClientArea(array $params)
{
    vihoz_ensureSchema();

    $api = null;
    $vid = vihoz_getVpsId($params);
    $status = []; $stats = []; $osList = []; $error = null;

    try {
        $api = vihoz_api($params);
        if ($vid) {
            // Use vs_status for live info & bandwidth
            $vs = $api->request('vs', [], ['vs_status' => (int)$vid]);
            $row = is_array($vs) && isset($vs[$vid]) ? $vs[$vid] : [];
            $status = ['vs_status' => [
                'power' => isset($row['status']) ? (int)$row['status'] : 0,
                'cpu'   => (float)($row['used_cpu'] ?? 0) * 100, // adjust if already percent
                'ram'   => (float)($row['used_ram'] ?? 0),
                'space' => (float)($row['used_disk'] ?? 0),
            ]];
            $stats = [
                'bandwidth' => [
                    'used'  => (float)($row['used_bandwidth'] ?? 0),
                    'limit' => (float)($row['bandwidth'] ?? 0),
                ],
            ];
            $osList = vihoz_fetchOsList($api, $params);
        }
    } catch (\Throwable $e) { $error = $e->getMessage(); }

    $assetsBase   = '/modules/servers/virtualizor_hourly/assets';
    $assetVersion = '1.3.0';

    return [
        'templatefile' => 'clientarea/home',
        'vars' => [
            'error'        => $error,
            'vpsid'        => $vid,
            'status'       => $status,
            'stats'        => $stats,
            'serviceid'    => (int)$params['serviceid'],
            'assetsBase'   => $assetsBase,
            'assetVersion' => $assetVersion,
            'modulelink'   => 'clientarea.php?action=productdetails&id='.(int)$params['serviceid'],
            'osList'       => $osList,
            'liveUrl'      => 'clientarea.php?action=productdetails&id='.(int)$params['serviceid'].'&modop=custom&a=LiveStats&ajax=1',
            'liveInterval' => 8000,

            // ✅ جدید برای نمودار تاریخچه مصرف
            'historyUrl'        => 'clientarea.php?action=productdetails&id='.(int)$params['serviceid'].'&modop=custom&a=UsageHistory&ajax=1',
            'historyDefaultDays'=> 30,
        ],
        'metadata' => ['display_name' => 'Virtualizor Cloud'],
    ];
}


function virtualizor_hourly_ClientAreaCustomButtonArray()
{
    return [
        'Start'        => 'Start',
        'Stop'         => 'Stop',
        'Restart'      => 'Restart',
        'Poweroff'     => 'Poweroff',
        'Rebuild OS'   => 'Rebuild',
        'Open Panel (SSO)' => 'SSO',
    ];
}

function virtualizor_hourly_AdminCustomButtonArray()
{
    return [
        'Reset Bandwidth' => 'ResetBandwidth',
    ];
}

/* ==================== Admin Services Tab: Dynamic Plans ==================== */

function virtualizor_hourly_AdminServicesTabFields(array $params)
{
    vihoz_ensureSchema();

    $error   = null;
    $options = [];
    $current = '';

    try {
        $api  = vihoz_api($params);
        $cfg  = vihoz_collectCreateParams($params);
        $virt = $cfg['virt'] ?? 'kvm';

        $plans = vihoz_fetchPlans($api, $virt);
        foreach ($plans as $pl) { $options[$pl['id']] = $pl['label']; }

        $current = vihoz_getServiceMeta((int)$params['serviceid'], 'VZ_PLANID') ?: '';
        if ($current === '' && !empty($cfg['plid'])) {
            $current = (string)$cfg['plid'];
        }

        if (isset($_POST['vz_planid_save'])) {
            $newPlid = trim($_POST['vz_planid'] ?? '');
            vihoz_setServiceMeta((int)$params['serviceid'], 'VZ_PLANID', $newPlid);
            $current = $newPlid;
        }

    } catch (\Throwable $e) { $error = $e->getMessage(); }

    $html  = '';
    if ($error) {
        $html .= '<div class="alert alert-danger" style="margin-bottom:10px;">'.htmlspecialchars($error).'</div>';
    }

    if ($options) {
        $html .= '<form method="post" style="margin:0;">';
        $html .= '<div class="form-group">';
        $html .= '<label><strong>Virtualizor Plan</strong></label>';
        $html .= '<select name="vz_planid" class="form-control" style="max-width:420px;">';
        foreach ($options as $id => $label) {
            $sel = ((string)$current === (string)$id) ? 'selected' : '';
            $html .= '<option value="'.htmlspecialchars($id).'" '.$sel.'>'.htmlspecialchars($label).'</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<button class="btn btn-primary" name="vz_planid_save" value="1">Save Plan</button>';
        $html .= '</form>';
        $html .= '<p class="help-block" style="margin-top:8px;">Saved per-service and used on Create/ChangePackage.</p>';
    } else {
        $html .= '<div class="alert alert-warning">No plans fetched from Virtualizor.</div>';
    }

    return [
        'Virtualizor Plan' => $html,
    ];
}

/* ==================== Custom Actions ==================== */

function virtualizor_hourly_Start($params)    { return vihoz_power($params, 'start'); }
function virtualizor_hourly_Stop($params)     { return vihoz_power($params, 'stop'); }
function virtualizor_hourly_Restart($params)  { return vihoz_power($params, 'restart'); }
function virtualizor_hourly_Poweroff($params) { return vihoz_power($params, 'poweroff'); }

function virtualizor_hourly_Rebuild($params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';

        $selectedOs = (int)($_REQUEST['osid'] ?? $_REQUEST['new_osid'] ?? 0);
        if ($selectedOs <= 0) return 'OS template is required';

        $res = $api->request('rebuild', [
            'vpsid'   => (int)$vid,
            'osid'    => $selectedOs,
            'rebuild' => 1,
        ], []);
        return empty($res['error']) ? 'success' : 'Error: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function virtualizor_hourly_ResetBandwidth($params)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';
        $res = $api->request('vs', [], ['bwreset' => (int)$vid]);
        return empty($res['error']) ? 'success' : 'Error: ' . json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function virtualizor_hourly_SSO($params)
{
    try {
        $api = vihoz_api($params);
        $res = $api->request('sso', [], []);
        if (!empty($res['url'])) { header('Location: '.$res['url']); exit; }
        if (is_string($res) && preg_match('~^https?://~i', $res)) { header('Location: '.$res); exit; }
        return 'Unable to generate SSO link';
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

/**
 * Live JSON endpoint for client UI
 * URL: clientarea.php?action=productdetails&id=SID&modop=custom&a=LiveStats&ajax=1
 */
function virtualizor_hourly_LiveStats(array $params)
{
    try {
        if (!isset($_GET['ajax'])) return 'Direct access not allowed';

        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) vihoz_send_json(['ok'=>false,'error'=>'Missing VPSID'], 400);

        $vs  = $api->request('vs', [], ['vs_status' => (int)$vid]);
        $row = is_array($vs) && isset($vs[$vid]) ? $vs[$vid] : [];

        $out = [
            'ok'    => true,
            'ts'    => time(),
            'power' => isset($row['status']) ? (int)$row['status'] : 0,
            'cpu'   => (float)($row['used_cpu'] ?? 0) * 100,
            'ram'   => (float)($row['used_ram'] ?? 0),
            'disk'  => (float)($row['used_disk'] ?? 0),
            'bw'    => [
                'used'  => (float)($row['used_bandwidth'] ?? 0),
                'limit' => (float)($row['bandwidth'] ?? 0),
            ],
        ];
        vihoz_send_json($out, 200);
    } catch (\Throwable $e) {
        vihoz_send_json(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

/* ==================== Helpers ==================== */

function vihoz_api(array $params): VirtualizorAdminClient
{
    $host   = $params['serverhostname'] ?: $params['serverip'];
    $key    = $params['serverusername'];
    $pass   = $params['serverpassword'];
    $port   = (int)($params['serverport'] ?: 4085);
    $verify = !empty($params['configoption']['Verify SSL']) || !empty($params['configoptions']['Verify SSL']);

    if (!$host || !$key || !$pass) {
        throw new \RuntimeException('Virtualizor server credentials are missing');
    }
    return new VirtualizorAdminClient($host, $key, $pass, $port, true, $verify);
}

function vihoz_getVpsId(array $params)
{
    $cf = $params['customfields'] ?? [];
    foreach ($cf as $k => $v) {
        if (strtolower($k) === 'vpsid' && !empty($v)) return (int)$v;
    }
    if (!empty($params['username']) && ctype_digit((string)$params['username'])) {
        return (int)$params['username'];
    }
    return null;
}

function vihoz_setServiceVpsId(int $serviceId, $vpsid)
{
    try {
        $pid = Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');
        if (!$pid) return;

        $field = Capsule::table('tblcustomfields')
            ->where('type', 'product')->where('relid', $pid)
            ->where('fieldname', 'LIKE', 'VPSID%')->first();

        if ($field) {
            $existing = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $field->id)->where('relid', $serviceId)->first();

            if ($existing) {
                Capsule::table('tblcustomfieldsvalues')->where('id', $existing->id)->update(['value' => $vpsid]);
            } else {
                Capsule::table('tblcustomfieldsvalues')->insert([
                    'fieldid' => $field->id, 'relid' => $serviceId, 'value' => $vpsid
                ]);
            }
        }
    } catch (\Throwable $e) {
        logModuleCall('virtualizor_hourly', 'setServiceVpsId', ['sid'=>$serviceId,'vpsid'=>$vpsid], $e->getMessage(), null, []);
    }
}

function vihoz_updateServiceRow(int $serviceId, array $fields)
{
    try {
        Capsule::table('tblhosting')->where('id', $serviceId)->update($fields);
    } catch (\Throwable $e) {
        logModuleCall('virtualizor_hourly', 'updateServiceRow', $fields, $e->getMessage(), null, []);
    }
}

function vihoz_randomPass($len = 16)
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^*-_=+';
    $str = '';
    for ($i=0; $i<$len; $i++) {
        $str .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return $str;
}

// جایگزین کل تابع قبلی کن
function vihoz_collectCreateParams(array $params): array
{
    // WHMCS: configoptionN (1-based)
    $coN = [];
    foreach ($params as $k => $v) {
        if (preg_match('/^configoption(\d+)$/', $k, $m)) {
            $coN[(int)$m[1]] = $v;
        }
    }
    $coNamed = $params['configoptions'] ?? []; // (configurable options) نه الزاما نیاز داریم

    // ترتیب بر اساس ConfigOptions که خودمان تعریف کرده‌ایم:
    // 1 virt, 2 plid, 3 osid, 4 stid, 5 server_group, 6 serid, 7 num_ips, 8 ram, 9 cores, 10 disk, 11 bandwidth, 12 vnc, 13 verify ssl, 14 hourly enable, 15 price/GB, 16 suspend threshold
    $getN = function(int $n, $default='') use ($coN) {
        return array_key_exists($n, $coN) ? $coN[$n] : $default;
    };

    return [
        'virt'        => strtolower((string)($getN(1, 'kvm') ?: 'kvm')),
        'plid'        => (string)$getN(2, ''),
        'osid'        => (string)$getN(3, ''),
        'stid'        => (string)$getN(4, ''),
        'server_group'=> (string)$getN(5, ''),
        'serid'       => (string)$getN(6, ''),
        'num_ips'     => (int)($getN(7, 1) ?: 1),
        'ram'         => (int)($getN(8, 2048) ?: 2048),
        'cores'       => (int)($getN(9, 2) ?: 2),
        'disk'        => (int)($getN(10, 30) ?: 30),
        'bandwidth'   => (int)($getN(11, 0) ?: 0),
        'vnc'         => (int)!empty($getN(12, '')),
        // باقی‌ها در جای دیگر استفاده می‌شوند
    ];
}


function vihoz_power(array $params, string $action)
{
    try {
        $api = vihoz_api($params);
        $vid = vihoz_getVpsId($params);
        if (!$vid) return 'Missing VPSID';

        $res = $api->request('vs', [], ['action'=>$action,'vpsid'=>(int)$vid]);
        return (empty($res['error'])) ? 'success' : 'Error: '.json_encode($res);
    } catch (\Throwable $e) { return 'Exception: ' . $e->getMessage(); }
}

function vihoz_fetchOsList(VirtualizorAdminClient $api, array $params): array
{
    $cfg  = vihoz_collectCreateParams($params);
    $virt = $cfg['virt'] ?? 'kvm';

    try {
        $res = $api->request('ostemplates', ['virt' => $virt], []);
        if (!empty($res['ostemplates']) && is_array($res['ostemplates'])) {
            $out = [];
            foreach ($res['ostemplates'] as $row) {
                $out[] = [
                    'osid'   => (int)($row['id'] ?? $row['osid'] ?? 0),
                    'name'   => (string)($row['name'] ?? $row['os_name'] ?? 'OS'),
                    'distro' => $row['distro'] ?? ($row['type'] ?? null),
                    'arch'   => $row['arch'] ?? null,
                ];
            }
            if ($out) return $out;
        }
    } catch (\Throwable $e) {}

    try {
        $res = $api->request('os', ['virt' => $virt], []);
        if (!empty($res['os']) && is_array($res['os'])) {
            $out = [];
            foreach ($res['os'] as $row) {
                $out[] = [
                    'osid'   => (int)($row['id'] ?? $row['osid'] ?? 0),
                    'name'   => (string)($row['name'] ?? $row['os_name'] ?? 'OS'),
                    'distro' => $row['distro'] ?? null,
                    'arch'   => $row['arch'] ?? null,
                ];
            }
            if ($out) return $out;
        }
    } catch (\Throwable $e) {}

    try {
        $res = $api->request('media', ['virt' => $virt], []);
        if (!empty($res['ostemplates']) && is_array($res['ostemplates'])) {
            $out = [];
            foreach ($res['ostemplates'] as $row) {
                $out[] = [
                    'osid'   => (int)($row['id'] ?? 0),
                    'name'   => (string)($row['name'] ?? 'OS'),
                    'distro' => $row['distro'] ?? null,
                    'arch'   => $row['arch'] ?? null,
                ];
            }
            if ($out) return $out;
        }
    } catch (\Throwable $e) {}

    return [];
}

function vihoz_fetchPlans(VirtualizorAdminClient $api, string $virt): array
{
    try {
        $res = $api->request('plans', ['virt' => $virt], []);
        if (!empty($res['plans']) && is_array($res['plans'])) {
            $out = [];
            foreach ($res['plans'] as $plid => $row) {
                $name = $row['plan_name'] ?? $row['name'] ?? ('Plan #'.$plid);
                $out[] = ['id' => (string)$plid, 'label' => $name];
            }
            if ($out) return $out;
        }
    } catch (\Throwable $e) {}

    try {
        $res = $api->request('addvs', ['virt' => $virt], []);
        if (!empty($res['plans']) && is_array($res['plans'])) {
            $out = [];
            foreach ($res['plans'] as $plid => $name) {
                $out[] = ['id' => (string)$plid, 'label' => is_string($name)?$name:('Plan #'.$plid)];
            }
            if ($out) return $out;
        }
    } catch (\Throwable $e) {}

    try {
        $res = $api->request('managevps', ['virt' => $virt], []);
        if (!empty($res['plans']) && is_array($res['plans'])) {
            $out = [];
            foreach ($res['plans'] as $plid => $name) {
                $out[] = ['id' => (string)$plid, 'label' => is_string($name)?$name:('Plan #'.$plid)];
            }
            if ($out) return $out;
        }
    } catch (\Throwable $e) {}

    return [];
}

function vihoz_findOrCreateUser(VirtualizorAdminClient $api, string $email, string $pass, string $fname, string $lname)
{
    $u = $api->request('users', [], ['page'=>1,'reslen'=>200]);
    if (!empty($u['users'])) {
        foreach ($u['users'] as $uid => $info) {
            if (!empty($info['email']) && strtolower($info['email']) === strtolower($email)) {
                return (int)$uid;
            }
        }
    }

    $res = $api->request('adduser', [
        'adduser'  => 1, 'priority' => 0,
        'newpass'  => $pass, 'newemail' => $email,
        'fname'    => $fname, 'lname'   => $lname,
    ], []);
    if (!empty($res['done'])) return (int)$res['done'];

    $u2 = $api->request('users', [], ['page'=>1,'reslen'=>200]);
    if (!empty($u2['users'])) {
        foreach ($u2['users'] as $uid => $info) {
            if (!empty($info['email']) && strtolower($info['email']) === strtolower($email)) {
                return (int)$uid;
            }
        }
    }
    throw new \RuntimeException('Failed to create/fetch Virtualizor user: ' . json_encode($res));
}

function vihoz_getServiceMeta(int $serviceId, string $key)
{
    try {
        $pid = Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');
        if (!$pid) return null;

        $field = Capsule::table('tblcustomfields')
            ->where('type','product')->where('relid',$pid)
            ->where('fieldname','LIKE','VZ_META%')->first();
        if (!$field) return null;

        $valRow = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid',$field->id)->where('relid',$serviceId)->first();
        if (!$valRow || $valRow->value === '') return null;

        $j = json_decode($valRow->value, true);
        if (!is_array($j)) return null;
        return $j[$key] ?? null;

    } catch (\Throwable $e) { return null; }
}

function vihoz_setServiceMeta(int $serviceId, string $key, $value): void
{
    try {
        $pid = Capsule::table('tblhosting')->where('id', $serviceId)->value('packageid');
        if (!$pid) return;

        $field = Capsule::table('tblcustomfields')
            ->where('type','product')->where('relid',$pid)
            ->where('fieldname','LIKE','VZ_META%')->first();

        if (!$field) {
            $id = Capsule::table('tblcustomfields')->insertGetId([
                'type'      => 'product',
                'relid'     => $pid,
                'fieldname' => 'VZ_META',
                'fieldtype' => 'textarea',
                'description' => 'Virtualizor per-service metadata (JSON)',
                'required'  => 0, 'adminonly' => 1, 'showorder' => 0, 'showinvoice' => 0,
            ]);
            $field = (object)['id'=>$id];
        }

        $valRow = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid',$field->id)->where('relid',$serviceId)->first();

        $data = [];
        if ($valRow && $valRow->value) {
            $j = json_decode($valRow->value, true);
            if (is_array($j)) $data = $j;
        }
        $data[$key] = $value;

        if ($valRow) {
            Capsule::table('tblcustomfieldsvalues')->where('id',$valRow->id)->update([
                'value' => json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        } else {
            Capsule::table('tblcustomfieldsvalues')->insert([
                'fieldid' => $field->id,
                'relid'   => $serviceId,
                'value'   => json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
        }
    } catch (\Throwable $e) {
        logModuleCall('virtualizor_hourly', 'setServiceMeta', ['sid'=>$serviceId,'key'=>$key,'val'=>$value], $e->getMessage(), null, []);
    }
}

function vihoz_send_json(array $data, int $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function virtualizor_hourly_UsageHistory(array $params)
{
    try {
        if (!isset($_GET['ajax'])) return 'Direct access not allowed';
        $sid = (int)$params['serviceid'];

        $from = isset($_GET['from']) ? strtotime($_GET['from']) : 0;
        $to   = isset($_GET['to'])   ? strtotime($_GET['to'])   : 0;

        // پیش‌فرض: ۳۰ روز اخیر
        if (!$from || !$to || $to <= $from) {
            $to = time();
            $from = strtotime('-30 days', $to);
        }

        $rows = \WHMCS\Database\Capsule::table('mod_vz_hourly_ledger')
            ->where('service_id', $sid)
            ->whereBetween('checked_at', [date('Y-m-d H:i:s',$from), date('Y-m-d H:i:s',$to)])
            ->orderBy('checked_at','asc')
            ->get(['checked_at','delta_gb','debit_amount','price_per_gb','client_credit_after']);

        $points = [];
        $sumGb = 0.0; $sumAmt = 0.0;
        foreach ($rows as $r) {
            $ts = strtotime($r->checked_at);
            $gb = (float)$r->delta_gb;
            $amt= (float)$r->debit_amount;
            $sumGb  += $gb;
            $sumAmt += $amt;
            $points[] = [
                't' => $ts * 1000,            // ms برای JS
                'gb' => round($gb, 6),
                'amt'=> round($amt, 6),
                'ppg'=> round((float)$r->price_per_gb, 6),
                'credit_after' => isset($r->client_credit_after) ? (float)$r->client_credit_after : null,
            ];
        }

        vihoz_send_json([
            'ok' => true,
            'from' => $from*1000,
            'to' => $to*1000,
            'total_gb' => round($sumGb, 6),
            'total_amount' => round($sumAmt, 6),
            'points' => $points,
        ], 200);

    } catch (\Throwable $e) {
        vihoz_send_json(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

