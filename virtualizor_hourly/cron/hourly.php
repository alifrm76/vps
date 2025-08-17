<?php
/**
 * Virtualizor Hourly Billing - Cron (every 10 minutes)
 * */10 * * * * php -q /path/to/whmcs/modules/servers/virtualizor_hourly/cron/hourly.php >/dev/null 2>&1
 */
declare(strict_types=1);

chdir(dirname(__FILE__, 3));
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../lib/VirtualizorAdminClient.php';

use WHMCS\Database\Capsule;

$MODULE = 'virtualizor_hourly';
$DEBUG  = false;

function api($action, array $params = []) {
    try { return localAPI($action, $params); }
    catch (Throwable $e) { return ['result'=>'error','message'=>$e->getMessage()]; }
}

function vz_api_from_server($serverId): VirtualizorAdminClient {
    $s = Capsule::table('tblservers')->where('id', $serverId)->first();
    if (!$s) throw new RuntimeException('Server not found: '.$serverId);
    $host = $s->hostname ?: $s->ipaddress;
    $key  = $s->username;
    $pass = $s->password;
    $port = (int)($s->port ?: 4085);
    return new VirtualizorAdminClient($host, $key, $pass, $port, true, true);
}

function ensureSchema() {
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
}

ensureSchema();

$services = Capsule::table('tblhosting as h')
    ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
    ->where('h.domainstatus', 'Active')
    ->where('p.servertype', $MODULE)
    ->select(
        'h.id as serviceid','h.userid as clientid','h.server as serverid','h.username as vpsid','h.packageid as pid',
        'p.configoption1','p.configoption14','p.configoption15','p.configoption16'
    )
    ->get();

$now = time();

foreach ($services as $row) {
    try {
        $serviceId = (int)$row->serviceid;
        $clientId  = (int)$row->clientid;
        $serverId  = (int)$row->serverid;
        $vpsid     = ctype_digit((string)$row->vpsid) ? (int)$row->vpsid : 0;

        $hourlyEnabled  = !empty($row->configoption14);
        $pricePerGB     = (float)($row->configoption15 ?? 0.0);
        $suspendMinCred = (float)($row->configoption16 ?? 0.0);

        if (!$hourlyEnabled || $pricePerGB <= 0 || !$serverId || !$vpsid) {
            if ($DEBUG) echo "[service:$serviceId] skipped\n";
            continue;
        }

        $api = vz_api_from_server($serverId);

        // Use vs_status: used_bandwidth (GB) cumulative in current period
        $st   = $api->request('vs', [], ['vs_status' => $vpsid]);
        $data = (is_array($st) && isset($st[$vpsid])) ? $st[$vpsid] : [];
        $usedGB  = (float)($data['used_bandwidth'] ?? 0.0);
        $limitGB = (float)($data['bandwidth'] ?? 0.0);

        $state = Capsule::table('mod_vz_hourly_state')->where('service_id',$serviceId)->first();
        $lastUsed = $state ? (float)$state->last_bw_used_gb : 0.0;

        $monthNow  = (int)date('Ym', $now);
        $monthPrev = $state ? (int)date('Ym', (int)$state->last_check_ts) : $monthNow;

        $delta = $usedGB - $lastUsed;
        $resetDetected = ($monthNow !== $monthPrev) || ($delta < -0.05); // -50MB

        if ($resetDetected) {
            $snapshot = Capsule::table('mod_vz_hourly_snapshots')
                ->where('service_id', $serviceId)
                ->where('snap_ts', '>=', strtotime('yesterday 23:30'))
                ->where('snap_ts', '<=', strtotime('today 00:30'))
                ->orderBy('snap_ts','desc')->first();

            $unbilled = 0.0;
            if ($snapshot) {
                $snapUsed = (float)$snapshot->used_bandwidth_gb;
                if ($snapUsed > $lastUsed) $unbilled = $snapUsed - $lastUsed;
            }
            $afterReset = max(0.0, $usedGB);
            $delta = $unbilled + $afterReset;
        } else {
            if ($delta < 0 && $delta > -0.01) { $delta = 0.0; } // -10MB noise
        }

        if ($delta <= 0.0001) {
            Capsule::table('mod_vz_hourly_state')->updateOrInsert(
                ['service_id'=>$serviceId],
                ['last_bw_used_gb'=>$usedGB,'last_check_ts'=>$now,'last_status'=>$state->last_status ?? 'unknown']
            );
            if ($DEBUG) echo "[service:$serviceId] no delta\n";
            continue;
        }

        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        $creditBefore = (float)($client->credit ?? 0.0);

        $debit = round($delta * $pricePerGB, 6);

        $desc = sprintf("Virtualizor hourly BW charge (Service #%d, %.4f GB × %.6f)", $serviceId, $delta, $pricePerGB);
        $r = api('AddCredit', [
            'clientid'    => $clientId,
            'amount'      => '-' . number_format($debit, 6, '.', ''),
            'description' => $desc,
            'type'        => 'General',
        ]);
        if (($r['result'] ?? 'error') !== 'success') {
            throw new RuntimeException('AddCredit failed: '.($r['message'] ?? 'unknown'));
        }

        $client2 = Capsule::table('tblclients')->where('id', $clientId)->first();
        $creditAfter = (float)($client2->credit ?? 0.0);

        Capsule::table('mod_vz_hourly_ledger')->insert([
            'service_id'          => $serviceId,
            'client_id'           => $clientId,
            'vpsid'               => $vpsid,
            'delta_gb'            => $delta,
            'price_per_gb'        => $pricePerGB,
            'debit_amount'        => $debit,
            'client_credit_after' => $creditAfter,
            'checked_at'          => date('Y-m-d H:i:s'),
            'note'                => null,
        ]);

        Capsule::table('mod_vz_hourly_state')->updateOrInsert(
            ['service_id'=>$serviceId],
            ['last_bw_used_gb'=>$usedGB,'last_check_ts'=>$now,'last_status'=>$state->last_status ?? 'active']
        );

        $hosting = Capsule::table('tblhosting')->where('id',$serviceId)->first();
        $domainStatus = $hosting ? $hosting->domainstatus : 'Active';

        if ($creditAfter < $suspendMinCred && $domainStatus === 'Active') {
            $sr = api('ModuleSuspend', [
                'accountid' => $serviceId,
                'suspendreason' => 'Insufficient credit for hourly bandwidth',
            ]);
            if (($sr['result'] ?? 'error') === 'success') {
                Capsule::table('mod_vz_hourly_state')->where('service_id',$serviceId)->update(['last_status'=>'suspended']);
                if ($DEBUG) echo "[service:$serviceId] suspended for low credit\n";
            }
        }

        if ($domainStatus === 'Suspended' && $creditAfter >= $suspendMinCred) {
            $ur = api('ModuleUnsuspend', ['accountid' => $serviceId]);
            if (($ur['result'] ?? 'error') === 'success') {
                Capsule::table('mod_vz_hourly_state')->where('service_id',$serviceId)->update(['last_status'=>'active']);
                if ($DEBUG) echo "[service:$serviceId] unsuspended (credit ok)\n";
            }
        }

        if ($DEBUG) echo "[service:$serviceId] delta={$delta}GB debit={$debit}\n";

    } catch (Throwable $e) {
        if ($DEBUG) echo "[service:{$row->serviceid}] ERROR: ".$e->getMessage()."\n";
        logActivity("[{$MODULE} cron] Service {$row->serviceid} error: ".$e->getMessage(), 0);
    }
}
