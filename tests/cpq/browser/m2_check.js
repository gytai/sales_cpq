/** GYTAI-74 M2 浏览器验收：页面可达、矩阵脱敏、树形页、P36 真实试算与轨迹。 */
const puppeteer = require('puppeteer-core');
const BASE = process.env.CPQ_BASE || 'http://127.0.0.1:8082/azbMYFuJTB.php';
const PASSWORD = process.env.CPQ_ADMIN_PASSWORD || 'Admin@123456';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SHOTS = __dirname + '/../../../runtime/temp/cpq_browser/shots';
const results = [];
function check(condition, name, detail) {
    results.push({ok: !!condition, name, detail: detail || ''});
    console.log(condition ? '[PASS]' : '[FAIL]', name, detail || '');
}
async function open(page, path) { await page.goto(BASE + path, {waitUntil:'networkidle0', timeout:30000}); }
async function rows(page, selector) { await page.waitForFunction((selector) => Array.from(document.querySelectorAll(selector + ' tbody tr')).some(tr => !tr.classList.contains('no-records-found')), {timeout:15000}, selector); }
let browser;
(async () => {
    browser = await puppeteer.launch({executablePath:CHROME,headless:'new',args:['--no-sandbox','--window-size=1440,1000']});
    const page = await browser.newPage(); await page.setViewport({width:1440,height:1000});
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('response', r => { if (r.status() >= 500) errors.push('HTTP ' + r.status() + ' ' + r.url()); });
    await open(page, '/index/login');
    await page.type('#pd-form-username', 'admin'); await page.type('#pd-form-password', PASSWORD);
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle0',timeout:30000}),page.click('#login-form button[type=submit]')]);

    await open(page, '/cpq/price_policy/index'); await rows(page, '#table');
    const matrix = await page.evaluate(() => ({text:document.body.innerText,headers:Array.from(document.querySelectorAll('#table th')).map(x=>x.innerText.trim()),rows:document.querySelectorAll('#table tbody tr').length}));
    check(matrix.rows > 0, 'P32 价格矩阵加载');
    check(matrix.headers.includes('指导价') && matrix.headers.includes('产线控制价'), '矩阵显示授权价格字段');
    check(matrix.headers.includes('成本价') && matrix.headers.includes('公司控制价'), '系统管理员可见完整价格字段');

    const cookies = await page.cookies();
    if (cookies.length) await page.deleteCookie(...cookies);
    await open(page, '/index/login');
    await page.waitForSelector('#pd-form-username', {timeout:10000});
    await page.type('#pd-form-username', 'cpq_sales'); await page.type('#pd-form-password', 'Appr@123456');
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle0',timeout:30000}),page.click('#login-form button[type=submit]')]);
    await open(page, '/cpq/price_policy/index'); await rows(page, '#table');
    const salesMatrix = await page.evaluate(() => ({headers:Array.from(document.querySelectorAll('#table th')).map(x=>x.innerText.trim()),text:$('#table').text()}));
    const salesHeaders = salesMatrix.headers;
    check(!salesHeaders.includes('成本价') && !salesHeaders.includes('公司控制价'), '销售角色成本/公司控制价不进入 DOM', JSON.stringify(salesHeaders));
    check(!salesMatrix.text.includes('60000.0000') && !salesMatrix.text.includes('96000.0000'), '销售角色列表不含后端未授权值');
    await page.screenshot({path:SHOTS + '/m2-price-matrix-masked.png',fullPage:true});

    await open(page, '/cpq/region/index'); await rows(page, '#table');
    const treeText = await page.evaluate(() => document.querySelector('#table').innerText);
    check(treeText.includes('CPQ-DEMO-REGION-CN') && treeText.includes('CPQ-DEMO-REGION-EAST'), 'P44 区域树父子节点展示');
    await page.screenshot({path:SHOTS + '/m2-region-tree.png',fullPage:true});

    await open(page, '/cpq/pricing/index'); await page.waitForSelector('#cpq-pricing-form');
    await page.evaluate(() => {
        $('[name="date"]').val('2026-09-01'); $('[name="customer_id"]').val('1'); $('[name="company"]').val('DEMO公司'); $('[name="market_scope"]').val('domestic'); $('[name="currency"]').val('CNY');
        $('[name="model_id"]').val('1'); $('[name="quantity"]').val('1'); $('[name="model_id"]').trigger('change');
    });
    // GYTAI-84：配置改为交互控件，选择型号后加载配置组（power_level 默认 standard 预填）
    await page.waitForFunction(() => document.querySelectorAll('#cpq-config-groups [data-group-code]').length > 0, {timeout: 20000});
    await page.waitForFunction(() => !document.querySelector('#cpq-accessory-add').disabled, {timeout: 20000});
    const interactiveConfig = await page.evaluate(() => {
        const groups = Array.from(document.querySelectorAll('#cpq-config-groups [data-group-code]')).map(x => x.getAttribute('data-group-code'));
        // 交互选择：features 勾选 monitoring，quantity 配置组填 1，加购一行配件再移除
        $('#cpq-config-groups [name="cpq-cfg-features"][value="monitoring"]').prop('checked', true).trigger('change');
        $('#cpq-config-groups [name="cpq-cfg-quantity"]').val('1').trigger('input');
        $('#cpq-accessory-add').click();
        $('#cpq-accessory-rows select').first().val($('#cpq-accessory-rows select option:last').val()).trigger('change');
        $('#cpq-accessory-rows input[type="number"]').val('2').trigger('input');
        const withAccessory = $('#cpq-config-json pre').text();
        $('#cpq-accessory-rows .btn-danger').click();
        return {groups, withAccessory, afterRemove: $('#cpq-config-json pre').text()};
    });
    check(interactiveConfig.groups.includes('power_level') && interactiveConfig.groups.includes('features'), 'GYTAI-84 配置组交互渲染', JSON.stringify(interactiveConfig.groups));
    check(interactiveConfig.withAccessory.includes('"accessories": [\n    {\n      "id":'), 'GYTAI-84 加购行进入提交负载', interactiveConfig.withAccessory);
    check(interactiveConfig.afterRemove.includes('"accessories": []'), 'GYTAI-84 移除加购行后负载清空', interactiveConfig.afterRemove);
    await page.evaluate(() => { $('#cpq-explain').click(); });
    await page.waitForFunction(() => (!document.querySelector('#cpq-pricing-result').classList.contains('hidden') && document.querySelector('#cpq-total').innerText !== '—') || !document.querySelector('#cpq-pricing-error').classList.contains('hidden'), {timeout:20000});
    const pricingError = await page.evaluate(() => $('#cpq-pricing-error').hasClass('hidden') ? '' : $('#cpq-pricing-error').text());
    if (pricingError) throw new Error('P36 试算失败：' + pricingError);
    const simulation = await page.evaluate(() => ({total:$('#cpq-total').text().trim(),approval:$('#cpq-approval').text().trim(),submit:$('#cpq-submittable').text().trim(),steps:document.querySelectorAll('.trace-step').length,text:$('#cpq-pricing-result').text()}));
    check(simulation.total === '136165.0000', 'P36 Decimal 总额原样展示', simulation.total);
    check(simulation.approval === '无需审批' && simulation.submit === '允许', 'P36 控制价边界与审批级别');
    check(simulation.steps === 14, 'P36 固定 14 步规则轨迹', String(simulation.steps));
    check(!simulation.text.includes('60000.0000') && !simulation.text.includes('96000.0000'), 'P36 脱敏轨迹不含成本/公司控制价值');
    await page.screenshot({path:SHOTS + '/m2-pricing-trace.png',fullPage:true});
    for (const item of [
        {discount:'0.9', label:'需产线审批', submit:'允许'},
        {discount:'0.85', label:'需公司审批', submit:'允许'},
        {discount:'0.75', label:'禁止提交', submit:'禁止'}
    ]) {
        await page.evaluate((discount) => { $('[name="manual_discount"]').val(discount); $('[name="discount_reason"]').val('浏览器边界验收'); $('#cpq-calculate').click(); }, item.discount);
        await page.waitForFunction((label) => $('#cpq-lines .label').first().text().trim() === label, {timeout:20000}, item.label);
        const state = await page.evaluate(() => ({label:$('#cpq-lines .label').first().text().trim(),submit:$('#cpq-submittable').text().trim()}));
        check(state.label === item.label && state.submit === item.submit, 'P36 三层边界 ' + item.discount, JSON.stringify(state));
    }
    await page.screenshot({path:SHOTS + '/m2-pricing-boundaries.png',fullPage:true});
    check(errors.length === 0, '页面无 5xx / JavaScript 异常', errors.join(' | '));
    await browser.close();
    if (results.some(x => !x.ok)) process.exitCode = 1;
})().catch(async error => { console.error(error); process.exitCode = 1; if (browser) await browser.close(); });
