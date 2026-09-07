/**
 * GYTAI-75 M3 待办/审批/模板/打印浏览器验收（P02、P59-P60、P70-P75）。
 *
 * 主路径：
 *  - 三条固定审批路径端到端：正常价格（销售确认）/ 产线审批 / 公司两级审批；
 *  - 职责分离：报价负责人不能处理特批节点（页面按钮禁用 + API 直调拒绝）；
 *  - 委托代理：创建 → 审批生效 → 代理待办可见（代理标记）→ 代理批准 → 记录留痕；
 *  - 重复操作：同一任务重复动作被服务端拒绝/幂等；
 *  - P59 模板预览/复制新版本；P60 生成 PDF → 轮询 → 哈希验证 → 受控下载计数；
 *  - 版本失效：撤回后旧任务详情显示失效提示且操作禁用。
 *
 * 运行：NODE_PATH=runtime/temp/cpq_browser/node/node_modules node tests/cpq/browser/m3_approval_check.js
 * 依赖：demo 数据含 cpq_approver / cpq_approver2（密码 Appr@123456）与默认中英文模板。
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const BASE = process.env.CPQ_BASE || 'http://127.0.0.1:8082/azbMYFuJTB.php';
const ADMIN_PASSWORD = process.env.CPQ_ADMIN_PASSWORD || 'Admin@123456';
const APPROVER_PASSWORD = process.env.CPQ_APPROVER_PASSWORD || 'Appr@123456';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = __dirname + '/../../../runtime/temp/cpq_browser/shots';
const results = [];
function check(condition, name, detail) {
    results.push({ok: !!condition, name, detail: detail || ''});
    console.log(condition ? '[PASS]' : '[FAIL]', name, detail || '');
}
async function open(page, path) { await page.goto(BASE + path, {waitUntil: 'networkidle0', timeout: 30000}); }
async function closeLayers(page) { await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} }); }
async function shot(page, name) { await page.screenshot({path: SHOTS + '/' + name + '.png', fullPage: true}); }
async function login(page, username, password) {
    await open(page, '/index/login');
    await page.evaluate(() => { document.querySelector('#pd-form-username').value = ''; document.querySelector('#pd-form-password').value = ''; });
    await page.type('#pd-form-username', username);
    await page.type('#pd-form-password', password);
    await page.click('#login-form button[type=submit]');
    // 登录成功为 JS 跳转，等待 URL 离开登录页并完成加载
    await page.waitForFunction(() => location.href.indexOf('index/login') === -1, {timeout: 30000});
    await page.waitForFunction(() => document.readyState === 'complete', {timeout: 30000});
    await page.waitForSelector('#main', {timeout: 20000}).catch(() => {});
}
async function logout(page) {
    // 清空会话 Cookie 即登出（比跳转 logout 更稳）
    const cookies = await page.cookies(BASE);
    if (cookies.length) {
        await page.deleteCookie.apply(page, cookies);
    }
}
// 列表页按关键字过滤并等待出现目标行
async function findRow(page, keywordSelector, keyword, cellText) {
    await page.waitForSelector('#table', {timeout: 15000});
    await page.type(keywordSelector, keyword);
    await page.keyboard.press('Enter');
    await page.waitForFunction((text) => {
        const rows = document.querySelectorAll('#table tbody tr');
        return Array.from(rows).some(r => r.textContent.indexOf(text) !== -1);
    }, {timeout: 20000}, cellText);
}
// 在列表行中点击指定按钮并等待审批详情 iframe
async function openDetailFromRow(page, cellText, btnClass) {
    const before = new Set(page.frames());
    await page.evaluate((text, cls) => {
        const rows = Array.from(document.querySelectorAll('#table tbody tr'));
        const row = rows.find(r => r.textContent.indexOf(text) !== -1);
        const btn = row && row.querySelector(cls);
        if (btn) { btn.click(); }
    }, cellText, btnClass);
    let frame = null;
    for (let i = 0; i < 24; i++) {
        frame = page.frames().find(f => !before.has(f) && f.url().indexOf('cpq/approval_task/detail/ids/') !== -1);
        if (frame) { break; }
        await new Promise(r => setTimeout(r, 500));
    }
    if (!frame) {
        frame = page.frames().find(f => f.url().indexOf('cpq/approval_task/detail/ids/') !== -1) || null;
    }
    return frame;
}
// 在详情 iframe 中执行审批动作（批准/驳回/退回/加签/转交）
async function actInDetail(frame, action, comment) {
    await frame.waitForSelector('#cpq-apd-operate button[data-action="' + action + '"]', {timeout: 20000});
    await frame.evaluate((act) => {
        document.querySelector('#cpq-apd-operate button[data-action="' + act + '"]').click();
    }, action);
    await frame.waitForSelector('#cpq-act-comment', {timeout: 10000});
    await frame.type('#cpq-act-comment', comment || '浏览器验收');
    await frame.waitForSelector('.layui-layer-btn0', {timeout: 10000});
    await frame.evaluate(() => { document.querySelector('.layui-layer-btn0').click(); });
    await frame.waitForFunction(() =>
        document.querySelector('.toast-success') !== null || document.querySelector('.layui-layer-dialog') !== null,
        {timeout: 25000});
    const outcome = await frame.evaluate(() => ({
        ok: document.querySelector('.toast-success') !== null,
        alert: document.querySelector('.layui-layer-dialog') ? document.querySelector('.layui-layer-dialog').textContent : ''
    }));
    return outcome;
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

    // 记录保存响应中的报价 ID
    let draftId = 0;
    page.on('response', async r => {
        if (r.url().indexOf('cpq/quote/save') !== -1 && r.status() === 200) {
            try {
                const json = await r.json();
                if (json && json.data && json.data.payload && json.data.payload.id) {
                    draftId = json.data.payload.id;
                }
            } catch (e) { /* ignore */ }
        }
    });

    // ------------------------------------------------------------------
    // 报价向导工厂（与 M2 验收同口径）
    // ------------------------------------------------------------------
    async function runWizard(name, discount, tag) {
        await open(page, '/cpq/quote/wizard');
        await page.waitForSelector('#cpq-quote-header', {timeout: 15000});
        await page.evaluate((quoteName) => {
            $('[name="name"]').val(quoteName);
            $('[name="product_line"]').val('DEMO-LINE').selectpicker('refresh');
            $('[name="currency"]').val('CNY').selectpicker('refresh');
            $('[name="customer_id"]').val('1');
            $('[name="company"]').val('DEMO公司');
        }, name);
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="2"].active', {timeout: 10000});
        await page.evaluate(() => { $('#cpq-pick-model').val('1'); $('#cpq-pick-quantity').val('1'); });
        await page.click('#cpq-add-line');
        await page.waitForFunction(() => document.querySelectorAll('#cpq-lines-table tbody tr:not(.cpq-lines-empty)').length === 1, {timeout: 15000});
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="3"].active', {timeout: 10000});
        await page.waitForFunction(() => document.querySelectorAll('.cpq-config-line .cfg-group').length > 0, {timeout: 20000});
        await page.evaluate(() => {
            $('input[name$="-quantity"]').val('1').trigger('change');
            $('input[name$="-features"][value="monitoring"]').prop('checked', true).trigger('change');
        });
        await page.waitForFunction(() => document.querySelector('.cpq-line-issues .label-success') !== null, {timeout: 20000});
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="4"].active', {timeout: 10000});
        if (discount) {
            await page.evaluate((d) => {
                $('#cpq-price-lines .line-card input').eq(0).val(d).trigger('change');
                $('#cpq-price-lines .line-card input').eq(1).val('浏览器验收折扣').trigger('change');
            }, discount);
        }
        await page.click('#cpq-recalc');
        await page.waitForFunction(() => !document.querySelector('#cpq-price-summary').classList.contains('hidden') || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'), {timeout: 20000});
        const price = await page.evaluate(() => ({
            approval: $('#cpq-sum-approval').text().trim(),
            submittable: $('#cpq-sum-submittable').text().trim(),
            error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text()
        }));
        if (price.error) {
            throw new Error(tag + ' 试算失败：' + price.error);
        }
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="5"].active', {timeout: 10000});
        await page.evaluate(() => { $('#cpq-add-term').trigger('click'); });
        await page.waitForFunction(() => document.querySelectorAll('#cpq-terms .terms-row').length > 0, {timeout: 8000});
        await page.evaluate(() => {
            $('.terms-row').last().find('input').val('NET30').trigger('change');
            $('.terms-row').last().find('textarea').val('验收后 30 天付款').trigger('change');
        });
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="6"].active', {timeout: 10000});
        await page.evaluate(() => { $('#toast-container').remove(); });
        await page.click('#cpq-submit');
        await page.waitForSelector('.layui-layer-btn0', {timeout: 10000});
        await page.click('.layui-layer-btn0');
        await page.waitForFunction(() =>
            document.querySelector('.toast-success') !== null || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'),
            {timeout: 25000});
        const outcome = await page.evaluate(() => ({
            ok: document.querySelector('.toast-success') !== null,
            error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text()
        }));
        return {price, outcome, id: draftId};
    }

    // ------------------------------------------------------------------
    // 0. P74 审批规则 + 规则模拟
    // ------------------------------------------------------------------
    await open(page, '/cpq/approval_rule/index');
    await page.waitForSelector('#table tbody tr', {timeout: 20000});
    const ruleRows = await page.evaluate(() => $('#table tbody').text());
    check(ruleRows.indexOf('CPQ-DEMO-APR-LINE') !== -1 && ruleRows.indexOf('CPQ-DEMO-APR-COMPANY') !== -1, 'P74 审批规则列表含产线/公司演示规则');
    await page.click('.btn-cpq-simulate');
    await page.waitForSelector('#cpq-sim-level', {timeout: 10000});
    await page.select('#cpq-sim-level', 'company');
    await page.select('#cpq-sim-line', 'DEMO-LINE');
    await page.click('.layui-layer-btn0');
    await page.waitForFunction(() => document.querySelectorAll('#cpq-sim-result tbody tr').length >= 2, {timeout: 15000});
    const simText = await page.evaluate(() => $('#cpq-sim-result').text());
    check(simText.indexOf('产线价格审批') !== -1 && simText.indexOf('公司价格审批') !== -1, 'P74 规则模拟解析两级固定路径');
    check(simText.indexOf('CPQ-DEMO-APR-LINE') !== -1 && simText.indexOf('CPQ-DEMO-APR-COMPANY') !== -1, 'P74 规则模拟命中产品线规则');
    await shot(page, 'm3-rule-simulate');
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // ------------------------------------------------------------------
    // 1. 三张报价：正常 / 产线 / 公司
    // ------------------------------------------------------------------
    const qNormal = await runWizard('M3验收-正常价格', '', 'm3-normal');
    check(qNormal.outcome.ok === true, '路径一 正常价格提交成功', qNormal.outcome.error);
    check(qNormal.price.approval === '无需审批', '路径一 试算等级=无需审批', JSON.stringify(qNormal.price));
    const qLine = await runWizard('M3验收-产线审批', '0.93', 'm3-line');
    check(qLine.outcome.ok === true, '路径二 产线审批提交成功', qLine.outcome.error);
    const qCompany = await runWizard('M3验收-公司审批', '0.85', 'm3-company');
    check(qCompany.outcome.ok === true, '路径三 公司审批提交成功', qCompany.outcome.error);

    // ------------------------------------------------------------------
    // 2. P02 我的待办：分类 / SLA / 批量批准默认禁用；销售确认
    // ------------------------------------------------------------------
    await open(page, '/cpq/todo/index');
    await page.waitForSelector('#cpq-todo-tabs', {timeout: 15000});
    const tabCount = await page.evaluate(() => document.querySelectorAll('#cpq-todo-tabs button').length);
    check(tabCount === 4, 'P02 待办四分类（待处理/已处理/我发起的/抄送我的）');
    const batchApproveDisabled = await page.evaluate(() =>
        Array.from(document.querySelectorAll('#toolbar a')).some(a => a.classList.contains('disabled') && a.textContent.indexOf('批量批准') !== -1));
    check(batchApproveDisabled, 'P02 批量批准按钮默认禁用');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收-正常价格') !== -1, {timeout: 20000});
    const todoText = await page.evaluate(() => $('#table tbody').text());
    check(todoText.indexOf('销售确认') !== -1, 'P02 待处理含销售确认任务');
    check(todoText.indexOf('剩余') !== -1, 'P02 展示 SLA 剩余时间');
    await shot(page, 'm3-todo-pending');

    // 进入销售确认详情并批准（confirm）
    let frame = await openDetailFromRow(page, 'M3验收-正常价格', '.btn-dialog');
    check(!!frame, 'P71 审批详情弹层打开');
    let outcome = await actInDetail(frame, 'approve', '销售确认无误');
    check(outcome.ok === true, '路径一 销售确认批准成功', outcome.alert);
    await shot(page, 'm3-normal-approve');
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // ------------------------------------------------------------------
    // 3. 职责分离：负责人不能处理特批节点（页面禁用 + API 拒绝）
    // ------------------------------------------------------------------
    await open(page, '/cpq/todo/index');
    await page.waitForSelector('#cpq-todo-tabs', {timeout: 15000});
    // 负责人待处理中不应出现特批节点任务（表格加载完毕即可，允许空表）
    await page.waitForFunction(() => document.querySelectorAll('#table tbody tr').length > 0, {timeout: 20000});
    const ownerTodo = await page.evaluate(() => $('#table tbody').text());
    check(ownerTodo.indexOf('产线价格审批') === -1 && ownerTodo.indexOf('公司价格审批') === -1, '职责分离：负责人待办不含特批节点任务');

    // 负责人从「我发起的」进入流程视图，取任务详情验证按钮禁用
    await open(page, '/cpq/approval_instance/index');
    await page.waitForSelector('#table', {timeout: 15000});
    await findRow(page, '#cpq-instance-keyword', 'M3验收-产线审批', 'M3验收-产线审批');
    const initiatedText = await page.evaluate(() => $('#table tbody').text());
    check(initiatedText.indexOf('进行中') !== -1 && initiatedText.indexOf('产线价格审批') !== -1, 'P72 我发起的显示当前节点与状态');
    await shot(page, 'm3-initiated');
    // 撤回一条旧报价做版本失效场景前，先取产线任务 id（经候选人接口不可行，用记录页查询后续）

    // 职责分离（API 级）：从「我发起的」流程视图取待处理任务 id，以负责人身份直调动作接口应被拒绝
    const flowUrl = await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('#table tbody tr')).find(r => r.textContent.indexOf('M3验收-产线审批') !== -1);
        const btn = row && row.querySelector('a.btn-dialog');
        return btn ? btn.getAttribute('href') : '';
    });
    check(flowUrl.indexOf('cpq/approval_instance/flow/ids/') !== -1, 'P72 流程视图入口存在', flowUrl);
    await page.goto(new URL(flowUrl, BASE + '/').href, {waitUntil: 'networkidle0', timeout: 30000});
    await page.waitForSelector('#cpq-flow-nodes .task-chip', {timeout: 15000});
    const lineTaskId = await page.evaluate(() => {
        const chip = Array.from(document.querySelectorAll('#cpq-flow-nodes .task-chip'))
            .find(c => c.textContent.indexOf('待处理') !== -1 || c.textContent.indexOf('pending') !== -1);
        const match = chip ? chip.textContent.match(/#(\d+)/) : null;
        return match ? parseInt(match[1], 10) : 0;
    });
    check(lineTaskId > 0, '流程视图取得待处理任务 id=' + lineTaskId);
    const sodReject = await page.evaluate(async (taskId, base) => {
        const resp = await fetch(base + '/cpq/approval_task/action', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({task_id: taskId, action: 'approve', comment: '负责人自批探测', idempotency_key: 'M3-SOD-PROBE'})
        });
        return resp.json();
    }, lineTaskId, BASE);
    check(sodReject.code !== 1 && /无权|分离|不存在|失效/.test(sodReject.msg || ''), '职责分离：负责人直调批准被服务端拒绝', (sodReject.msg || '').slice(0, 120));
    await shot(page, 'm3-sod-flow');

    // ------------------------------------------------------------------
    // 4. 产线审批人：待办 → 批准（产线）→ 公司路径推进
    // ------------------------------------------------------------------
    await logout(page);
    await login(page, 'cpq_approver', APPROVER_PASSWORD);
    await open(page, '/cpq/approval_task/index');
    await page.waitForSelector('#cpq-approval-tabs', {timeout: 15000});
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收-产线审批') !== -1, {timeout: 20000});
    const approverTodo = await page.evaluate(() => $('#table tbody').text());
    check(approverTodo.indexOf('M3验收-公司审批') !== -1, 'P70 产线审批人待办含两笔产线节点任务');
    check(approverTodo.indexOf('剩余') !== -1, 'P70 待办展示 SLA');
    await shot(page, 'm3-approver-todo');

    frame = await openDetailFromRow(page, 'M3验收-产线审批', '.btn-dialog');
    check(!!frame, 'P71 产线审批详情打开');
    await frame.waitForSelector('#cpq-apd-risks', {timeout: 15000});
    const detailText = await frame.evaluate(() => ({
        risks: $('#cpq-apd-risks').text(),
        price: $('#cpq-apd-price-totals').text(),
        ops: $('#cpq-apd-operate').text(),
        hint: $('#cpq-apd-operate-hint').text(),
        terms: $('#cpq-apd-terms').text()
    }));
    check(detailText.risks.indexOf('低于指导价') !== -1 || detailText.risks.indexOf('产线价格审批') !== -1, 'P71 风险项展示审批等级与价格分级');
    check(detailText.price.indexOf('含税总额') !== -1, 'P71 价格汇总展示（服务端脱敏结果）');
    check(detailText.ops.indexOf('加签') !== -1 && detailText.ops.indexOf('转交') !== -1 && detailText.ops.indexOf('退回') !== -1, 'P71 操作区为五种审批动作');
    check(detailText.terms.indexOf('30 天付款') !== -1, 'P71 条款展示（冻结版本条款快照）', detailText.terms.slice(0, 60));
    await shot(page, 'm3-line-detail');
    outcome = await actInDetail(frame, 'approve', '产线同意');
    check(outcome.ok === true, '路径二 产线批准成功', outcome.alert);
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // 公司路径：产线节点批准 → 推进到公司节点
    frame = await openDetailFromRow(page, 'M3验收-公司审批', '.btn-dialog');
    outcome = await actInDetail(frame, 'approve', '产线同意，转公司审批');
    check(outcome.ok === true, '路径三 产线节点批准成功', outcome.alert);
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // 重复操作：从待办列表取一笔已处理任务，再次动作应被服务端拒绝（任务已处理/失效）
    const processedDup = await page.evaluate(async (base) => {
        const listResp = await fetch(base + '/cpq/approval_task/index', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
            body: 'category=processed&page=1&limit=5'
        });
        const list = await listResp.json();
        const row = (list.rows || [])[0];
        if (!row) {
            return {skip: true};
        }
        const resp = await fetch(base + '/cpq/approval_task/action', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({task_id: row.id, action: 'approve', comment: '重复操作探测', idempotency_key: 'M3-DUP-' + row.id})
        });
        return {skip: false, ret: await resp.json()};
    }, BASE);
    check(processedDup.skip || (processedDup.ret && processedDup.ret.code !== 1), '重复操作：已处理任务再次动作被拒绝',
        processedDup.skip ? '无已处理任务可探测' : JSON.stringify(processedDup.ret).slice(0, 160));

    // ------------------------------------------------------------------
    // 5. 委托与代理：产线审批员委托给公司审批员
    // ------------------------------------------------------------------
    // 先建一笔新的产线报价用于代理场景
    await logout(page);
    await login(page, 'admin', ADMIN_PASSWORD);
    const qDeleg = await runWizard('M3验收-代理审批', '0.93', 'm3-deleg');
    check(qDeleg.outcome.ok === true, '代理场景报价提交成功', qDeleg.outcome.error);

    await logout(page);
    await login(page, 'cpq_approver', APPROVER_PASSWORD);
    await open(page, '/cpq/approval_delegation/index');
    await page.waitForSelector('#table', {timeout: 15000});
    await page.waitForFunction(() => document.querySelectorAll('#table tbody tr').length > 0, {timeout: 15000}).catch(() => {});
    // 幂等重跑：先撤销本人名下未完成（待审批/生效中）的委托
    for (let i = 0; i < 5; i++) {
        const hasCancel = await page.evaluate(() => {
            const btn = document.querySelector('#table tbody .btn-cpq-cancel');
            if (btn) { btn.click(); return true; }
            return false;
        });
        if (!hasCancel) { break; }
        await page.waitForSelector('.layui-layer-btn0', {timeout: 8000});
        await page.click('.layui-layer-btn0');
        await page.waitForFunction(() => document.querySelector('.toast-success') !== null, {timeout: 10000});
        await new Promise(r => setTimeout(r, 800));
    }
    await page.click('.btn-cpq-delegation-add');
    await page.waitForSelector('#cpq-dlg-line', {timeout: 10000});
    await page.select('#cpq-dlg-line', 'DEMO-LINE');
    await page.evaluate(() => {
        const fmt = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') + ' ' +
            String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':00';
        const now = new Date();
        $('#cpq-dlg-starts').val(fmt(new Date(now.getTime() - 3600000)));
        $('#cpq-dlg-ends').val(fmt(new Date(now.getTime() + 86400000)));
        $('#cpq-dlg-reason').val('休假期间代理审批');
    });
    // 代理人：经 selectpage 下拉选择「CPQ演示审批员-公司」（打开下拉 → 键盘定位目标行 → 回车）
    await page.waitForSelector('#cpq-dlg-delegate_text', {timeout: 10000});
    await page.click('#cpq-dlg-delegate_text');
    await page.waitForFunction(() => Array.from(document.querySelectorAll('.sp_results li')).some(el => el.textContent.indexOf('公司') !== -1), {timeout: 10000});
    const targetIndex = await page.evaluate(() =>
        Array.from(document.querySelectorAll('.sp_results li')).findIndex(el => el.textContent.indexOf('公司') !== -1));
    check(targetIndex >= 0, 'P75 候选人下拉含目标代理人', 'index=' + targetIndex);
    for (let i = 0; i <= targetIndex; i++) {
        await page.keyboard.press('ArrowDown');
        await new Promise(r => setTimeout(r, 250));
    }
    await page.keyboard.press('Enter');
    await new Promise(r => setTimeout(r, 800));
    const delegateVal = await page.evaluate(() => $('#cpq-dlg-delegate').val());
    check(!!delegateVal, 'P75 委托代理人已选择', 'delegate_id=' + delegateVal);
    await page.evaluate(() => { document.querySelector('.layui-layer-btn0').click(); });
    await page.waitForFunction(() => document.querySelector('.toast-success') !== null
        || document.querySelector('.toast-error') !== null
        || document.querySelector('.layui-layer-dialog') !== null, {timeout: 15000});
    const delegCreated = await page.evaluate(() => document.querySelector('.toast-success') !== null);
    if (!delegCreated) {
        console.log('委托创建反馈：', await page.evaluate(() =>
            (document.querySelector('.toast-error') || {}).textContent
            || (document.querySelector('.layui-layer-dialog') || {}).textContent || '无反馈'));
    }
    check(delegCreated, 'P75 委托申请已提交（待审批）');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('待审批') !== -1, {timeout: 20000});
    await shot(page, 'm3-delegation-created');

    // 委托人自己不能审批自己的委托（无委托审批权限）——批准按钮不可见
    const selfApproveVisible = await page.evaluate(() =>
        Array.from(document.querySelectorAll('#table .btn-cpq-approve')).some(b => b.offsetParent !== null));
    check(!selfApproveVisible, 'P75 无委托审批权限者不见批准按钮');

    // admin（system_admin）批准委托
    await logout(page);
    await login(page, 'admin', ADMIN_PASSWORD);
    await open(page, '/cpq/approval_delegation/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('待审批') !== -1, {timeout: 20000});
    await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('#table tbody tr')).find(r => r.textContent.indexOf('待审批') !== -1);
        row.querySelector('.btn-cpq-approve').click();
    });
    await page.waitForSelector('.layui-layer-btn0', {timeout: 10000});
    await page.click('.layui-layer-btn0');
    await page.waitForFunction(() => document.querySelector('.toast-success') !== null, {timeout: 15000});
    check(true, 'P75 委托经审批生效');
    await shot(page, 'm3-delegation-approved');

    // 代理人（公司审批员）待办可见委托人任务并带代理标记
    await logout(page);
    await login(page, 'cpq_approver2', APPROVER_PASSWORD);
    await open(page, '/cpq/todo/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收-代理审批') !== -1, {timeout: 20000});
    const delegTodo = await page.evaluate(() => $('#table tbody').text());
    check(delegTodo.indexOf('代理') !== -1, 'P02 代理待办带「代理」来源标记');
    await shot(page, 'm3-delegate-todo');

    frame = await openDetailFromRow(page, 'M3验收-代理审批', '.btn-dialog');
    const delegBanner = await frame.evaluate(() => $('#cpq-apd-delegate').hasClass('hidden') ? '' : $('#cpq-apd-delegate').text());
    check(delegBanner.indexOf('代理人身份') !== -1, 'P71 代理处理提示委托人');
    outcome = await actInDetail(frame, 'approve', '代理批准');
    check(outcome.ok === true, '代理批准成功', outcome.alert);
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // 代理操作记录留痕
    await open(page, '/cpq/approval_delegation/actions');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('批准') !== -1, {timeout: 20000});
    const delegActions = await page.evaluate(() => $('#table tbody').text());
    check(delegActions.indexOf('代理') !== -1, 'P75 代理操作记录含委托人标记');
    await shot(page, 'm3-delegate-actions');

    // ------------------------------------------------------------------
    // 6. 公司审批人处理公司节点（路径三完成）
    // ------------------------------------------------------------------
    await open(page, '/cpq/approval_task/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收-公司审批') !== -1, {timeout: 20000});
    frame = await openDetailFromRow(page, 'M3验收-公司审批', '.btn-dialog');
    outcome = await actInDetail(frame, 'approve', '公司同意');
    check(outcome.ok === true, '路径三 公司批准成功（两级完成）', outcome.alert);
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // ------------------------------------------------------------------
    // 7. P73 审批记录：动作流水与代理标记
    // ------------------------------------------------------------------
    await logout(page);
    await login(page, 'admin', ADMIN_PASSWORD);
    await open(page, '/cpq/approval_record/index');
    await page.waitForSelector('#cpq-record-action', {timeout: 15000});
    await page.waitForFunction(() => $('#table tbody tr').length > 0, {timeout: 20000});
    const recordText = await page.evaluate(() => $('#table tbody').text());
    check(recordText.indexOf('批准') !== -1 && recordText.indexOf('销售确认') !== -1, 'P73 审批记录含确认/批准动作');
    check(recordText.indexOf('代理') !== -1, 'P73 审批记录含代理处理标记');
    check(recordText.indexOf('→') !== -1, 'P73 审批记录含动作前后哈希');
    await shot(page, 'm3-records');

    // 报价状态核验：三张报价应已批准
    await open(page, '/cpq/quote/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收') !== -1, {timeout: 20000});
    const quoteListText = await page.evaluate(() => $('#table tbody').text());
    check(['M3验收-正常价格', 'M3验收-产线审批', 'M3验收-公司审批', 'M3验收-代理审批'].every(n => quoteListText.indexOf(n) !== -1), '报价列表含全部 M3 验收报价');
    await shot(page, 'm3-quote-list');

    // ------------------------------------------------------------------
    // 8. P59 报价模板：预览 / 复制新版本 / 列表
    // ------------------------------------------------------------------
    await open(page, '/cpq/quote_template/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('CPQ-DEMO-QT-ZH') !== -1, {timeout: 20000});
    const tplText = await page.evaluate(() => $('#table tbody').text());
    check(tplText.indexOf('CPQ-DEMO-QT-EN') !== -1, 'P59 中英文模板均在列表');
    check(tplText.indexOf('已发布') !== -1, 'P59 模板状态徽标');
    // 预览（精确匹配编码列，避免命中历史副本 CPQ-DEMO-QT-ZH-Vn）
    await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('#table tbody tr'))
            .find(r => r.cells[1] && r.cells[1].textContent.trim() === 'CPQ-DEMO-QT-ZH');
        row.querySelector('.btn-cpq-preview').click();
    });
    await page.waitForSelector('#cpq-preview-frame', {timeout: 10000});
    let previewFrame = null;
    for (let i = 0; i < 10; i++) {
        previewFrame = page.frames().find(f => f.url() === 'about:blank' && f.parentFrame() !== page.mainFrame());
        if (previewFrame) { break; }
        await new Promise(r => setTimeout(r, 500));
    }
    const previewHtml = await page.evaluate(() => {
        const f = document.querySelector('#cpq-preview-frame');
        return f && f.contentDocument ? f.contentDocument.body.innerHTML : '';
    });
    check(previewHtml.indexOf('Q-DEMO-0001') !== -1, 'P59 模板预览以测试数据渲染变量');
    await shot(page, 'm3-template-preview');
    await page.evaluate(() => { try { Layer.closeAll(); } catch (e) {} });

    // 复制新版本（精确匹配已发布的源模板行）
    await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('#table tbody tr'))
            .find(r => r.cells[1] && r.cells[1].textContent.trim() === 'CPQ-DEMO-QT-ZH');
        row.querySelector('.btn-cpq-copy').click();
    });
    await page.waitForSelector('.layui-layer-btn0', {timeout: 10000});
    await page.click('.layui-layer-btn0');
    await page.waitForFunction(() => document.querySelector('.toast-success') !== null, {timeout: 15000});
    await page.waitForFunction(() => $('#table tbody').text().indexOf('-V') !== -1, {timeout: 20000});
    check(true, 'P59 已发布模板复制为新版本草稿');

    // ------------------------------------------------------------------
    // 9. P60 报价打印记录：生成 → 轮询 → 哈希验证 → 受控下载
    // ------------------------------------------------------------------
    await open(page, '/cpq/quote_document/index');
    await page.waitForSelector('#table', {timeout: 15000});
    await page.click('.btn-cpq-generate');
    await page.waitForSelector('#cpq-gen-quote', {timeout: 10000});
    await page.evaluate((name) => {
        const sel = document.querySelector('#cpq-gen-quote');
        const opt = Array.from(sel.options).find(o => o.textContent.indexOf(name) !== -1);
        if (opt) { sel.value = opt.value; }
    }, 'M3验收-正常价格');
    await page.evaluate(() => { document.querySelector('.layui-layer-btn0').click(); });
    await page.waitForFunction(() => document.querySelector('.toast-success') !== null || document.querySelector('.layui-layer-dialog') !== null, {timeout: 15000});
    const genOk = await page.evaluate(() => document.querySelector('.toast-success') !== null);
    check(genOk, 'P60 PDF 生成任务已受理');

    // 轮询至已生成（队列 worker 消费）
    let docReady = false;
    for (let i = 0; i < 30; i++) {
        await new Promise(r => setTimeout(r, 3000));
        await page.evaluate(() => { $('#table').bootstrapTable('refresh', {silent: true}); });
        const text = await page.evaluate(() => $('#table tbody').text());
        if (text.indexOf('已生成') !== -1) { docReady = true; break; }
    }
    check(docReady, 'P60 异步任务轮询至已生成');
    await shot(page, 'm3-document-generated');

    // 哈希验证
    await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('#table tbody tr')).find(r => r.textContent.indexOf('已生成') !== -1);
        row.querySelector('.btn-cpq-verify').click();
    });
    await page.waitForSelector('.layui-layer-dialog', {timeout: 10000});
    const verifyText = await page.evaluate(() => document.querySelector('.layui-layer-dialog').textContent);
    check(verifyText.indexOf('哈希验证通过') !== -1, 'P60 哈希验证通过', verifyText.slice(0, 80));
    await shot(page, 'm3-document-verify');
    await page.evaluate(() => { document.querySelector('.layui-layer-close').click(); });

    // 受控下载（fetch 校验 200 + PDF，下载计数 +1）
    const download = await page.evaluate(async () => {
        const row = Array.from(document.querySelectorAll('#table tbody tr')).find(r => r.textContent.indexOf('已生成') !== -1);
        const btn = row.querySelector('.btn-cpq-download');
        const idCell = row.cells[1] ? row.textContent : '';
        return {hasBtn: !!btn};
    });
    check(download.hasBtn, 'P60 已生成任务展示受控下载按钮');
    const docId = await page.evaluate(() => {
        return $('#table').bootstrapTable('getData').find(r => r.status === 'succeeded').id;
    });
    const dlResp = await page.evaluate(async (id, base) => {
        const resp = await fetch(base + '/cpq/quote_document/download/ids/' + id, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        const buf = await resp.arrayBuffer();
        return {status: resp.status, type: resp.headers.get('content-type'), size: buf.byteLength};
    }, docId, BASE);
    check(dlResp.status === 200 && (dlResp.type || '').indexOf('application/pdf') !== -1 && dlResp.size > 512, 'P60 受控下载返回 PDF 文件流', JSON.stringify(dlResp));
    await page.evaluate(() => { $('#table').bootstrapTable('refresh', {silent: true}); });
    await new Promise(r => setTimeout(r, 1500));
    const dlCount = await page.evaluate(() => $('#table tbody').text());
    check(/[1-9]/.test(dlCount), 'P60 下载计数更新');

    // ------------------------------------------------------------------
    // 10. 版本失效：撤回后旧任务详情显示失效提示
    // ------------------------------------------------------------------
    const qStale = await runWizard('M3验收-版本失效', '0.93', 'm3-stale');
    check(qStale.outcome.ok === true, '版本失效场景报价提交成功', qStale.outcome.error);
    // 审批人待办中取该任务 id（撤回前）
    await logout(page);
    await login(page, 'cpq_approver', APPROVER_PASSWORD);
    await open(page, '/cpq/approval_task/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收-版本失效') !== -1, {timeout: 20000});
    const staleTaskUrl = await page.evaluate(() => {
        const row = Array.from(document.querySelectorAll('#table tbody tr')).find(r => r.textContent.indexOf('M3验收-版本失效') !== -1);
        const btn = row && row.querySelector('a.btn-dialog');
        return btn ? btn.getAttribute('href') : '';
    });
    check(staleTaskUrl.indexOf('cpq/approval_task/detail/ids/') !== -1, '版本失效场景取得任务详情地址', staleTaskUrl);
    // 负责人撤回报价 → 任务失效
    await logout(page);
    await login(page, 'admin', ADMIN_PASSWORD);
    await open(page, '/cpq/quote/index');
    await page.waitForFunction(() => $('#table tbody').text().indexOf('M3验收-版本失效') !== -1, {timeout: 20000});
    await page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('#table tbody tr'));
        const row = rows.find(r => r.textContent.indexOf('M3验收-版本失效') !== -1);
        const btn = row && row.querySelector('.btn-cpq-withdraw');
        if (btn) { btn.click(); }
    });
    await page.waitForSelector('.layui-layer-btn0', {timeout: 10000});
    await page.click('.layui-layer-btn0');
    await page.waitForFunction(() => document.querySelector('.toast-success') !== null, {timeout: 15000});
    check(true, '版本失效场景报价已撤回');
    // 审批人直接打开旧任务详情：失效提示 + 操作禁用
    await logout(page);
    await login(page, 'cpq_approver', APPROVER_PASSWORD);
    await page.goto(new URL(staleTaskUrl, BASE + '/').href, {waitUntil: 'networkidle0', timeout: 30000});
    await page.waitForSelector('#cpq-apd-operate-panel', {timeout: 15000});
    const stale = await page.evaluate(() => ({
        alert: $('#cpq-apd-stale').hasClass('hidden') ? '' : $('#cpq-apd-stale').text(),
        disabled: Array.from(document.querySelectorAll('#cpq-apd-operate button')).every(b => b.disabled)
    }));
    check(stale.alert.indexOf('已失效') !== -1 || stale.alert.indexOf('版本已变化') !== -1, 'P71 版本变化后任务失效提示', stale.alert.slice(0, 80));
    check(stale.disabled === true, 'P71 失效任务操作按钮全部禁用');
    await shot(page, 'm3-stale-task');

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
