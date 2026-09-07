/**
 * GYTAI-70 M2 报价向导浏览器验收（P50-P58）。
 *
 * 主路径：报价列表 → 六步向导建单（客户/选品/配置/试算/条款/提交，Q-001）
 * → 低于公司底价阻断（Q-004，错误回到第 4 步）→ 详情只读 → 修订（Q-010）
 * → 版本差异。金额一律为服务端 Decimal 字符串原样展示。
 *
 * 运行：NODE_PATH=runtime/temp/cpq_browser/node/node_modules node tests/cpq/browser/m2_quote_check.js
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const BASE = process.env.CPQ_BASE || 'http://127.0.0.1:8082/azbMYFuJTB.php';
const PASSWORD = process.env.CPQ_ADMIN_PASSWORD || 'Admin@123456';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = __dirname + '/../../../runtime/temp/cpq_browser/shots';
const results = [];
function check(condition, name, detail) {
    results.push({ok: !!condition, name, detail: detail || ''});
    console.log(condition ? '[PASS]' : '[FAIL]', name, detail || '');
}
async function open(page, path) { await page.goto(BASE + path, {waitUntil: 'networkidle0', timeout: 30000}); }
async function shot(page, name) { await page.screenshot({path: SHOTS + '/' + name + '.png', fullPage: true}); }

let browser;
(async () => {
    fs.mkdirSync(SHOTS, {recursive: true});
    browser = await puppeteer.launch({executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--window-size=1440,1100']});
    const page = await browser.newPage();
    await page.setViewport({width: 1440, height: 1100});
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('response', r => { if (r.status() >= 500) errors.push('HTTP ' + r.status() + ' ' + r.url()); });

    await open(page, '/index/login');
    await page.type('#pd-form-username', 'admin');
    await page.type('#pd-form-password', PASSWORD);
    await Promise.all([page.waitForNavigation({waitUntil: 'networkidle0', timeout: 30000}), page.click('#login-form button[type=submit]')]);

    // P50 列表：多视图/筛选加载
    await open(page, '/cpq/quote/index');
    await page.waitForSelector('#cpq-quote-views', {timeout: 15000});
    const viewCount = await page.evaluate(() => document.querySelectorAll('#cpq-quote-views button').length);
    check(viewCount === 6, 'P50 报价列表多视图分组', '视图数 ' + viewCount);
    await shot(page, 'm2q-quote-list');

    // 记录保存响应中的报价 ID（草稿保存返回 payload.id）
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

    // 向导主路径工厂：走完 1-6 步，返回是否提交成功
    async function runWizard(name, discount, expectSubmitOk, tag) {
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

        // 第 2 步：选品
        await page.waitForSelector('.wizard-panel[data-step="2"].active', {timeout: 10000});
        await page.evaluate(() => { $('#cpq-pick-model').val('1'); $('#cpq-pick-quantity').val('1'); });
        await page.click('#cpq-add-line');
        await page.waitForFunction(() => document.querySelectorAll('#cpq-lines-table tbody tr:not(.cpq-lines-empty)').length === 1, {timeout: 15000});

        // 第 3 步：配置（补齐必填项后服务端校验合法）
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="3"].active', {timeout: 10000});
        await page.waitForFunction(() => document.querySelectorAll('.cpq-config-line .cfg-group').length > 0, {timeout: 20000});
        await page.evaluate(() => {
            $('input[name$="-quantity"]').val('1').trigger('change');
            $('input[name$="-features"][value="monitoring"]').prop('checked', true).trigger('change');
        });
        await page.waitForFunction(() => document.querySelector('.cpq-line-issues .label-success') !== null, {timeout: 20000});
        const configValid = await page.evaluate(() => document.querySelector('.cpq-line-issues .label-success') !== null);
        check(configValid, tag + ' 第3步默认配置服务端校验合法',
            await page.evaluate(() => $('.cpq-line-issues').text().trim().slice(0, 200)));
        if (!configValid) {
            return false;
        }
        await shot(page, tag + '-step3-config');

        // 第 4 步：价格与折扣 → 服务端试算
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="4"].active', {timeout: 10000});
        if (discount) {
            await page.evaluate((d) => {
                $('#cpq-price-lines .line-card input').eq(0).val(d).trigger('change');
                $('#cpq-price-lines .line-card input').eq(1).val('浏览器边界验收').trigger('change');
            }, discount);
        }
        await page.click('#cpq-recalc');
        await page.waitForFunction(() => !document.querySelector('#cpq-price-summary').classList.contains('hidden') || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'), {timeout: 20000});
        const price = await page.evaluate(() => ({
            total: $('#cpq-sum-total').text().trim(),
            approval: $('#cpq-sum-approval').text().trim(),
            submittable: $('#cpq-sum-submittable').text().trim(),
            error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text()
        }));
        if (price.error) {
            throw new Error(tag + ' 试算失败：' + price.error);
        }
        await shot(page, tag + '-step4-pricing');
        check(draftId > 0, tag + ' 草稿已保存并返回 ID', 'id=' + draftId);

        // 第 5 步：商务条款
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="5"].active', {timeout: 10000});
        await page.click('#cpq-add-term');
        await page.evaluate(() => {
            $('.terms-row').last().find('input').val('NET30').trigger('change');
            $('.terms-row').last().find('textarea').val('验收后 30 天付款').trigger('change');
        });

        // 第 6 步：预览与提交
        await page.click('#cpq-next');
        await page.waitForSelector('.wizard-panel[data-step="6"].active', {timeout: 10000});
        const preview = await page.evaluate(() => $('#cpq-preview').text());
        check(preview.indexOf(name) !== -1 && preview.indexOf('DEMO-LINE') !== -1, tag + ' 第6步预览包含报价信息');
        await page.evaluate(() => { $('#toast-container').remove(); });
        await page.click('#cpq-submit');
        await page.waitForSelector('.layui-layer-btn0', {timeout: 10000});
        await page.click('.layui-layer-btn0');
        await page.waitForFunction(() =>
            document.querySelector('.toast-success') !== null || !document.querySelector('#cpq-wizard-error').classList.contains('hidden'),
            {timeout: 25000});
        const outcome = await page.evaluate(() => ({
            ok: document.querySelector('.toast-success') !== null,
            error: $('#cpq-wizard-error').hasClass('hidden') ? '' : $('#cpq-wizard-error').text(),
            step: $('#cpq-steps li.active').data('step'),
            stepError: $('#cpq-steps li.error').data('step') || 0
        }));
        return {price, outcome};
    }

    // Q-001 主路径：正常价格提交成功
    const q1 = await runWizard('浏览器验收报价 Q-001', '', true, 'q001');
    check(q1.outcome.ok === true, 'Q-001 六步向导提交成功', q1.outcome.error);
    check(q1.price.approval === '无需审批' && q1.price.submittable === '允许', 'Q-001 试算等级与可提交', JSON.stringify(q1.price));
    check(/^\d+\.\d{4}$/.test(q1.price.total), 'Q-001 总额为服务端 Decimal 字符串', q1.price.total);
    await shot(page, 'q001-submitted');
    const firstQuoteId = draftId;

    // Q-004：低于公司控制价，前端提交被服务端阻断且回到第 4 步
    const q4 = await runWizard('浏览器验收报价 Q-004', '0.75', false, 'q004');
    check(q4.price.submittable === '禁止' && q4.price.approval === '禁止提交', 'Q-004 试算即禁止提交', JSON.stringify(q4.price));
    check(q4.outcome.ok === false && q4.outcome.error.indexOf('CPQ_PRICE_BELOW_COMPANY_FLOOR') !== -1, 'Q-004 提交被服务端硬阻断', q4.outcome.error.slice(0, 160));
    check(String(q4.outcome.step) === '4' && String(q4.outcome.stepError) === '4', 'Q-004 错误回到第 4 步', 'step=' + q4.outcome.step + ' errorStep=' + q4.outcome.stepError);
    await shot(page, 'q004-blocked');

    // P53 详情：只读 + 版本历史
    await open(page, '/cpq/quote/detail/ids/' + firstQuoteId);
    await page.waitForSelector('#cpq-detail-base', {timeout: 15000});
    const detail = await page.evaluate(() => ({
        base: $('#cpq-detail-base').text(),
        revisions: $('#cpq-detail-revisions').text(),
        inputs: document.querySelectorAll('.cpq-quote-detail input:not([type=hidden]), .cpq-quote-detail textarea, .cpq-quote-detail select').length,
        actions: $('#cpq-detail-actions').text()
    }));
    check(detail.base.indexOf('已提交') !== -1, 'P53 详情显示已提交状态');
    check(detail.revisions.indexOf('v1') !== -1, 'P53 版本历史含 v1 冻结版本');
    check(detail.inputs === 0, 'P53 详情页整页只读（无可编辑输入）', 'inputs=' + detail.inputs);
    check(detail.actions.indexOf('撤回') !== -1 && detail.actions.indexOf('创建修订版本') !== -1, 'P53 已提交态操作按钮');
    await shot(page, 'm2q-quote-detail');

    // Q-010：已提交报价创建修订 → 新草稿在向导中恢复
    await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('#cpq-detail-actions button'));
        const target = buttons.find(b => b.textContent.indexOf('创建修订版本') !== -1);
        target.click();
    });
    await page.waitForSelector('#cpq-revision-note', {timeout: 10000});
    await new Promise(r => setTimeout(r, 600));
    await page.evaluate(() => { document.querySelector('.layui-layer-btn0').click(); });
    await page.waitForFunction(() => Array.from(document.querySelectorAll('iframe')).some(f => (f.src || '').indexOf('cpq/quote/wizard/ids/') !== -1), {timeout: 20000});
    // 等 puppeteer frame 注册完成（DOM iframe 与 frames() 注册存在时序差）
    let wizardFrame = null;
    for (let i = 0; i < 20; i++) {
        wizardFrame = page.frames().find(f => f.url().indexOf('cpq/quote/wizard/ids/') !== -1);
        if (wizardFrame) {
            break;
        }
        await new Promise(r => setTimeout(r, 500));
    }
    check(!!wizardFrame, 'Q-010 修订生成新草稿并打开向导');
    if (wizardFrame) {
        await wizardFrame.waitForFunction(() => document.querySelectorAll('#cpq-lines-table tbody tr:not(.cpq-lines-empty)').length === 1, {timeout: 15000});
        check(true, 'Q-010 修订草稿在向导中恢复明细行');
        const hint = await wizardFrame.evaluate(() => $('#cpq-draft-hint').text());
        check(hint.indexOf('乐观锁 v1') !== -1, 'Q-010 新草稿乐观锁从 v1 开始', hint);
    }

    // P58 版本差异：v0 → v1
    await open(page, '/cpq/quote/diff/ids/' + firstQuoteId);
    await page.waitForSelector('#cpq-diff-run', {timeout: 15000});
    await page.click('#cpq-diff-run');
    await page.waitForFunction(() => !document.querySelector('#cpq-diff-result').classList.contains('hidden') || !document.querySelector('#cpq-diff-error').classList.contains('hidden'), {timeout: 20000});
    const diff = await page.evaluate(() => ({
        error: $('#cpq-diff-error').hasClass('hidden') ? '' : $('#cpq-diff-error').text(),
        count: $('#cpq-diff-count').text(),
        lines: $('#cpq-diff-lines').text(),
        totals: $('#cpq-diff-totals').text()
    }));
    if (diff.error) {
        throw new Error('版本差异失败：' + diff.error);
    }
    check(diff.lines.indexOf('新增') !== -1, 'P58 空基线对比显示新增行', diff.lines.slice(0, 120));
    check(diff.totals.indexOf('含税总额') !== -1, 'P58 汇总金额差异展示');
    await shot(page, 'm2q-quote-diff');

    check(errors.length === 0, '页面无 5xx / JavaScript 异常', errors.join(' | '));
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
