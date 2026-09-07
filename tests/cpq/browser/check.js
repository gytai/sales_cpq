/**
 * GYTAI-65 浏览器验收脚本（M1 产品中心与配置器前端）。
 *
 * 运行方式（本机需有 Chrome；puppeteer-core 安装在 runtime/temp/cpq_browser）：
 *   NODE_PATH=runtime/temp/cpq_browser/node_modules node tests/cpq/browser/check.js
 *
 * 环境变量覆盖（默认值对应 docker/README.md 的本地开发环境）：
 *   CPQ_BASE       后台入口 URL（默认 http://127.0.0.1:8082/azbMYFuJTB.php）
 *   CPQ_ADMIN_PASSWORD  管理员密码（默认 Admin@123456，本地开发库）
 *   CHROME_PATH    Chrome 可执行文件路径
 *
 * 覆盖：登录、菜单、列表/详情/版本操作、发布不可编辑、引用删除提示、
 * 配置器 C-001~C-004、规则编辑器（结构化编辑/JSON 预览/单规则测试/冲突检测 C-005）、
 * 版本复制 C-006、绕过前端直调服务端 C-007、全页面 Console 错误收集。
 *
 * 前置：库中不得残留 CPQ-TEST-CYCLE-R1/R2 规则与本脚本产生的系列草稿；
 * 如需清理（Docker 开发库）：
 *   docker exec sales_cpq-mysql-1 mysql -uroot -proot fastadmin -e \
 *     "delete from fa_cpq_config_rule where code like 'CPQ-TEST-%'; \
 *      delete from fa_cpq_product_series where code='CPQ-DEMO-SERIES' and version>1;"
 */
const puppeteer = require('puppeteer-core');

const BASE = process.env.CPQ_BASE || 'http://127.0.0.1:8082/azbMYFuJTB.php';
const ADMIN_PASSWORD = process.env.CPQ_ADMIN_PASSWORD || 'Admin@123456';
const SHOTS = __dirname + '/../../../runtime/temp/cpq_browser/shots';
const CHROME = process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const results = [];
let consoleErrors = [];

function pass(name, extra) {
    results.push(['PASS', name, extra || '']);
    console.log('[PASS]', name, extra || '');
}
function fail(name, extra) {
    results.push(['FAIL', name, extra || '']);
    console.log('[FAIL]', name, extra || '');
}
async function shot(page, name) {
    await page.screenshot({path: `${SHOTS}/${name}.png`, fullPage: true});
    console.log('  [shot]', name + '.png');
}
async function openPage(page, path) {
    await page.goto(BASE + path, {waitUntil: 'networkidle0', timeout: 30000});
}
function watchConsole(page) {
    page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        const text = msg.text();
        // 忽略 favicon/图片资源 404，JS 模块加载失败会以 pageerror 形式另行捕获
        if (/favicon|\.ico|\.png|\.jpg/i.test(text) && /Failed to load resource/i.test(text)) return;
        consoleErrors.push(text);
    });
    page.on('pageerror', (err) => consoleErrors.push(String(err)));
    page.on('response', (resp) => {
        if (resp.status() >= 500) {
            console.log('  [HTTP' + resp.status() + ']', resp.url().split('8082')[1]);
            consoleErrors.push('HTTP ' + resp.status() + ' ' + resp.url());
        }
    });
}

// 在页面上下文里调用 FastAdmin Ajax（带会话），返回 {code,msg,data}
async function api(page, url, data) {
    return page.evaluate(async (url, data) => {
        const ret = await new Promise((resolve) => {
            $.ajax({
                url: url, type: 'POST', dataType: 'json', data: data,
                success: resolve, error: (xhr) => resolve({code: xhr.status, msg: xhr.statusText})
            });
        });
        return ret;
    }, url, data);
}

async function layerConfirm(page) {
    await page.waitForSelector('.layui-layer-btn0', {visible: true, timeout: 5000});
    // 等 layer 滑入动画结束，避免点击落空
    await new Promise((r) => setTimeout(r, 400));
    await page.click('.layui-layer-btn0');
}
async function waitTableRows(page) {
    await page.waitForFunction(
        () => document.querySelectorAll('#table tbody tr').length > 0
            && !document.querySelector('#table tbody tr.no-records-found'),
        {timeout: 15000, polling: 300}
    );
}
// 找到包含指定文本的行
async function findRow(page, text) {
    return page.evaluateHandle((text) => {
        const rows = Array.from(document.querySelectorAll('#table tbody tr'));
        return rows.find((tr) => tr.innerText.includes(text)) || null;
    }, text);
}

async function setConfig(page, cfg) {
    await page.evaluate((cfg) => {
        // 先清空全部输入
        $('#cpq-groups input[type=radio]').prop('checked', false);
        $('#cpq-groups input[type=checkbox]').prop('checked', false);
        Object.entries(cfg).forEach(([code, value]) => {
            const name = 'cpq-' + code;
            const inputs = $(`[name="${name}"]`);
            if (!inputs.length) return;
            const type = inputs.first().attr('type');
            if (type === 'radio') {
                inputs.filter(`[value="${value}"]`).prop('checked', true);
            } else if (type === 'checkbox') {
                (Array.isArray(value) ? value : [value]).forEach((v) => {
                    inputs.filter(`[value="${v}"]`).prop('checked', true);
                });
            } else {
                inputs.val(value);
            }
        });
        $('#cpq-groups input').first().trigger('change');
    }, cfg);
}
async function waitValidation(page, marker) {
    await page.waitForFunction(
        (marker) => (document.querySelector('#cpq-validation') || {}).innerText
            && document.querySelector('#cpq-validation').innerText.includes(marker),
        {timeout: 15000, polling: 300}, marker
    );
}
// 读取当前校验序号（#cpq-validation[data-seq]），配合 waitFreshValidation 防止读到过期结果
async function validationSeq(page) {
    return page.evaluate(() => parseInt((document.querySelector('#cpq-validation') || {}).dataset && document.querySelector('#cpq-validation').dataset.seq || '0', 10));
}
async function waitFreshValidation(page, prevSeq, marker) {
    await page.waitForFunction(
        (prevSeq, marker) => {
            const el = document.querySelector('#cpq-validation');
            if (!el || !el.innerText) return false;
            const seq = parseInt(el.dataset.seq || '0', 10);
            return seq > prevSeq && el.innerText.includes(marker);
        },
        {timeout: 15000, polling: 300}, prevSeq, marker
    );
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: 'new',
        args: ['--no-sandbox', '--window-size=1440,900']
    });
    const page = await browser.newPage();
    await page.setViewport({width: 1440, height: 900});
    watchConsole(page);

    // ---------- 登录 ----------
    await openPage(page, '/index/login');
    await page.type('#pd-form-username', 'admin');
    await page.type('#pd-form-password', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForNavigation({waitUntil: 'networkidle0', timeout: 30000}),
        page.click('#login-form button[type=submit]')
    ]);
    const menuText = await page.evaluate(() => document.body.innerText);
    menuText.includes('CPQ产品中心')
        ? pass('登录后台并展示 CPQ产品中心菜单')
        : fail('登录后台', '菜单中未找到 CPQ产品中心');

    // ---------- 产品系列列表：按钮守卫 + 版本复制（C-006）----------
    await openPage(page, '/cpq/product_series/index');
    await waitTableRows(page);
    let row = await findRow(page, 'CPQ-DEMO-SERIES');
    const seriesButtons = await page.evaluate((tr) => ({
        edit: tr.querySelectorAll('.btn-editone').length,
        del: tr.querySelectorAll('.btn-delone').length,
        detail: tr.querySelectorAll('.btn-dialog').length,
        copy: Array.from(tr.querySelectorAll('.btn-ajax')).filter((a) => a.innerText.includes('复制新版本')).length
    }), row);
    (seriesButtons.edit === 0 && seriesButtons.del === 0)
        ? pass('发布不可编辑：已发布系列行隐藏编辑/删除按钮')
        : fail('发布不可编辑', JSON.stringify(seriesButtons));
    (seriesButtons.detail === 1 && seriesButtons.copy === 1)
        ? pass('版本操作：已发布系列行展示详情/复制新版本按钮')
        : fail('版本操作按钮', JSON.stringify(seriesButtons));

    // 只读详情
    await openPage(page, '/cpq/product_series/detail/ids/1');
    await page.waitForSelector('form[role=form]', {timeout: 10000, polling: 300});
    await new Promise((r) => setTimeout(r, 800));
    const detailState = await page.evaluate(() => ({
        disabledInputs: $('form[role=form]').find('input,select,textarea').filter(':disabled').length,
        totalInputs: $('form[role=form]').find('input,select,textarea').length,
        footerHidden: $('.layer-footer').is(':hidden'),
        code: $('[name="row[code]"]').val()
    }));
    (detailState.totalInputs > 0 && detailState.disabledInputs === detailState.totalInputs && detailState.footerHidden && detailState.code === 'CPQ-DEMO-SERIES')
        ? pass('详情页只读渲染', JSON.stringify(detailState))
        : fail('详情页只读渲染', JSON.stringify(detailState));
    await shot(page, '02-series-detail');

    // 复制新版本（C-006 前端流）→ 产生 v2 草稿 → 再删除草稿清理
    await openPage(page, '/cpq/product_series/index');
    await waitTableRows(page);
    row = await findRow(page, 'CPQ-DEMO-SERIES');
    await page.evaluate((tr) => {
        Array.from(tr.querySelectorAll('.btn-ajax')).find((a) => a.innerText.includes('复制新版本')).click();
    }, row);
    await layerConfirm(page);
    // 等待服务端处理完成（成功或失败都会有 toast），然后重新打开页面断言数据行
    await page.waitForFunction(
        () => document.querySelectorAll('.toast-message').length > 0,
        {timeout: 10000, polling: 300}
    );
    const copyToast = await page.evaluate(() =>
        Array.from(document.querySelectorAll('.toast-message')).map((e) => e.innerText).join('|'));
    console.log('  [toast]', copyToast);
    // 刷新是异步的，重新打开页面再断言
    await openPage(page, '/cpq/product_series/index');
    await waitTableRows(page);
    const hasV2Draft = await page.evaluate(() =>
        Array.from(document.querySelectorAll('#table tbody tr')).some((tr) =>
            tr.innerText.includes('CPQ-DEMO-SERIES') && tr.innerText.includes('草稿')));
    hasV2Draft ? pass('C-006 复制新版本：已发布系列复制出 v2 草稿') : fail('C-006 复制新版本', '未出现 v2 草稿行');
    // 删除 v2 草稿（草稿可删，验证引用提示通道不影响正常删除）
    row = await page.evaluateHandle(() =>
        Array.from(document.querySelectorAll('#table tbody tr')).find((tr) =>
            tr.innerText.includes('CPQ-DEMO-SERIES') && tr.innerText.includes('草稿')) || null);
    const draftRowExists = await page.evaluate((tr) => !!tr, row);
    if (!draftRowExists) {
        fail('草稿删除', '未找到 v2 草稿行');
    } else {
        await page.evaluate((tr) => tr.querySelector('.btn-delone').click(), row);
        await layerConfirm(page);
        await new Promise((r) => setTimeout(r, 1500));
        await openPage(page, '/cpq/product_series/index');
        await waitTableRows(page);
        const v2Gone = await page.evaluate(() =>
            !Array.from(document.querySelectorAll('#table tbody tr')).some((tr) =>
                tr.innerText.includes('CPQ-DEMO-SERIES') && tr.innerText.includes('草稿')));
        v2Gone ? pass('草稿版本可删除（清理复制产物）') : fail('草稿删除', 'v2 草稿仍在列表');
    }
    await shot(page, '01-series-list');

    // ---------- 表单必填校验 ----------
    await openPage(page, '/cpq/product_series/add?dialog=1');
    await page.waitForSelector('form[role=form]', {timeout: 10000, polling: 300});
    await page.evaluate(() => $('form[role=form] button[type=submit]').removeClass('disabled').click());
    await new Promise((r) => setTimeout(r, 600));
    const hasError = await page.evaluate(() =>
        $('.n-error').filter(':visible').length > 0
        || $('.msg-wrap').filter((_, el) => $(el).text().trim().length > 0).length > 0);
    hasError ? pass('新增表单空提交出现必填中文校验提示') : fail('新增表单校验', '未发现校验错误标记');
    await shot(page, '07-form-validation');

    // ---------- 配置器：C-001~C-004 + BOM 模拟 ----------
    await openPage(page, '/cpq/configurator/index');
    await page.waitForSelector('#cpq-model', {timeout: 10000, polling: 300});
    const modelId = await page.evaluate(() => {
        const opt = Array.from(document.querySelectorAll('#cpq-model option'))
            .find((o) => o.innerText.includes('CPQ-DEMO-EQUIPMENT-A'));
        return opt ? opt.value : '';
    });
    if (!modelId) {
        fail('配置器加载型号', '未找到 CPQ-DEMO-EQUIPMENT-A');
    } else {
    try {
        await page.select('#cpq-model', modelId);
        await page.click('#cpq-load');
        await page.waitForSelector('#cpq-groups .cpq-group', {timeout: 15000, polling: 300});
        const groupCount = await page.evaluate(() => $('#cpq-groups .cpq-group').length);
        const navCount = await page.evaluate(() => $('#cpq-group-nav .list-group-item').length);
        (groupCount === 6 && navCount === 6)
            ? pass('配置器加载：6 个配置组 + 分组导航')
            : fail('配置器加载', `groups=${groupCount} nav=${navCount}`);

        // 初始自动校验完成（data-seq >= 1）
        await page.waitForFunction(
            () => parseInt((document.querySelector('#cpq-validation') || {}).dataset
                && document.querySelector('#cpq-validation').dataset.seq || '0', 10) >= 1,
            {timeout: 15000, polling: 300});

        // C-002 依赖 + C-003 互斥 + C-004 数量上限（一次构造三种非法）
        let seq = await validationSeq(page);
        await setConfig(page, {power_level: 'high', cooling_level: 'standard', features: ['remote', 'offline'], quantity: '11'});
        await waitFreshValidation(page, seq, '配置不合法');
        const invalidInfo = await page.evaluate(() => ({
            dangerPanels: $('.cpq-group.panel-danger').map((_, p) => $(p).data('group-code')).get(),
            navFlags: $('#cpq-group-nav .label-danger').length,
            errors: $('#cpq-validation .text-danger li').map((_, li) => $(li).text()).get()
        }));
        const hitC002 = invalidInfo.errors.some((t) => t.includes('REQUIRE-COOLING'));
        const hitC003 = invalidInfo.errors.some((t) => t.includes('EXCLUDE-OFFLINE'));
        const hitC004 = invalidInfo.errors.some((t) => t.includes('QUANTITY-RANGE'));
        (hitC002 && hitC003 && hitC004)
            ? pass('C-002/C-003/C-004 依赖、互斥、数量上限均由服务端检出并定位')
            : fail('C-002/C-003/C-004', JSON.stringify(invalidInfo.errors));
        (invalidInfo.dangerPanels.length >= 3 && invalidInfo.navFlags >= 3)
            ? pass('服务端错误定位：面板标红 + 导航角标', JSON.stringify(invalidInfo.dangerPanels))
            : fail('错误定位标红', JSON.stringify(invalidInfo));
        await shot(page, '03-configurator-invalid');

        // C-001 必选项未选择：清空多选 features
        seq = await validationSeq(page);
        await setConfig(page, {power_level: 'standard', cooling_level: 'standard', features: [], quantity: '2'});
        await waitFreshValidation(page, seq, '配置不合法');
        const c001 = await page.evaluate(() => ({
            errors: $('#cpq-validation .text-danger li').map((_, li) => $(li).text()).get(),
            links: $('#cpq-validation .text-danger a').length
        }));
        c001.errors.some((t) => t.includes('必填'))
            ? pass('C-001 必选项未选择被阻止并定位配置组')
            : fail('C-001', JSON.stringify(c001.errors));

        // 合法配置 → BOM 模拟
        seq = await validationSeq(page);
        await setConfig(page, {power_level: 'high', cooling_level: 'enhanced', features: ['monitoring', 'remote'], quantity: '2'});
        await waitFreshValidation(page, seq, '配置合法');
        await page.click('#cpq-bom-run');
        try {
            await page.waitForFunction(
                () => document.querySelectorAll('#cpq-bom tr').length > 0
                    && !document.querySelector('#cpq-bom .text-muted'), {timeout: 10000, polling: 300});
        } catch (e) {
            const diag = await page.evaluate(() => ({
                bom: document.querySelector('#cpq-bom').innerHTML.slice(0, 200),
                validation: document.querySelector('#cpq-validation').innerText.slice(0, 120),
                toasts: Array.from(document.querySelectorAll('.toast-message')).map((el) => el.innerText),
                btnDisabled: document.querySelector('#cpq-bom-run').disabled,
                predRows: document.querySelectorAll('#cpq-bom tr').length,
                predMuted: !!document.querySelector('#cpq-bom .text-muted')
            }));
            fail('BOM 模拟', JSON.stringify(diag));
            throw e;
        }
        const bomInfo = await page.evaluate(() => ({
            rows: $('#cpq-bom tr').length,
            summary: $('#cpq-summary').text(),
            hash: $('#cpq-validation').text()
        }));
        (bomInfo.rows > 0 && bomInfo.summary.includes('"power_level"') && bomInfo.hash.includes('配置指纹'))
            ? pass('合法配置：校验通过 + 配置摘要含 hash + BOM 模拟生成', `bom_rows=${bomInfo.rows}`)
            : fail('合法配置/BOM', JSON.stringify(bomInfo));
        await shot(page, '04-configurator-valid');

        // C-007 绕过前端直调服务端 BOM（非法配置必须被拒绝）
        const bypass = await page.evaluate(async () => {
            const ret = await new Promise((resolve) => {
                $.ajax({
                    url: 'cpq/configurator/bom', type: 'POST', dataType: 'json',
                    data: {model_id: $('#cpq-model').val(), configuration: JSON.stringify({power_level: 'high', cooling_level: 'standard', features: ['remote', 'offline'], quantity: 99})},
                    success: resolve, error: (xhr) => resolve({code: xhr.status, msg: xhr.statusText})
                });
            });
            return ret;
        });
        (bypass.code !== 1 && String(bypass.msg).includes('不合法'))
            ? pass('C-007 绕过前端直调服务端被拒绝', String(bypass.msg))
            : fail('C-007', JSON.stringify(bypass));
    } catch (e) {
        fail('配置器流程中断', String(e.message || e).slice(0, 160));
    }
    }

    // ---------- 规则编辑器：结构化编辑/JSON 预览/单规则测试/冲突检测（C-005）----------
    try {
    // 造环：R1 读 calculated_capacity 写 quantity；R2 读 quantity 写 calculated_capacity
    const r1 = await api(page, 'cpq/config_rule/add', {
        'row[code]': 'CPQ-TEST-CYCLE-R1', 'row[name]': '测试环R1', 'row[type]': 'FORMULA',
        'row[model_id]': '', 'row[product_line]': 'CPQ-TEST-LINE',
        'row[condition_json]': JSON.stringify({field: 'configuration.calculated_capacity', operator: 'not_empty'}),
        'row[action_json]': JSON.stringify([{action: 'set', target: 'quantity', value: 5}]),
        'row[priority]': 0, 'row[severity]': 'blocking', 'row[message]': '', 'row[description]': '',
        'row[version]': 1, 'row[effective_date]': '', 'row[expiry_date]': '', 'row[status]': 'draft'
    });
    // R1 发布需要型号维度或产线维度规则集无冲突；此处 R1 为产线级规则
    const r1Publish = r1.code === 1 ? await page.evaluate(async () => {
        const list = await new Promise((r) => $.ajax({url: 'cpq/config_rule/index', type: 'GET', dataType: 'json', data: {filter: JSON.stringify({code: 'CPQ-TEST-CYCLE-R1'}), op: JSON.stringify({code: '='})}, success: r}));
        const id = list.rows && list.rows[0] ? list.rows[0].id : 0;
        if (!id) return {code: 0, msg: 'R1 未入库'};
        const submit = await new Promise((r) => $.ajax({url: 'cpq/config_rule/submit', type: 'POST', dataType: 'json', data: {ids: id}, success: r, error: (x) => r({code: x.status, msg: x.statusText})}));
        if (submit.code !== 1) return submit;
        return await new Promise((r) => $.ajax({url: 'cpq/config_rule/publish', type: 'POST', dataType: 'json', data: {ids: id}, success: r, error: (x) => r({code: x.status, msg: x.statusText})}));
    }) : r1;
    (r1Publish.code === 1) ? pass('C-005 前置：产线级规则 R1 发布成功') : fail('C-005 前置 R1 发布', JSON.stringify(r1Publish));

    // 通过新增页 UI 创建 R2（结构化编辑器真实操作）
    await openPage(page, '/cpq/config_rule/add?dialog=1');
    await page.waitForSelector('#cpq-cond-add', {timeout: 10000, polling: 300});
    await new Promise((r) => setTimeout(r, 500));
    await page.type('[name="row[code]"]', 'CPQ-TEST-CYCLE-R2');
    await page.type('[name="row[name]"]', '测试环R2');
    await page.type('[name="row[product_line]"]', 'CPQ-TEST-LINE');
    // 结构化条件：configuration.quantity not_empty
    await page.click('#cpq-cond-add');
    await page.type('#cpq-cond-list .cpq-cond-field', 'configuration.quantity');
    await page.select('#cpq-cond-list .cpq-cond-operator', 'not_empty');
    // 结构化动作：set calculated_capacity = 10
    await page.click('#cpq-act-add');
    await page.select('#cpq-act-list .cpq-act-type', 'set');
    await page.type('#cpq-act-list .cpq-act-target', 'calculated_capacity');
    await page.type('#cpq-act-list .cpq-act-value', '10');
    await page.evaluate(() => $('#cpq-act-list .cpq-act-value').trigger('input'));
    const jsonPreview = await page.evaluate(() => ({
        cond: $('#cpq-cond-json').val(), act: $('#cpq-act-json').val()
    }));
    (jsonPreview.cond.includes('configuration.quantity') && jsonPreview.act.includes('calculated_capacity'))
        ? pass('规则编辑器：结构化编辑同步 JSON 预览')
        : fail('规则编辑器 JSON 同步', JSON.stringify(jsonPreview));

    // 冲突检测（C-005：与已发布 R1 构成循环依赖）
    await page.click('#cpq-analyze-run');
    await page.waitForFunction(
        () => (document.querySelector('#cpq-analyze-result') || {}).innerText
            && document.querySelector('#cpq-analyze-result').innerText.length > 10, {timeout: 10000, polling: 300});
    const analyzeText = await page.evaluate(() => $('#cpq-analyze-result').text());
    (analyzeText.includes('循环依赖') && analyzeText.includes('CPQ-TEST-CYCLE-R1'))
        ? pass('C-005 冲突检测：循环依赖结构化展示')
        : fail('C-005 冲突检测', analyzeText.slice(0, 200));
    await shot(page, '06-rule-analyze');

    // 单规则测试
    await page.evaluate(() => { document.querySelector('#cpq-test-config').value = '{"quantity": 3}'; });
    // 单规则测试要求选择适用型号（selectpage 隐藏域直接赋值）
    await page.evaluate((modelId) => {
        $('[name="row[model_id]"]').val(modelId).trigger('change');
    }, modelId);
    await page.click('#cpq-test-run');
    await page.waitForFunction(
        () => (document.querySelector('#cpq-test-result') || {}).innerText.includes('规则'), {timeout: 10000, polling: 300});
    const testText = await page.evaluate(() => $('#cpq-test-result').text());
    (testText.includes('规则命中') && testText.includes('calculated_capacity'))
        ? pass('单规则测试：命中并返回试算配置')
        : fail('单规则测试', testText.slice(0, 200));
    await shot(page, '05-rule-editor');

    // 保存 R2（草稿）→ 发布应失败（C-005 发布期拦截）
    const r2 = await api(page, 'cpq/config_rule/add', {
        'row[code]': 'CPQ-TEST-CYCLE-R2', 'row[name]': '测试环R2', 'row[type]': 'FORMULA',
        'row[model_id]': '', 'row[product_line]': 'CPQ-TEST-LINE',
        'row[condition_json]': jsonPreview.cond, 'row[action_json]': jsonPreview.act,
        'row[priority]': 0, 'row[severity]': 'blocking', 'row[message]': '', 'row[description]': '',
        'row[version]': 1, 'row[effective_date]': '', 'row[expiry_date]': '', 'row[status]': 'draft'
    });
    if (r2.code === 1) {
        const r2Publish = await page.evaluate(async () => {
            const list = await new Promise((r) => $.ajax({url: 'cpq/config_rule/index', type: 'GET', dataType: 'json', data: {filter: JSON.stringify({code: 'CPQ-TEST-CYCLE-R2'}), op: JSON.stringify({code: '='})}, success: r}));
            const id = list.rows && list.rows[0] ? list.rows[0].id : 0;
            if (!id) return {code: 0, msg: 'R2 未入库'};
            const submit = await new Promise((r) => $.ajax({url: 'cpq/config_rule/submit', type: 'POST', dataType: 'json', data: {ids: id}, success: r}));
            if (submit.code !== 1) return submit;
            return await new Promise((r) => $.ajax({url: 'cpq/config_rule/publish', type: 'POST', dataType: 'json', data: {ids: id}, success: r}));
        });
        (r2Publish.code !== 1 && String(r2Publish.msg).includes('循环依赖'))
            ? pass('C-005 新规则形成循环依赖发布失败', String(r2Publish.msg).slice(0, 80))
            : fail('C-005 发布拦截', JSON.stringify(r2Publish).slice(0, 200));
    } else {
        fail('C-005 R2 草稿创建', JSON.stringify(r2).slice(0, 200));
    }
    } catch (e) {
        fail('规则编辑器流程中断', String(e.message || e).slice(0, 160));
    }

    // ---------- 引用影响提示：删除被引用的配置组 ----------
    try {
    await openPage(page, '/cpq/option_group/index');
    await waitTableRows(page);
    row = await findRow(page, 'features');
    await page.evaluate((tr) => tr.querySelector('.btn-delone').click(), row);
    await layerConfirm(page);
    await page.waitForFunction(
        () => Array.from(document.querySelectorAll('.layui-layer')).some((el) => el.innerText.includes('无法删除')),
        {timeout: 10000, polling: 300});
    const refText = await page.evaluate(() =>
        Array.from(document.querySelectorAll('.layui-layer')).map((el) => el.innerText).join('\n'));
    (refText.includes('无法删除') && refText.includes('被') && refText.includes('引用'))
        ? pass('删除被引用配置组：弹窗展示引用来源')
        : fail('引用影响提示', refText.slice(0, 200));
    await shot(page, '08-reference-blocked');
    await page.evaluate(() => layer.closeAll());
    } catch (e) {
        fail('引用影响提示流程中断', String(e.message || e).slice(0, 160));
    }

    // ---------- 汇总 ----------
    const failed = results.filter((r) => r[0] === 'FAIL');
    console.log('\n===== 浏览器验证结果 =====');
    console.log(`PASS ${results.length - failed.length} / FAIL ${failed.length}`);
    console.log(`Console 错误数: ${consoleErrors.length}`);
    if (consoleErrors.length) {
        console.log(consoleErrors.slice(0, 10).join('\n'));
    }
    await browser.close();
    process.exit(failed.length || consoleErrors.length ? 1 : 0);
})().catch((err) => {
    console.error('SCRIPT_ERROR', err);
    process.exit(2);
});
