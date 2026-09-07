/**
 * GYTAI-78 M4 驾驶舱/报表中心浏览器验收（P01、P90-P94）。
 *
 * 主路径：
 *  - P01 驾驶舱首屏一次加载：KPI/漏斗/趋势/分布渲染，业务首屏完成标记置位；
 *  - 统一筛选：查询/重置驱动整页刷新；
 *  - 下钻：KPI/漏斗点击携带规范化筛选跳转报价列表并生效；
 *  - P90-P93 报表页加载与表格渲染（金额为服务端字符串原样展示）；
 *  - P94 导出：创建（POST）→ 轮询 → 一次性领取令牌 → 下载 XLSX。
 *
 * 运行：NODE_PATH=runtime/temp/cpq_browser/node/node_modules node tests/cpq/browser/m4_check.js
 * 依赖：demo 数据与 admin（Admin@123456）。
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const BASE = process.env.CPQ_BASE || 'http://127.0.0.1:8082/azbMYFuJTB.php';
const ADMIN_PASSWORD = process.env.CPQ_ADMIN_PASSWORD || 'Admin@123456';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = __dirname + '/../../../runtime/temp/cpq_browser/shots';
const results = [];
function check(condition, name, detail) {
    results.push({ok: !!condition, name, detail: detail || ''});
    console.log(condition ? '[PASS]' : '[FAIL]', name, detail || '');
}
async function open(page, path) { await page.goto(BASE + path, {waitUntil: 'networkidle0', timeout: 30000}); }
async function shot(page, name) { await page.screenshot({path: SHOTS + '/' + name + '.png', fullPage: true}); }
// 登录：表单提交存在 JS 绑定竞态（点击早于 RequireJS 绑定时会整页 POST，
// URL 停留在 index/login 导致误判超时）。改为页面内 AJAX 登录，
// 与 FastAdmin 登录脚本同源（含 __token__），失败立即抛错而不是干等。
async function login(page, username, password) {
    await open(page, '/index/login');
    const ret = await page.evaluate(async (u, p, url) => {
        const pairs = [
            'username=' + encodeURIComponent(u),
            'password=' + encodeURIComponent(p),
            'keeplogin=1'
        ];
        const token = document.querySelector('input[name=__token__]');
        if (token) { pairs.push('__token__=' + encodeURIComponent(token.value)); }
        const resp = await fetch(url, {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
            body: pairs.join('&')
        });
        return await resp.json();
    }, username, password, BASE + '/index/login');
    if (!ret || ret.code !== 1) {
        throw new Error('登录失败 ' + username + ': ' + JSON.stringify(ret).slice(0, 200));
    }
    await open(page, '/index/index');
    if (page.url().indexOf('index/login') !== -1) {
        throw new Error('登录后仍停留在登录页: ' + username);
    }
}
async function logout(page) {
    const cookies = await page.cookies(BASE);
    if (cookies.length) {
        await page.deleteCookie.apply(page, cookies);
    }
}
// 页面内以登录态调用后台接口（返回 JSON）
async function api(page, method, path, body) {
    return page.evaluate(async (m, u, b) => {
        const init = {method: m, headers: {'X-Requested-With': 'XMLHttpRequest'}};
        if (b) {
            init.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            init.body = b;
        }
        const resp = await fetch(u, init);
        return await resp.json();
    }, method, path, body || null);
}
function formEncode(obj) {
    return Object.keys(obj).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(obj[k])).join('&');
}

let browser;
(async () => {
    fs.mkdirSync(SHOTS, {recursive: true});
    browser = await puppeteer.launch({executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--window-size=1440,1100']});
    const page = await browser.newPage();
    await page.setViewport({width: 1440, height: 1100});
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('response', r => { if (r.status() >= 500) { errors.push('HTTP ' + r.status() + ' ' + r.url()); } });

    await login(page, 'admin', ADMIN_PASSWORD);

    // ------------------------------------------------------------------
    // 1. P01 驾驶舱：首屏一次加载 + 完成标记 + 各区块渲染
    // ------------------------------------------------------------------
    await open(page, '/cpq/dashboard/index');
    await page.waitForFunction(() => window.__CPQ_FIRST_SCREEN_READY__ === true, {timeout: 30000});
    const flagged = await page.evaluate(() => performance.getEntriesByName('cpq-first-screen-ready').length > 0);
    check(flagged, 'P01 业务首屏完成标记（performance.mark + 全局旗标）');
    const dash = await page.evaluate(() => ({
        kpis: document.querySelectorAll('#cpq-kpi-row .cpq-kpi-card').length,
        risks: document.querySelectorAll('#cpq-risk-row .cpq-kpi-card').length,
        charts: Array.from(document.querySelectorAll('[id^=cpq-chart-]')).filter(el => el.querySelector('canvas')).length,
        todos: document.querySelectorAll('#cpq-todo-body tr').length,
        links: document.querySelectorAll('#cpq-quick-links a').length
    }));
    check(dash.kpis >= 6, 'P01 KPI 卡片渲染（≥6）', 'kpis=' + dash.kpis);
    check(dash.risks >= 4, 'P01 价格风险区渲染（≥4）', 'risks=' + dash.risks);
    check(dash.charts >= 6, 'P01 漏斗/趋势/四级分布图表渲染（≥6）', 'charts=' + dash.charts);
    check(dash.todos >= 1, 'P01 我的待审批区块渲染');
    check(dash.links >= 4, 'P01 快捷入口渲染');
    await shot(page, 'm4-dashboard');

    // 筛选表单存在统一七字段
    const filterFields = await page.evaluate(() =>
        ['company', 'sales_org_id', 'product_line', 'region_id', 'currency', 'created_from', 'created_to']
            .every(name => document.querySelector('#cpq-filter-form [name="' + name + '"]') !== null));
    check(filterFields, 'P01 统一筛选七字段齐全');

    // ------------------------------------------------------------------
    // 2. 下钻：KPI「已批准」卡片点击跳转报价列表并携带筛选
    // ------------------------------------------------------------------
    await page.evaluate(() => {
        const cards = Array.from(document.querySelectorAll('#cpq-kpi-row .cpq-kpi-card'));
        const approved = cards.find(c => c.textContent.indexOf('已批准') !== -1);
        if (approved) { approved.click(); }
    });
    await page.waitForFunction(() => location.href.indexOf('cpq/quote/index') !== -1, {timeout: 15000});
    const drillQuery = await page.evaluate(() => location.search);
    check(drillQuery.indexOf('status=approved') !== -1, 'P01 KPI 下钻携带 status=approved', drillQuery);
    await page.waitForFunction(() => document.querySelectorAll('#table tbody tr').length > 0, {timeout: 20000});
    await shot(page, 'm4-dashboard-drilldown');

    // ------------------------------------------------------------------
    // 3. P90 报价漏斗：图表 + 明细表 + 转化率
    // ------------------------------------------------------------------
    await open(page, '/cpq/report_quote/index');
    await page.waitForFunction(() =>
        document.querySelector('#cpq-chart-funnel canvas') !== null
        && document.querySelectorAll('#cpq-funnel-body tr').length >= 6, {timeout: 30000});
    const funnelText = await page.evaluate(() => document.querySelector('#cpq-funnel-body').textContent);
    check(funnelText.indexOf('草稿') !== -1 && funnelText.indexOf('已接受') !== -1, 'P90 漏斗六段明细齐全');
    check(/\d\.\d{6}/.test(funnelText), 'P90 相邻转化率展示（服务端字符串）');
    await shot(page, 'm4-report-quote');

    // ------------------------------------------------------------------
    // 4. P91 折扣与毛利：表格渲染（admin 为 system_admin 可见毛利列）
    // ------------------------------------------------------------------
    await open(page, '/cpq/report_pricing/index');
    await page.waitForFunction(() => document.querySelectorAll('#cpq-pricing-body tr').length > 0, {timeout: 30000});
    const pricing = await page.evaluate(() => ({
        note: document.querySelector('#cpq-pricing-note').textContent,
        marginVisible: document.querySelector('.cpq-col-margin-amount').style.display !== 'none'
    }));
    check(pricing.marginVisible, 'P91 管理员可见毛利列', pricing.note.slice(0, 60));
    await shot(page, 'm4-report-pricing');

    // ------------------------------------------------------------------
    // 5. P92 审批效率：节点/审批人两维
    // ------------------------------------------------------------------
    await open(page, '/cpq/report_approval/index');
    await page.waitForFunction(() => document.querySelectorAll('#cpq-node-body tr').length > 0, {timeout: 30000});
    const nodeText = await page.evaluate(() => document.querySelector('#cpq-node-body').textContent);
    check(/sales_confirm|line_approval|company_approval/.test(nodeText), 'P92 节点维度渲染（按审批节点聚合）');
    await shot(page, 'm4-report-approval');

    // ------------------------------------------------------------------
    // 6. P93 配置分析：top 型号 / 选项频次 / 校验统计
    // ------------------------------------------------------------------
    await open(page, '/cpq/report_configuration/index');
    await page.waitForFunction(() => document.querySelectorAll('#cpq-config-kpi .cpq-kpi-card').length >= 2, {timeout: 30000});
    const configKpis = await page.evaluate(() => document.querySelector('#cpq-config-kpi').textContent);
    check(/配置快照总数/.test(configKpis) && /校验失败数/.test(configKpis), 'P93 校验统计卡片渲染');
    await shot(page, 'm4-report-configuration');

    // ------------------------------------------------------------------
    // 7. P94 导出：创建（POST）→ 轮询 → 领取令牌 → 下载 XLSX
    // ------------------------------------------------------------------
    await open(page, '/cpq/report_export/index');
    await page.waitForSelector('#table', {timeout: 15000});
    const created = await api(page, 'POST', BASE + '/cpq/report_export/create', formEncode({idempotency_key: 'M4-BROWSER-' + Date.now()}));
    check(created.code === 1 && created.data && created.data.job_key, 'P94 创建导出任务（POST）', JSON.stringify(created).slice(0, 120));
    const jobKey = created.data && created.data.job_key;
    let job = null;
    for (let i = 0; i < 30; i++) {
        await new Promise(r => setTimeout(r, 2000));
        const ret = await api(page, 'GET', BASE + '/cpq/report_export/status?job_key=' + encodeURIComponent(jobKey));
        job = ret && ret.data ? ret.data : null;
        if (job && (job.status === 'succeeded' || job.status === 'failed')) { break; }
    }
    check(job && job.status === 'succeeded', 'P94 轮询至任务完成', job ? ('status=' + job.status + ' ' + (job.error_message || '')) : '无状态');
    if (job && job.status === 'succeeded') {
        await page.evaluate(() => { $('#table').bootstrapTable('refresh', {silent: true}); });
        await new Promise(r => setTimeout(r, 1200));
        const claimed = await api(page, 'POST', BASE + '/cpq/report_export/claim', formEncode({job_key: jobKey}));
        check(claimed.code === 1 && claimed.data && claimed.data.token, 'P94 一次性领取下载令牌', JSON.stringify(claimed).slice(0, 120));
        const token = claimed.data && claimed.data.token;
        const download = await page.evaluate(async (u) => {
            const resp = await fetch(u, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            const buf = await resp.arrayBuffer();
            return {status: resp.status, type: resp.headers.get('content-type'), size: buf.byteLength};
        }, BASE + '/cpq/report_export/download?job_key=' + encodeURIComponent(jobKey) + '&token=' + encodeURIComponent(token));
        check(download.status === 200
            && (download.type || '').indexOf('spreadsheetml') !== -1
            && download.size > 512, 'P94 携带令牌下载 XLSX', JSON.stringify(download));
        const replay = await api(page, 'POST', BASE + '/cpq/report_export/claim', formEncode({job_key: jobKey}));
        check(replay.code !== 1, 'P94 令牌领取后不可重复领取', JSON.stringify(replay).slice(0, 100));
        await shot(page, 'm4-report-export');
    }

    // ------------------------------------------------------------------
    // 8. P01 首屏性能：admin 登录预热后连续三次业务首屏均 ≤2000ms
    // ------------------------------------------------------------------
    const perfRuns = [];
    for (let i = 0; i < 3; i++) {
        await open(page, '/cpq/dashboard/index');
        await page.waitForFunction(() => window.__CPQ_FIRST_SCREEN_READY__ === true, {timeout: 30000});
        const ms = await page.evaluate(() => {
            const entries = performance.getEntriesByName('cpq-first-screen-ready');
            return entries.length ? Math.round(entries[0].startTime) : -1;
        });
        perfRuns.push(ms);
    }
    check(perfRuns.every(v => v >= 0 && v <= 2000), 'P01 预热后连续三次业务首屏 ≤2000ms', 'runs(ms)=' + perfRuns.join(','));

    // ------------------------------------------------------------------
    // 9. P104 接口管理（admin）：凭证加密保存、永不回显，页面仅支持重置
    // ------------------------------------------------------------------
    const cfgExisting = await api(page, 'GET', BASE + '/cpq/integration_config/index?keyword=M4-DEMO-CRM&page=1&limit=20');
    let cfgId = cfgExisting && cfgExisting.rows && cfgExisting.rows.length ? cfgExisting.rows[0].id : 0;
    // 凭证不能经 save 写入（doSave 白名单不含 credentials），且服务端校验
    // “新建非 none 认证必须随附凭证”，故正确流程：先以 auth_type=none 保存
    // 基本信息，再 resetcredential 单独写入凭证，最后改回 hmac 并启用。
    const cfgSaved = await api(page, 'POST', BASE + '/cpq/integration_config/save', formEncode({
        id: cfgId,
        'row[code]': 'M4-DEMO-CRM',
        'row[name]': 'M4演示CRM接口',
        'row[system_type]': 'crm',
        'row[base_url]': 'https://crm.example.invalid',
        'row[auth_type]': 'none',
        'row[hmac_algorithm]': 'sha256',
        'row[timeout_ms]': '1200',
        'row[max_retries]': '1',
        'row[status]': 'disabled'
    }));
    check(cfgSaved.code === 1, 'P104 保存接口配置基本信息', JSON.stringify(cfgSaved).slice(0, 120));
    cfgId = cfgSaved.data && cfgSaved.data.id ? cfgSaved.data.id : cfgId;
    const credReset = await api(page, 'POST', BASE + '/cpq/integration_config/resetcredential',
        formEncode({id: cfgId, credentials: '{"hmac_secret":"m4-browser-secret"}'}));
    check(credReset.code === 1 && credReset.data && credReset.data.credentials_configured === true
        && Object.keys(credReset.data).length === 1, 'P104 重置凭证仅返回 credentials_configured 布尔');
    const cfgEnabled = await api(page, 'POST', BASE + '/cpq/integration_config/save', formEncode({
        id: cfgId,
        'row[auth_type]': 'hmac',
        'row[hmac_algorithm]': 'sha256',
        'row[status]': 'enabled'
    }));
    check(cfgEnabled.code === 1 && cfgEnabled.data && cfgEnabled.data.auth_type === 'hmac'
        && cfgEnabled.data.status === 'enabled', 'P104 凭证就绪后启用 HMAC 认证', JSON.stringify(cfgEnabled).slice(0, 120));
    const cfgList = await api(page, 'GET', BASE + '/cpq/integration_config/index?keyword=M4-DEMO-CRM&page=1&limit=20');
    const cfgRow = cfgList && cfgList.rows && cfgList.rows.length ? cfgList.rows[0] : null;
    const leakedKeys = cfgRow ? Object.keys(cfgRow).filter(k => k.indexOf('credential') === 0 && k !== 'credentials_configured') : ['<no-row>'];
    check(cfgRow && leakedKeys.length === 0 && cfgRow.credentials_configured === true
        && JSON.stringify(cfgRow).indexOf('m4-browser-secret') === -1,
        'P104 列表 JSON 无任何凭证字段且不回显明文', leakedKeys.join(','));
    await open(page, '/cpq/integration_config/index');
    await page.waitForFunction(() => document.body.textContent.indexOf('重置凭证') !== -1, {timeout: 20000});
    const cfgPageText = await page.evaluate(() => document.body.textContent);
    check(cfgPageText.indexOf('查看原凭证') === -1, 'P104 页面仅提供重置凭证、无查看原凭证入口');
    await shot(page, 'm4-integration-config');

    // ------------------------------------------------------------------
    // 10. P106 审计日志（admin）：只读页面，无任何写操作入口
    // ------------------------------------------------------------------
    await open(page, '/cpq/audit_log/index');
    await page.waitForSelector('#table', {timeout: 15000});
    const auditDom = await page.evaluate(() => ({
        add: document.querySelectorAll('.btn-add').length,
        edit: document.querySelectorAll('.btn-edit').length,
        del: document.querySelectorAll('.btn-del').length,
        operate: document.querySelectorAll('[data-operate-edit],[data-operate-del]').length
    }));
    check(auditDom.add + auditDom.edit + auditDom.del + auditDom.operate === 0,
        'P106 审计日志页面无新增/编辑/删除入口', JSON.stringify(auditDom));
    const auditList = await api(page, 'GET', BASE + '/cpq/audit_log/index?page=1&limit=5');
    check(auditList && typeof auditList.total === 'number', 'P106 管理员可查询审计日志', 'total=' + (auditList && auditList.total));
    await shot(page, 'm4-audit-log');

    // ------------------------------------------------------------------
    // 11. P100 数据范围（admin）：演示销售的授权收窄正确
    // ------------------------------------------------------------------
    const scopeList = await api(page, 'GET', BASE + '/cpq/data_scope/index?keyword=cpq_sales&page=1&limit=20');
    const salesRow = scopeList && scopeList.rows ? scopeList.rows.find(r => r.username === 'cpq_sales') : null;
    check(!!salesRow, 'P100 授权列表可见演示销售 cpq_sales');
    if (salesRow) {
        const scopeDetail = await api(page, 'GET', BASE + '/cpq/data_scope/detail?admin_id=' + salesRow.id);
        const eff = scopeDetail && scopeDetail.data && scopeDetail.data.effective ? scopeDetail.data.effective : {};
        check(scopeDetail.code === 1
            && (eff.product_lines || []).indexOf('DEMO-LINE') !== -1
            && (eff.sales_org_ids || []).length >= 1,
            'P100 演示销售数据范围=DEMO-LINE ∩ 华东销售部', JSON.stringify(eff).slice(0, 160));
    }
    await open(page, '/cpq/data_scope/index');
    await page.waitForSelector('#table', {timeout: 15000});
    await shot(page, 'm4-data-scope');

    // ------------------------------------------------------------------
    // 12. 多角色：销售 cpq_sales（owner-only）+ 敏感字段脱敏 + 系统管理拒权
    // ------------------------------------------------------------------
    const DRILL_URL = BASE + '/cpq/quote/index?created_from=2020-01-01&offset=0&limit=100';
    await logout(page);
    await login(page, 'cpq_sales', 'Appr@123456');
    await open(page, '/cpq/dashboard/index');
    await page.waitForFunction(() => window.__CPQ_FIRST_SCREEN_READY__ === true, {timeout: 30000});
    const salesKpis = await page.evaluate(() => document.querySelectorAll('#cpq-kpi-row .cpq-kpi-card').length);
    check(salesKpis >= 6, '多角色 销售驾驶舱按数据范围正常渲染');
    await shot(page, 'm4-dashboard-sales');
    const salesQuote = await api(page, 'GET', DRILL_URL);
    const salesCodes = salesQuote && salesQuote.rows ? salesQuote.rows.map(r => r.code) : [];
    check(salesQuote && salesQuote.total === 1 && salesCodes[0] === 'DEMO-M4-Q-OWN',
        '多角色 销售下钻统一路径仅见本人报价（owner-only）', 'total=' + (salesQuote && salesQuote.total) + ' codes=' + salesCodes.join(','));
    const salesDefault = await api(page, 'GET', BASE + '/cpq/quote/index?offset=0&limit=100');
    const salesDefaultCodes = salesDefault && salesDefault.rows ? salesDefault.rows.map(r => r.code) : [];
    check(salesDefault && salesDefault.total === 1 && salesDefaultCodes[0] === 'DEMO-M4-Q-OWN',
        '多角色 销售默认报价列表同样按数据范围 owner-only 收窄', 'total=' + (salesDefault && salesDefault.total) + ' codes=' + salesDefaultCodes.join(','));
    const salesPricing = await api(page, 'GET', BASE + '/cpq/report_pricing/index');
    const salesPricingRows = salesPricing && salesPricing.data && salesPricing.data.rows ? salesPricing.data.rows : [];
    const sensitiveKeys = ['gross_margin_amount', 'gross_margin_rate', 'cost', 'company_floor'];
    const salesLeak = salesPricingRows.some(r => sensitiveKeys.some(k => Object.prototype.hasOwnProperty.call(r, k)));
    check(salesPricing.code === 1 && salesPricingRows.length >= 1 && !salesLeak,
        '多角色 P91 销售行递归脱敏（无成本/毛利键）', 'rows=' + salesPricingRows.length);
    const salesAudit = await api(page, 'GET', BASE + '/cpq/audit_log/index?page=1&limit=5');
    check(!salesAudit || salesAudit.total === undefined, '多角色 销售访问审计日志被拒');
    const salesCfg = await api(page, 'GET', BASE + '/cpq/integration_config/index?page=1&limit=5');
    check(!salesCfg || salesCfg.total === undefined || salesCfg.code !== 1, '多角色 销售访问接口管理被拒');

    // ------------------------------------------------------------------
    // 13. 多角色：销售经理组织子树 / 审计可读 / 财务可读毛利
    // ------------------------------------------------------------------
    await logout(page);
    await login(page, 'cpq_sales_mgr', 'Appr@123456');
    const mgrQuote = await api(page, 'GET', DRILL_URL);
    const mgrCodes = mgrQuote && mgrQuote.rows ? mgrQuote.rows.map(r => r.code) : [];
    check(mgrQuote && mgrQuote.total >= 2
        && mgrCodes.indexOf('DEMO-M4-Q-OWN') !== -1 && mgrCodes.indexOf('DEMO-M4-Q-ORG') !== -1,
        '多角色 销售经理可见所属组织子树报价', 'total=' + (mgrQuote && mgrQuote.total) + ' codes=' + mgrCodes.join(','));

    await logout(page);
    await login(page, 'cpq_auditor', 'Appr@123456');
    const auditorAudit = await api(page, 'GET', BASE + '/cpq/audit_log/index?page=1&limit=5');
    check(auditorAudit && typeof auditorAudit.total === 'number', '多角色 审计角色可访问审计日志', 'total=' + (auditorAudit && auditorAudit.total));
    const auditorQuote = await api(page, 'GET', DRILL_URL);
    const auditorCodes = auditorQuote && auditorQuote.rows ? auditorQuote.rows.map(r => r.code) : [];
    check(auditorQuote && auditorQuote.total >= 2
        && auditorCodes.indexOf('DEMO-M4-Q-OWN') !== -1 && auditorCodes.indexOf('DEMO-M4-Q-ORG') !== -1,
        '多角色 审计角色全公司只读可见全部演示报价', 'total=' + (auditorQuote && auditorQuote.total) + ' codes=' + auditorCodes.join(','));

    await logout(page);
    await login(page, 'cpq_finance', 'Appr@123456');
    const finPricing = await api(page, 'GET', BASE + '/cpq/report_pricing/index');
    const finRows = finPricing && finPricing.data && finPricing.data.rows ? finPricing.data.rows : [];
    check(finPricing && finPricing.code === 1 && finRows.length >= 1,
        '多角色 财务角色可访问折扣毛利分析（按产品线收窄，全量演示数据）', 'rows=' + finRows.length);
    check(finRows.length >= 1 && finRows.every(r => Object.prototype.hasOwnProperty.call(r, 'gross_margin_amount')
        && Object.prototype.hasOwnProperty.call(r, 'gross_margin_rate')),
        '多角色 P91 财务（全量角色）可见毛利列');

    check(errors.length === 0, '页面无 5xx / JavaScript 异常', errors.join(' | ').slice(0, 300));
    await browser.close();
    if (results.some(x => !x.ok)) {
        process.exitCode = 1;
    }
})().catch(async error => {
    console.error(error);
    process.exitCode = 1;
    if (browser) {
        await browser.close();
    }
});
