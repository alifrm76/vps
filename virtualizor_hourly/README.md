# Virtualizor Hourly (WHMCS Server Module)

Features:
- Provision via Virtualizor **Admin API**.
- Dynamic **Plans** dropdown in Admin Service tab (reads from Virtualizor).
- Client UI with **live** CPU/RAM/Disk rings and Bandwidth bar (no page refresh).
- **Rebuild OS** with live OS list from Virtualizor.
- **Hourly bandwidth billing** using cron (per GB), auto **Suspend/Unsuspend** on credit threshold.
- Midnight **snapshot** cron to avoid end-of-month loss at reset.

## Install

1. Copy this folder to:
   `modules/servers/virtualizor_hourly/`

2. In WHMCS **Servers**, create a server using this module's server type:
   - Hostname/IP = Virtualizor Master
   - Username = Admin API Key
   - Password = Admin API Pass
   - Port = 4085
   - Test Connection

3. In the **Product** using this module, create a **Custom Field** (Product level):
   - Name: `VPSID`
   - Type: Text

4. Product Module Settings:
   - Set virt/plid/osid or leave plid empty to use manual resources
   - Enable **Hourly Bandwidth Billing**, set **Price per GB** and **Suspend if Credit Below**.

5. Database tables:
   - Will be created automatically when you open Server Test / ClientArea / Admin tab
   - Or run SQL in `install/schema.sql` manually.

6. Crons:
```
*/10 * * * * php -q /path/to/whmcs/modules/servers/virtualizor_hourly/cron/hourly.php >/dev/null 2>&1
57 * * * *   php -q /path/to/whmcs/modules/servers/virtualizor_hourly/cron/snapshot.php >/dev/null 2>&1
3  0 * * *   php -q /path/to/whmcs/modules/servers/virtualizor_hourly/cron/snapshot.php >/dev/null 2>&1
```

Notes:
- Bandwidth usage is fetched from `act=vs&vs_status=VPSID` → `used_bandwidth` (GB) and `bandwidth` (GB).
- Delta logic protects against monthly reset; snapshot cron recovers late-night usage before reset.
