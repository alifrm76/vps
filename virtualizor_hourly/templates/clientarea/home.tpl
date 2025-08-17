{* Virtualizor - Client UI with live update *}
<link rel="stylesheet" href="{$assetsBase}/css/client.css?v={$assetVersion}">
<script src="{$assetsBase}/js/client.js?v={$assetVersion}"></script>
<script>
window.__VZC_LIVE__ = {
    url: '{$liveUrl|escape:"url"}',
    interval: {$liveInterval|default:8000}
};
</script>

<div class="vzc">
    <div class="vzc-header">
        <div class="vzc-title">
            <span class="vzc-badge">VPS</span>
            <h2>مدیریت سرور مجازی {if $vpsid}#{$vpsid}{/if}</h2>
            <p class="vzc-sub">کنترل پاور، آمار لحظه‌ای منابع، و نصب مجدد سیستم‌عامل</p>
        </div>
        <div class="vzc-actions">
            {if $vpsid}
            <a class="btn vzc-btn vzc-btn-primary" href="{$modulelink}&modop=custom&a=SSO">ورود به پنل (SSO)</a>
            {/if}
        </div>
    </div>

    {if $error}
        <div class="alert alert-danger vzc-alert"><strong>خطا:</strong> {$error}</div>
    {/if}

    {if !$vpsid}
        <div class="vzc-card">
            <div class="vzc-card-body">
                <div class="vzc-empty">
                    <div class="vzc-empty-icon">
                        <svg viewBox="0 0 24 24" width="64" height="64" aria-hidden="true">
                            <path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M3 9h18" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="6" cy="7" r="1" fill="currentColor"/>
                            <circle cx="9" cy="7" r="1" fill="currentColor"/>
                        </svg>
                    </div>
                    <h4>VPSID پیدا نشد</h4>
                    <p>پس از ایجاد سرویس، مقدار <b>VPSID</b> به‌صورت خودکار ثبت می‌شود.</p>
                </div>
            </div>
        </div>
    {else}
        {assign var=s value=$status.vs_status}

        <div class="vzc-grid">
            <!-- Status + Power -->
            <div class="vzc-card vzc-card-glow">
                <div class="vzc-card-header">
                    <h4>وضعیت و کنترل پاور</h4>
                    <span class="vzc-state {if $s.power == 1}on{else}off{/if}">
                        {if $s.power == 1}روشن{else}خاموش{/if}
                    </span>
                </div>
                <div class="vzc-card-body">
                    <div class="vzc-gauges">
                        <div class="vzc-gauge">
                            {assign var=cpu value=$s.cpu|default:0}
                            <svg viewBox="0 0 36 36" class="vzc-ring" data-percent="{$cpu}">
                                <path class="bg" d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32"/>
                                <path class="fg" d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32"/>
                                <text x="18" y="20" class="txt">{$cpu|round:0}%</text>
                            </svg>
                            <div class="vzc-gauge-label">CPU</div>
                        </div>
                        <div class="vzc-gauge">
                            {assign var=ram value=$s.ram|default:0}
                            <svg viewBox="0 0 36 36" class="vzc-ring" data-percent="{$ram}">
                                <path class="bg" d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32"/>
                                <path class="fg" d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32"/>
                                <text x="18" y="20" class="txt">{$ram|round:0}%</text>
                            </svg>
                            <div class="vzc-gauge-label">RAM</div>
                        </div>
                        <div class="vzc-gauge">
                            {assign var=disk value=$s.space|default:0}
                            <svg viewBox="0 0 36 36" class="vzc-ring" data-percent="{$disk}">
                                <path class="bg" d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32"/>
                                <path class="fg" d="M18 2 a 16 16 0 1 1 0 32 a 16 16 0 1 1 0 -32"/>
                                <text x="18" y="20" class="txt">{$disk|round:0}%</text>
                            </svg>
                            <div class="vzc-gauge-label">Disk</div>
                        </div>
                    </div>

                    <div class="vzc-buttons">
                        <form method="post" action="{$modulelink}">
                            <input type="hidden" name="modop" value="custom" />
                            <button class="vzc-btn vzc-btn-success" formaction="{$modulelink}&a=Start">Start</button>
                            <button class="vzc-btn vzc-btn-warning" formaction="{$modulelink}&a=Stop">Stop</button>
                            <button class="vzc-btn vzc-btn-info"    formaction="{$modulelink}&a=Restart">Restart</button>
                            <button class="vzc-btn vzc-btn-danger"  formaction="{$modulelink}&a=Poweroff">Poweroff</button>
                            <a class="vzc-btn vzc-btn-primary" href="{$modulelink}&modop=custom&a=SSO">Open Panel</a>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Bandwidth -->
            <div class="vzc-card">
                <div class="vzc-card-header"><h4>مصرف پهنای‌باند (ماه)</h4></div>
                <div class="vzc-card-body">
                    {assign var=bw value=$stats.bandwidth}
                    {if $bw}
                        {assign var=used value=$bw.used|default:0}
                        {assign var=limit value=$bw.limit|default:0}
                        {assign var=pct value=0}
                        {if $limit > 0}{assign var=pct value=($used*100)/$limit}{/if}

                        <div class="vzc-progress-wrap">
                            <div class="vzc-progress">
                                <div class="vzc-progress-bar" style="width:{if $limit > 0}{$pct|round:0}{else}0{/if}%"></div>
                            </div>
                            <div class="vzc-progress-meta">
                                <span><b>مصرف:</b> {$used} GB</span>
                                <span><b>سقف:</b> {if $limit>0}{$limit} GB{else}نامحدود{/if}</span>
                            </div>
                        </div>
                        <div class="vzc-hint">نوار بالا به صورت زنده به‌روزرسانی می‌شود.</div>
                    {else}
                        <div class="vzc-empty sm"><p>اطلاعات پهنای‌باند در دسترس نیست.</p></div>
                    {/if}
                </div>
            </div>
            <!-- Usage History Chart -->
        <div class="vzc-card vzc-card-glow">
          <div class="vzc-card-header">
            <h4>تاریخچه مصرف و هزینه</h4>
            <div class="vzc-history-range">
              <input type="date" id="vzc-h-from" class="vzc-input">
              <input type="date" id="vzc-h-to" class="vzc-input">
              <button id="vzc-h-apply" class="vzc-btn vzc-btn-primary">اعمال</button>
            </div>
          </div>
          <div class="vzc-card-body">
            <div class="vzc-history-metrics">
              <div><b>مصرف کل:</b> <span id="vzc-h-sum-gb">0.00</span> GB</div>
              <div><b>مبلغ کل:</b> <span id="vzc-h-sum-amt">0.00</span></div>
            </div>
            <div class="vzc-history-chart-wrap">
              <canvas id="vzc-history-canvas" height="120"></canvas>
            </div>
            <div class="vzc-hint">نمودار: هر نقطه = یک اجرای کرون (دلتا مصرف و مبلغ همان بازه)</div>
          </div>
        </div>
        
        {* مقداردهی اولیه بازه *}
        <script>
          window.__VZC_HISTORY__ = {
            url: '{$historyUrl|escape:"url"}',
            days: {$historyDefaultDays|default:30}
          };
        </script>


            <!-- Rebuild -->
            <div class="vzc-card">
                <div class="vzc-card-header"><h4>نصب مجدد سیستم‌عامل (Rebuild)</h4></div>
                <div class="vzc-card-body">
                    {if $osList|@count > 0}
                        <div class="vzc-os-picker">
                            <div class="vzc-os-search">
                                <input type="text" class="form-control vzc-input" id="vzc-os-search" placeholder="جستجو: Ubuntu, Debian, Windows..." />
                            </div>

                            <div class="vzc-os-select-wrap">
                                <form method="post" action="{$modulelink}&modop=custom&a=Rebuild" id="vzc-os-form" class="vzc-form-inline">
                                    <input type="hidden" name="modop" value="custom" />
                                    <div class="form-group">
                                        <label>انتخاب OS</label>
                                        <select name="osid" id="vzc-os-select" class="form-control vzc-input" required>
                                            {foreach from=$osList item=os}
                                                {assign var=label value=$os.name}
                                                {if $os.distro}{assign var=label value=$os.distro|cat:' - '|cat:$os.name}{/if}
                                                {if $os.arch}{assign var=label value=$label|cat:' ('|cat:$os.arch|cat:')'}{/if}
                                                <option value="{$os.osid}" data-name="{$os.name|lower}" data-distro="{$os.distro|lower}" data-arch="{$os.arch|lower}">{$label}</option>
                                            {/foreach}
                                        </select>
                                    </div>
                                    <button class="vzc-btn vzc-btn-outline">Rebuild</button>
                                </form>
                            </div>
                            <div class="vzc-hint">⚠️ هشدار: نصب مجدد تمام اطلاعات دیسک را پاک می‌کند.</div>
                        </div>
                    {else}
                        <div class="vzc-empty sm"><p>لیست OS از سرور دریافت نشد. لطفاً بعداً تلاش کنید.</p></div>
                    {/if}
                </div>
            </div>
        </div>
    {/if}
</div>
