/**
 * GYTAI-77 M4 报价矩阵浏览器验收（补齐 Q/C 矩阵缺口）。
 *
 * 覆盖：
 *  - Q-003 反向：手工折扣 <1 且未填理由 → INVALID_INPUT 阻断；
 *  - Q-003 正向：0.85 + 理由 → 公司审批，可提交；提交按钮在途禁用（防重复提交）；
 *  - Q-005：国际客户 USD 报价 → 汇率换算总额 + 详情价格快照展示定价/报价币种与汇率；
 *  - Q-006：无策略维度组合（欧洲客户）→ POLICY_MISSING 且提示缺失维度；
 *  - Q-007：多行取最严格审批级别（无折扣 + 产线折扣 → 产线审批）；
 *  - Q-008：策略发布新版本后，已提交报价冻结快照不变、新试算用新价；
 *  - 乐观锁冲突：两个编辑会话并发保存 → 后者收到乐观锁冲突提示；
 *  - 页面状态：列表空态；销售角色详情不泄露成本/公司控制价。
 *
 * 运行：NODE_PATH=runtime/temp/cpq_browser/node/node_modules node tests/cpq/browser/m4_quote_matrix_check.js
 * 依赖：demo 数据（含 GYTAI-77 段）、admin（Admin@123456）、cpq_sales（Appr@123456）。
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const BASE = process.env.CPQ_BASE || 'http://127.0.0.1:8082/azbMYFuJTB.php';
const ADMIN_PASSWORD = process.env.CPQ_ADMIN_PASSWORD || 'Admin@123456';
const SALES_PASSWORD = process.env.CPQ_APPROVER_PASSWORD || 'Appr@123456';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = __dirname + '/../../../runtime/temp/cpq_browser/shots';
// 客户 ID（demo.sql GYTAI-77 段：NA=9 国际北美 / EU=10 国际欧洲 / Q8=11 国内专属）
const CUSTOMER_NA = '9', CUSTOMER_EU = '10', CUSTOMER_Q8 = '11';
const results = [];
function check(condition, name, detail) {
    results.push({ok: !!condition, name, detail: detail || ''});
    console.log(condition ? '[PASS]' : '[FAIL]', name, detail || '');
}
async function open(page, path) { await page.goto(BASE + path, {waitUntil: 'networkidle0', timeout: 30000}); }
async function shot(page, name) { await page.screenshot({path: SHOTS + '/' + name + '.png', fullPage: true}); }
async function login(page, username, password) {
    await open(page, '/index/login');
    const ret = await page.evaluate(async (u, p, url) => {
        const pairs = ['username=' + encodeURIComponent(u), 'password=' + encodeURIComponent(p), 'keeplogin=1'];
        const token = document.querySelector('input[name=__token__]');
        if (token) { pairs.push('__token__=' + encodeURIComponent(token.value)); }
        const resp = await fetch(url, {
            method: 'POST', credentials: 'include',
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
    if (cookies.length) { await page.deleteCookie.apply(page, cookies); }
}
async function api(page, method, path, body) {
    return page.evaluate(async (m, u, b, base) => {
        const init = {method: m, headers: {'X-Requested-With': 'XMLHttpRequest'}};
        if (b) {
            init.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            init.body = b;
        }
        // 用脚本顶部 BASE 直拼，绕开 location.pathname（详情/wizard 子路径下会被拼到 controller 之后）
        const baseUrl = base.replace(/\/+$/, '');
        const url = u.indexOf(baseUrl) === 0 ? u : (baseUrl + '/' + u.replace(/^\//, ''));
        const resp = await fetch(url, init);
        const text = await resp.text();
        try { return JSON.parse(text); } catch (e) { return {code: resp.status, msg: text.slice(0, 200)}; }
    }, method, path, body || null, BASE);
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
    page.on('response', r => { if (r.status() >= 500) errors.push('HTTP ' + r.status() + ' ' + r.url()); });

    // 记录草稿保存响应中的报价 ID
    let draftId = 0;
    async function watchDraft(p) {
        p.on('response', async r => {
            if (r.url().indexOf('cpq/quote/save') !== -1 && r.status() === 200) {
                try {
                    const json = await r.json();
                    if (json && json.data && json.data.payload && json.data.payload.id) {
                        draftId = json.data.payload.id;
                    }
                } catch (e) { /* ignore */ }
            }
        });
    }

    // 参数化向导：走到第 4 步完成试算。lines: [{discount, reason}]
    async function wizardToPricing(tag, opts) {
        const currency = opts.currency || 'CNY';
        const customerId = opts.customer || '1';
        draftId = 0;
        await open(page, '/cpq/quote/wizard');
        await page.waitForSelector('#cpq-quote-header', {timeout: 15000});
        await page.evaluate((quoteName, cur, cust) => {
            $('[name="name"]').val(quoteName);
            $('[name="product_line"]').val('DEMO-LINE').selectpicker('refresh');
            $('[name="currency"]').val(cur).selectpicker('refresh');
            $('[name="customer_id"]').val(cust);
            $('[name="company"]').val('DEMO公司');
        }, 'E2E矩阵 ' + tag, currency, customerId);
        await page.click('#cpq-next');

        await page.waitForSelector('.wizard-panel[data-step="2"].active', {timeout: 10000});
        for (let i = 0; i < opts.lineCount; i++) {
            await page.evaluate(() => { $('#cpq-pick-model').val('1'); $('#cpq-pick-quantity').val('1'); });
            await page.click('#cpq-add-line');
            await page.waitForFunction((n) => document.querySelectorAll('#cpq-lines-table tbody tr:not(.cpq-lines-empty)').length === n, {timeout: 15000}, i + 1);
        }

        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="3"].active', {timeout: 10000});
        await page.waitForFunction(() => document.querySelectorAll('.cpq-config-line .cfg-group').length > 0, {timeout: 20000});
        await page.evaluate(() => {
            $('input[name$="-quantity"]').val('1').trigger('change');
            $('input[name$="-features"][value="monitoring"]').prop('checked', true).trigger('change');
        });
        await page.waitForFunction((n) => document.querySelectorAll('.cpq-line-issues .label-success').length >= n, {timeout: 20000}, opts.lineCount);

        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="4"].active', {timeout: 10000});
        // 清空 toast 容器防止多行校验 toast 覆盖 recalc 按钮（puppeteer page.click 会命中 toast）
        await page.evaluate(() => { $('#toast-container,.toast-message').remove(); });
        if (opts.lines) {
            await page.evaluate((lines) => {
                $('#cpq-price-lines .line-card').each(function (index) {
                    var line = lines[index] || {};
                    if (line.discount !== undefined) {
                        $(this).find('input').eq(0).val(line.discount).trigger('change');
                    }
                    $(this).find('input').eq(1).val(line.reason || '').trigger('change');
                });
            }, opts.lines);
        }
        // 用原生 click 绕过 toast 遮挡与视口位置问题
        await page.evaluate(() => document.getElementById('cpq-recalc').click());
        await page.waitForFunction(() => !document.querySelector('#cpq-price-summary').classList.contains('hidden') || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'), {timeout: 25000});
        return await page.evaluate(() => ({
            total: $('#cpq-sum-total').text().trim(),
            approval: $('#cpq-sum-approval').text().trim(),
            submittable: $('#cpq-sum-submittable').text().trim(),
            error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text()
        }));
    }

    // 第 6 步提交；观测提交按钮在途禁用（从第 4/5 步连续下一步到第 6 步）
    async function wizardSubmit(expectOk) {
        for (let i = 0; i < 3; i++) {
            const active = await page.evaluate(() => $('#cpq-steps li.active').data('step'));
            if (String(active) === '6') { break; }
            await page.click('#cpq-next');
            await new Promise(r => setTimeout(r, 300));
        }
        await page.waitForSelector('.wizard-panel[data-step="6"].active', {timeout: 10000});
        await page.evaluate(() => { $('#toast-container,.toast-message').remove(); });
        await page.evaluate(() => document.getElementById('cpq-submit').click());
        await page.waitForSelector('.layui-layer-btn0', {timeout: 10000});
        await page.evaluate(() => document.querySelector('.layui-layer-btn0').click());
        let sawDisabled = false;
        for (let i = 0; i < 20; i++) {
            sawDisabled = await page.evaluate(() => document.getElementById('cpq-submit').disabled === true);
            if (sawDisabled || document.querySelector('.toast-success')) { break; }
            await new Promise(r => setTimeout(r, 50));
        }
        await page.waitForFunction(() =>
            document.querySelector('.toast-success') !== null || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'),
            {timeout: 30000});
        const outcome = await page.evaluate(() => ({
            ok: document.querySelector('.toast-success') !== null,
            error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text()
        }));
        return {outcome, sawDisabled};
    }

    await login(page, 'admin', ADMIN_PASSWORD);
    watchDraft(page);

    // ---------- Q-003 反向：折扣 <1 未填理由 ----------
    let price = await wizardToPricing('Q003n', {customer: CUSTOMER_Q8, lineCount: 1, lines: [{discount: '0.85', reason: ''}]});
    check(price.error.indexOf('CPQ_PRICE_INVALID_INPUT') !== -1 && price.error.indexOf('理由') !== -1,
        'Q-003 反向：折扣未填理由被 INVALID_INPUT 阻断', price.error.slice(0, 160));
    check((await page.evaluate(() => $('#cpq-steps li.error').data('step'))) === 4, 'Q-003 反向：错误定位第 4 步');
    await shot(page, 'q003n-reason-required');

    // ---------- Q-003 正向：0.85 + 理由 → 公司审批 ----------
    await page.evaluate(() => {
        $('#cpq-price-lines .line-card').eq(0).find('input').eq(1).val('浏览器矩阵验收 Q-003').trigger('change');
    });
    await page.evaluate(() => { $('#toast-container,.toast-message').remove(); });
    await page.evaluate(() => document.getElementById('cpq-recalc').click());
    await page.waitForFunction(() => $('#cpq-sum-approval').text().trim() !== '—' || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'), {timeout: 25000});
    price = await page.evaluate(() => ({
        total: $('#cpq-sum-total').text().trim(),
        approval: $('#cpq-sum-approval').text().trim(),
        submittable: $('#cpq-sum-submittable').text().trim(),
        error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text()
    }));
    check(price.approval === '公司审批' && price.submittable === '允许', 'Q-003 正向：0.85 命中公司审批', JSON.stringify(price));
    await shot(page, 'q003p-company-approval');
    let submitted = await wizardSubmit(true);
    check(submitted.outcome.ok, 'Q-003 正向：提交成功', submitted.outcome.error.slice(0, 160));
    check(submitted.sawDisabled, '防重复提交：提交在途按钮禁用');
    await shot(page, 'q003p-submitted');

    // ---------- Q-005：国际客户 USD 报价 ----------
    price = await wizardToPricing('Q005', {customer: CUSTOMER_NA, currency: 'USD', lineCount: 1, lines: [{discount: '', reason: ''}]});
    check(price.error === '', 'Q-005 国际客户试算成功', price.error.slice(0, 160));
    check(price.total === '16971.8309', 'Q-005 USD 换算总额（goods 16901.4084 + fees 70.4225，汇率 0.14084507）', price.total);
    check(price.approval === '无需审批', 'Q-005 无折扣无需审批', price.approval);
    await shot(page, 'q005-usd-trial');
    submitted = await wizardSubmit(true);
    check(submitted.outcome.ok, 'Q-005 USD 报价提交成功', submitted.outcome.error.slice(0, 160));
    const q5Id = draftId;
    check(q5Id > 0, 'Q-005 报价 ID 已捕获', 'id=' + q5Id);

    await open(page, '/cpq/quote/detail/ids/' + q5Id);
    await page.waitForSelector('#cpq-snapshot-lines tr', {timeout: 15000});
    const q5detail = await page.evaluate(() => ({
        currency: $('#cpq-snapshot-currency').text(),
        rows: $('#cpq-snapshot-lines').text(),
        totals: $('#cpq-snapshot-totals').text()
    }));
    check(q5detail.currency.indexOf('CNY') !== -1 && q5detail.currency.indexOf('USD') !== -1 && q5detail.currency.indexOf('汇率') !== -1,
        'Q-005 详情快照展示定价/报价币种与汇率', q5detail.currency.trim());
    check(q5detail.totals.indexOf('16971.8309') !== -1, 'Q-005 详情快照含税总额 16971.8309 USD', q5detail.totals.trim());
    check(q5detail.rows.indexOf('0.14084507') !== -1 || q5detail.currency.indexOf('0.14084507') !== -1, 'Q-005 汇率快照值 0.14084507');
    await shot(page, 'q005-detail-snapshot');

    // ---------- Q-006：欧洲客户无策略维度 ----------
    price = await wizardToPricing('Q006', {customer: CUSTOMER_EU, currency: 'EUR', lineCount: 1, lines: [{discount: '', reason: ''}]});
    check(price.error.indexOf('CPQ_PRICE_POLICY_MISSING') !== -1, 'Q-006 策略缺失错误码', price.error.slice(0, 120));
    check(price.error.indexOf('缺失维度组合') !== -1 && price.error.indexOf('CPQ-DEMO-REGION-EU') !== -1 && price.error.indexOf('international') !== -1,
        'Q-006 提示缺失维度（区域/市场）', price.error.slice(0, 220));
    check((await page.evaluate(() => $('#cpq-steps li.error').data('step'))) === 4, 'Q-006 错误定位第 4 步');
    await shot(page, 'q006-policy-missing');

    // ---------- Q-007：多行最严格审批 ----------
    price = await wizardToPricing('Q007', {
        customer: CUSTOMER_Q8, lineCount: 2,
        lines: [{discount: '', reason: ''}, {discount: '0.93', reason: '浏览器矩阵验收 Q-007 第二行折扣'}]
    });
    check(price.error === '' && price.approval === '产线审批', 'Q-007 多行取最严格审批（none+line → line）', JSON.stringify(price));
    await shot(page, 'q007-strictest');
    submitted = await wizardSubmit(true);
    check(submitted.outcome.ok, 'Q-007 多行报价提交成功', submitted.outcome.error.slice(0, 160));

    // ---------- Q-008：发布新策略版本，冻结快照不变 ----------
    price = await wizardToPricing('Q008', {customer: CUSTOMER_Q8, lineCount: 1, lines: [{discount: '', reason: ''}]});
    check(price.total === '136165.0000', 'Q-008 v1 策略试算 136165.0000', price.total);
    submitted = await wizardSubmit(true);
    check(submitted.outcome.ok, 'Q-008 报价提交成功（冻结快照）', submitted.outcome.error.slice(0, 160));
    const q8Id = draftId;

    await open(page, '/cpq/quote/detail/ids/' + q8Id);
    await page.waitForSelector('#cpq-snapshot-lines tr', {timeout: 15000});
    let q8detail = await page.evaluate(() => $('#cpq-snapshot-totals').text());
    check(q8detail.indexOf('136165.0000') !== -1, 'Q-008 提交后快照总额 136165.0000', q8detail.trim());
    await shot(page, 'q008-detail-before-publish');

    // 策略生命周期：复制 v2 → 改指导价 130000 → 发布（覆盖 v1）
    const published = await api(page, 'GET', 'cpq/price_policy/index?search=CPQ-DEMO-POLICY-Q8&sort=version&order=asc&offset=0&limit=20');
    const v1 = (published.rows || []).find(r => r.code === 'CPQ-DEMO-POLICY-Q8' && String(r.version) === '1');
    check(!!v1 && v1.status === 'published', 'Q-008 v1 策略当前为 published', v1 ? v1.status : '未找到');
    const copied = await api(page, 'POST', 'cpq/price_policy/copy', 'ids=' + v1.id);
    let v2Id;
    if (copied && copied.code === 1 && copied.data && copied.data.version === 2) {
        v2Id = copied.data.id;
        check(true, 'Q-008 复制生成 v2 草稿', 'v2Id=' + v2Id);
    } else {
        // 幂等：上轮测试遗留的草稿，直接拿来用
        const draftRows2 = await api(page, 'GET', 'cpq/price_policy/index?search=CPQ-DEMO-POLICY-Q8&status=draft&sort=version&order=desc&offset=0&limit=10');
        const existV2 = (draftRows2.rows || []).find(r => r.code === 'CPQ-DEMO-POLICY-Q8' && String(r.version) === '2');
        if (existV2) {
            v2Id = existV2.id;
            check(true, 'Q-008 复用已有 v2 草稿（幂等）', 'v2Id=' + v2Id);
        } else {
            check(false, 'Q-008 复制生成 v2 草稿', JSON.stringify(copied).slice(0, 120) + ' | rows=' + (draftRows2.rows || []).length);
        }
    }

    const draftRows = await api(page, 'GET', 'cpq/price_policy/index?search=CPQ-DEMO-POLICY-Q8&status=draft&sort=version&order=desc&offset=0&limit=10');
    const v2 = (draftRows.rows || []).find(r => r.code === 'CPQ-DEMO-POLICY-Q8' && String(r.id) === String(v2Id));
    check(!!v2, 'Q-008 v2 草稿可检索', 'rows=' + (draftRows.rows || []).length);
    if (v2) {
        const editBody = 'row[id]=' + encodeURIComponent(v2Id) + '&' + Object.keys(v2)
            .filter(k => ['id', 'createtime', 'updatetime', 'dimension_key'].indexOf(k) === -1)
            .map(k => 'row[' + encodeURIComponent(k) + ']=' + encodeURIComponent(v2[k] === null ? '' : v2[k]))
            .join('&') + '&row[guide_price]=130000';
        const edited = await api(page, 'POST', 'cpq/price_policy/edit/ids/' + v2Id, editBody);
        check(edited && edited.code === 1, 'Q-008 v2 指导价改 130000', JSON.stringify(edited).slice(0, 120));
    }

    // 版本化主数据发布需先提交审批（draft → pending），再发布（pending → published）
    const submittedPolicy = await api(page, 'POST', 'cpq/price_policy/submit', 'ids=' + v2Id);
    check(submittedPolicy && submittedPolicy.code === 1, 'Q-008 v2 提交审批', JSON.stringify(submittedPolicy).slice(0, 120));

    const release = await api(page, 'POST', 'cpq/price_policy/publish', 'ids=' + v2Id + '&change_summary=' + encodeURIComponent('Q-008 验收发布 v2'));
    check(release && release.code === 1, 'Q-008 v2 发布成功', JSON.stringify(release).slice(0, 120));

    // 旧报价冻结快照不变；新试算用新价
    await open(page, '/cpq/quote/detail/ids/' + q8Id);
    await page.waitForSelector('#cpq-snapshot-lines tr', {timeout: 15000});
    q8detail = await page.evaluate(() => $('#cpq-snapshot-totals').text());
    check(q8detail.indexOf('136165.0000') !== -1, 'Q-008 发布 v2 后旧报价快照仍 136165.0000（冻结不变）', q8detail.trim());
    await shot(page, 'q008-detail-after-publish');

    price = await wizardToPricing('Q008b', {customer: CUSTOMER_Q8, lineCount: 1, lines: [{discount: '', reason: ''}]});
    // 指导价只参与三层控制价分级（定价基础价来自价格表条目，不随策略发布变化）：
    // v2 指导价 130000 使基础价 120000 落入「产线审批」，总额仍为 136165.0000。
    check(price.error === '' && price.total === '136165.0000' && price.approval === '产线审批',
        'Q-008 新试算命中 v2 指导价 130000（120000 < 指导价 → 产线审批，总额不变 136165）', JSON.stringify(price));
    const states = await api(page, 'GET', 'cpq/price_policy/index?search=CPQ-DEMO-POLICY-Q8&sort=version&order=asc&offset=0&limit=20');
    const v1After = (states.rows || []).find(r => r.code === 'CPQ-DEMO-POLICY-Q8' && String(r.version) === '1');
    const v2After = (states.rows || []).find(r => r.code === 'CPQ-DEMO-POLICY-Q8' && String(r.version) === '2');
    check(v1After && v1After.status !== 'published' && v2After && v2After.status === 'published',
        'Q-008 v1 被新版本取代', 'v1=' + (v1After ? v1After.status : '?') + ' v2=' + (v2After ? v2After.status : '?'));
    await shot(page, 'q008-new-trial');

    // ---------- 乐观锁冲突提示 ----------
    draftId = 0;
    await wizardToPricing('LOCK', {customer: CUSTOMER_Q8, lineCount: 1, lines: [{discount: '', reason: ''}]});
    const lockDraftId = draftId;
    check(lockDraftId > 0, '乐观锁：草稿已保存', 'id=' + lockDraftId);
    const page2 = await browser.newPage();
    await page2.setViewport({width: 1200, height: 900});
    await page2.goto(BASE + '/cpq/quote/wizard/ids/' + lockDraftId, {waitUntil: 'networkidle0', timeout: 30000});
    await page2.waitForSelector('#cpq-quote-header', {timeout: 15000});
    await page2.evaluate(() => { $('#toast-container,.toast-message').remove(); });
    await page2.evaluate(() => document.getElementById('cpq-save-draft').click()); // 第二会话保存 → 服务端乐观锁 +1
    await page2.waitForFunction(() => document.querySelector('.toast-success') !== null, {timeout: 20000});
    await page.bringToFront();
    await page.evaluate(() => document.getElementById('cpq-save-draft').click()); // 第一会话仍持旧锁 → 冲突
    await page.waitForFunction(() => !document.querySelector('#cpq-wizard-error').classList.contains('hidden'), {timeout: 20000});
    const lockOutcome = await page.evaluate(() => ({
        error: $('#cpq-wizard-error').text(),
        hint: $('#cpq-draft-hint').text()
    }));
    check(lockOutcome.error.indexOf('乐观锁') !== -1, '乐观锁：冲突报错提示', lockOutcome.error.slice(0, 160));
    check(lockOutcome.hint.indexOf('重新进入') !== -1 || lockOutcome.hint.indexOf('已被他人修改') !== -1,
        '乐观锁：草稿提示引导重新进入', lockOutcome.hint.trim());
    await shot(page, 'optimistic-lock-conflict');
    await page2.close();

    // ---------- 列表空态 ----------
    await open(page, '/cpq/quote/index');
    await page.waitForSelector('#cpq-quote-views', {timeout: 15000});
    await page.focus('.fixed-table-toolbar .search input');
    await page.keyboard.type('ZZZ不存在的报价XYZ');
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('.no-records-found') !== null, {timeout: 20000});
    check(true, '列表空态：无匹配时展示空记录提示', await page.evaluate(() => $('.no-records-found').text().trim()));
    await shot(page, 'empty-state');

    // ---------- 销售角色：详情不泄露成本/公司控制价 ----------
    await logout(page);
    await login(page, 'cpq_sales', SALES_PASSWORD);
    const ownQuotes = await api(page, 'GET', 'cpq/quote/index?search=DEMO-M4-Q-OWN&offset=0&limit=5');
    const ownQuote = (ownQuotes.rows || []).find(r => r.code === 'DEMO-M4-Q-OWN');
    check(!!ownQuote, '销售可见自己名下的演示报价', 'total=' + (ownQuotes.total || 0));
    await open(page, '/cpq/quote/detail/ids/' + ownQuote.id);
    await page.waitForSelector('#cpq-snapshot-lines tr', {timeout: 15000});
    const salesDetail = await page.evaluate(() => ({
        snapshot: document.getElementById('cpq-detail-snapshot-panel').textContent,
        controlColHidden: document.querySelector('#cpq-detail-snapshot-panel').classList.contains('cpq-snapshot-no-control')
    }));
    check(salesDetail.snapshot.indexOf('244080.0000') !== -1, '销售可见冻结快照总额', salesDetail.snapshot.trim().slice(0, 160));
    check(salesDetail.snapshot.indexOf('60000') === -1 && salesDetail.snapshot.indexOf('96000') === -1 && salesDetail.snapshot.indexOf('控制单价') === -1,
        '销售详情不泄露成本/公司控制价（60000/96000）', '');
    check(salesDetail.controlColHidden, '销售视图控制单价列隐藏');
    await shot(page, 'sales-no-cost-leak');

    check(errors.length === 0, '页面无 5xx / JavaScript 异常', errors.join(' | ').slice(0, 300));
    await browser.close();
    const failed = results.filter(x => !x.ok);
    console.log('----');
    console.log('PASS ' + (results.length - failed.length) + ' / FAIL ' + failed.length);
    if (failed.length) {
        process.exitCode = 1;
    }
})().catch(async error => {
    console.error(error);
    process.exitCode = 1;
    if (browser) {
        await browser.close();
    }
});
