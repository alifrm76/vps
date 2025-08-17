<?php
/**
 * Virtualizor Hourly Billing - Snapshot Cron
 * Suggested crons:
 * 57 * * * * php -q /path/to/whmcs/modules/servers/virtualizor_hourly/cron/snapshot.php >/dev/null 2>&1
 * 3  0 * * * php -q /path/to/whmcs/modules/servers/virtualizor_hourly/cron/snapshot.php >/dev/null 2>&1
 */
declare(strict_types=1);

chdir(dirname(__FILE__, 3));
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../lib/VirtualizorAdminClient.php';

use WHMCS\Database\Capsule;

function vz_api_from_server($serverId): VirtualizorAdminClient {
    $s = Capsule::table('tblservers')->where('id', $serverId)->first();
    if (!$s) throw new RuntimeException('Server not found: '.$serverId);
    $host = $s->hostname ?: $s->ipaddress;
    $key  = $s->username;
    $pass = $s->password;
    $port = (int)($s->port ?: 4085);
    return new VirtualizorAdminClient($host, $key, $pass, $port, true, true);
}

function ensureSnapshotSchema() {
    $schema = Capsule::schema();
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
ensureSnapshotSchema();

$services = Capsule::table('tblhosting as h')
    ->join('tblproducts as p','p.id','=','h.packageid')
    ->where('h.domainstatus','Active')
    ->where('p.servertype','virtualizor_hourly')
    ->select('h.id as serviceid','h.userid as clientid','h.server as serverid','h.username as vpsid')
    ->get();

$now = time();

foreach ($services as $row) {
    try {
        $sid = (int)$row->serviceid;
        $cid = (int)$row->clientid;
        $srv = (int)$row->serverid;
        $vid = ctype_digit((string)$row->vpsid) ? (int)$row->vpsid : 0;
        if (!$srv || !$vid) continue;

        $api = vz_api_from_server($srv);

        $st  = $api->request('vs', [], ['vs_status' => $vid]);
        $rec = (is_array($st) && isset($st[$vid])) ? $st[$vid] : [];
        $usedGB = (float)($rec['used_bandwidth'] ?? 0.0);

        Capsule::table('mod_vz_hourly_snapshots')->insert([
            'service_id'        => $sid,
            'client_id'         => $cid,
            'vpsid'             => $vid,
            'snap_ts'           => $now,
            'used_bandwidth_gb' => $usedGB,
        ]);

    } catch (Throwable $e) {
        logActivity("[virtualizor_hourly snapshot] service {$row->serviceid} error: ".$e->getMessage(), 0);
    }
}
