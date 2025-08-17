<?php
declare(strict_types=1);
/**
 * Admin AJAX endpoint for Virtualizor plans
 * Actions:
 *   - action=plans      → لیست همه پلن‌ها (id,label)
 *   - action=planLabel  → گرفتن label بر اساس plid
 */
define('ADMINAREA', true);
// Move to the WHMCS root so we can bootstrap the environment
chdir(dirname(__FILE__, 4));
// Now load the WHMCS init file from the root we just chdir'd into
require_once 'init.php';

use WHMCS\Database\Capsule;

session_start();
if (empty($_SESSION['adminid'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit;
}

function out($arr, $code=200){
    if (!headers_sent()){
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

$action    = $_GET['action'] ?? '';
$productId = isset($_GET['productid']) ? (int)$_GET['productid'] : 0;
$plid      = isset($_GET['plid']) ? trim((string)$_GET['plid']) : '';

if (!$productId) out(['ok'=>false,'error'=>'Missing productid'], 400);

$product = Capsule::table('tblproducts')->where('id', $productId)->first();
if (!$product || $product->servertype !== 'virtualizor_hourly') {
    out(['ok'=>false,'error'=>'Product not found or wrong module'], 404);
}

$virt = strtolower($product->configoption1 ?? 'kvm');

// انتخاب یک سرور از گروه یا اولین سرور ماژول
$serverId = 0;
if (!empty($product->servergroup)) {
    $srv = Capsule::table('tblservergroupsrel as r')
        ->join('tblservers as s','s.id','=','r.serverid')
        ->where('r.groupid', $product->servergroup)
        ->where('s.type', 'virtualizor_hourly')
        ->select('s.id')->first();
    if ($srv) $serverId = (int)$srv->id;
}
if ($serverId === 0) {
    $srv = Capsule::table('tblservers')->where('type','virtualizor_hourly')->orderBy('id','asc')->first();
    if ($srv) $serverId = (int)$srv->id;
}
if ($serverId === 0) out(['ok'=>false,'error'=>'No server found for this module'], 404);

// Virtualizor client
require_once __DIR__ . '/../lib/VirtualizorAdminClient.php';
$s = Capsule::table('tblservers')->where('id', $serverId)->first();
$host = $s->hostname ?: $s->ipaddress;
$key  = $s->username;
$pass = $s->password;
$port = (int)($s->port ?: 4085);
$client = new VirtualizorAdminClient($host, $key, $pass, $port, true, true);

// helper: fetch all plans (with fallbacks)
function fetchPlans(VirtualizorAdminClient $client, string $virt): array {
    $plans = [];
    // primary
    try {
        $res = $client->request('plans', ['virt'=>$virt], []);
        if (!empty($res['plans']) && is_array($res['plans'])) {
            foreach ($res['plans'] as $plid => $row) {
                $label = $row['plan_name'] ?? $row['name'] ?? ('Plan #'.$plid);
                $plans[] = ['id'=>(string)$plid,'label'=>$label];
            }
        }
    } catch (Throwable $e) {}
    // fallback 1
    if (!$plans) {
        try {
            $res = $client->request('addvs', ['virt'=>$virt], []);
            if (!empty($res['plans']) && is_array($res['plans'])) {
                foreach ($res['plans'] as $plid => $name) {
                    $plans[] = ['id'=>(string)$plid,'label'=> (is_string($name)?$name:('Plan #'.$plid))];
                }
            }
        } catch (Throwable $e) {}
    }
    // fallback 2
    if (!$plans) {
        try {
            $res = $client->request('managevps', ['virt'=>$virt], []);
            if (!empty($res['plans']) && is_array($res['plans'])) {
                foreach ($res['plans'] as $plid => $name) {
                    $plans[] = ['id'=>(string)$plid,'label'=> (is_string($name)?$name:('Plan #'.$plid))];
                }
            }
        } catch (Throwable $e) {}
    }
    return $plans;
}

try {
    if ($action === 'plans') {
        $plans = fetchPlans($client, $virt);
        out(['ok'=>true,'plans'=>$plans], 200);
    } elseif ($action === 'planLabel') {
        if ($plid === '') out(['ok'=>false,'error'=>'Missing plid'], 400);
        $plans = fetchPlans($client, $virt);
        $label = null;
        foreach ($plans as $p) {
            if ((string)$p['id'] === (string)$plid) { $label = $p['label']; break; }
        }
        out(['ok'=>true,'label'=>$label], 200);
    } else {
        out(['ok'=>false,'error'=>'Bad action'], 400);
    }
} catch (Throwable $e) {
    out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
