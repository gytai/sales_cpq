define(['jquery', 'bootstrap', 'backend', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Form, CpqCommon) {
    'use strict';
    var lastSafeResult = null;
    // ------------------------------------------------------------------
    // 交互配置（GYTAI-84）：配置组与配件/服务不再手工编辑 JSON，选择产品
    // 型号后从 cpq/pricing/context 拉取配置结构与配件清单渲染控件；
    // 合法性校验仍在 calculate/explain 服务端执行，前端只收集选择结果。
    // ------------------------------------------------------------------
    var currentSchema = null;
    var accessoryCatalog = [];
    function escape(value) { return $('<span>').text(value == null ? '' : String(value)).html(); }

    function collectConfiguration() {
        var configuration = {};
        $.each((currentSchema && currentSchema.groups) || [], function (_, group) {
            if (group.input_type === 'readonly') { return; }
            var name = 'cpq-cfg-' + group.code;
            if (group.input_type === 'multiple') {
                var selected = [];
                $('[name="' + name + '"]:checked').each(function () { selected.push($(this).val()); });
                if (selected.length) { configuration[group.code] = selected; }
            } else {
                var field = $('[name="' + name + '"]');
                var value = group.input_type === 'single' ? field.filter(':checked').val() : field.val();
                if (value !== undefined && value !== '') { configuration[group.code] = value; }
            }
        });
        return configuration;
    }

    function collectAccessories() {
        var accessories = [];
        $('#cpq-accessory-rows > .row').each(function () {
            var id = $.trim($(this).find('select').val() || '');
            if (!id) { return; }
            accessories.push({id: id, quantity: $(this).find('input[type="number"]').val() || '1'});
        });
        return accessories;
    }

    function updateConfigPreview() {
        $('#cpq-config-json pre').text(JSON.stringify({
            configuration: collectConfiguration(),
            accessories: collectAccessories()
        }, null, 2));
    }

    function renderConfigGroups() {
        var container = $('#cpq-config-groups').empty();
        var groups = (currentSchema && currentSchema.groups) || [];
        var renderable = $.grep(groups, function (group) { return group.input_type !== 'readonly'; });
        if (!renderable.length) {
            container.html('<p class="help-block" style="margin-bottom:0;">' + (groups.length ? '该型号仅含自动计算项，无需选择。' : '该型号没有已发布的配置组，按基础价计价。') + '</p>');
            updateConfigPreview();
            return;
        }
        $.each(renderable, function (_, group) {
            var panel = $('<div class="panel panel-default" style="margin-bottom:8px;"></div>').attr('data-group-code', group.code);
            var heading = $('<div class="panel-heading" style="padding:6px 10px;"></div>');
            heading.append($('<strong></strong>').text(group.name));
            if (group.is_required) {
                heading.append(' ').append('<span class="label label-danger">必选</span>');
            }
            heading.append($('<code class="pull-right"></code>').text(group.code));
            var body = $('<div class="panel-body" style="padding:6px 10px;"></div>');
            if (group.help_text) {
                body.append($('<p class="help-block" style="margin-bottom:6px;"></p>').text(group.help_text));
            }
            if (group.input_type === 'single') {
                $.each(group.options || [], function (_, option) {
                    var label = $('<label class="radio-inline"></label>');
                    var input = $('<input type="radio">').attr({name: 'cpq-cfg-' + group.code, value: option.code});
                    if (group.default_value === option.code) {
                        input.prop('checked', true);
                    }
                    label.append(input).append(document.createTextNode(' ' + option.name));
                    body.append(label);
                });
            } else if (group.input_type === 'multiple') {
                var defaults = $.isArray(group.default_value) ? group.default_value : [];
                $.each(group.options || [], function (_, option) {
                    var label = $('<label class="checkbox-inline"></label>');
                    var input = $('<input type="checkbox">').attr({name: 'cpq-cfg-' + group.code, value: option.code});
                    if ($.inArray(option.code, defaults) !== -1) {
                        input.prop('checked', true);
                    }
                    label.append(input).append(document.createTextNode(' ' + option.name));
                    body.append(label);
                });
            } else if (group.input_type === 'number') {
                body.append($('<input type="number" step="any" class="form-control input-sm">')
                    .attr('name', 'cpq-cfg-' + group.code)
                    .val(group.default_value === null || group.default_value === undefined ? '' : group.default_value));
            } else {
                body.append($('<input type="text" class="form-control input-sm">')
                    .attr('name', 'cpq-cfg-' + group.code)
                    .val(group.default_value === null || group.default_value === undefined ? '' : group.default_value));
            }
            panel.append(heading).append(body);
            container.append(panel);
        });
        updateConfigPreview();
    }

    function accessoryOptionLabel(item) {
        var label = item.code + ' · ' + item.name;
        if (item.type) { label += '（' + item.type + '）'; }
        if (item.unit) { label += ' 单位 ' + item.unit; }
        return label;
    }

    function appendAccessoryRow() {
        var row = $('<div class="row" style="margin-bottom:6px;"></div>');
        // 控件不带 name，避免混入表单序列化；数值统一以字符串提交，由服务端解析
        var select = $('<select class="form-control input-sm"></select>');
        select.append($('<option value=""></option>').text('请选择配件/服务'));
        var lines = {};
        $.each(accessoryCatalog, function (_, item) {
            var line = item.product_line || '通用';
            (lines[line] = lines[line] || []).push(item);
        });
        $.each(Object.keys(lines).sort(), function (_, line) {
            var group = $('<optgroup></optgroup>').attr('label', '产品线 ' + line);
            $.each(lines[line], function (_, item) {
                group.append($('<option></option>').attr('value', item.id).text(accessoryOptionLabel(item)));
            });
            select.append(group);
        });
        var quantity = $('<input type="number" min="1" step="1" class="form-control input-sm">').val('1');
        var remove = $('<button type="button" class="btn btn-danger btn-sm" title="移除本行"><i class="fa fa-trash"></i></button>');
        remove.on('click', function () { row.remove(); updateConfigPreview(); });
        row.append($('<div class="col-xs-7"></div>').append(select))
            .append($('<div class="col-xs-3"></div>').append(quantity))
            .append($('<div class="col-xs-2"></div>').append(remove));
        $('#cpq-accessory-rows').append(row);
        updateConfigPreview();
    }

    function resetInteractiveConfig() {
        currentSchema = null;
        accessoryCatalog = [];
        $('#cpq-accessory-rows').empty();
        $('#cpq-accessory-add').prop('disabled', true);
        updateConfigPreview();
    }

    function loadContext() {
        var modelId = $.trim($('[name="model_id"]').val() || '');
        resetInteractiveConfig();
        if (!modelId) {
            $('#cpq-config-groups').html('<p class="help-block" style="margin-bottom:0;">选择产品型号后按配置组交互选择，默认值已预填。</p>');
            return;
        }
        Fast.api.ajax({url: 'cpq/pricing/context', type: 'get', data: {model_id: modelId}}, function (context) {
            currentSchema = (context && context.schema) || null;
            accessoryCatalog = (context && context.accessories) || [];
            renderConfigGroups();
            $('#cpq-accessory-add').prop('disabled', !accessoryCatalog.length);
            return false;
        }, function (data, ret) {
            $('#cpq-config-groups').html('<p class="text-warning" style="margin-bottom:0;">' + escape((ret && ret.msg) || '加载配置结构失败') + '</p>');
            return false;
        });
    }

    function payload() {
        var data = {};
        $.each($('#cpq-pricing-form').serializeArray(), function (_, item) {
            // 配置组交互输入（cpq-cfg- 前缀）单独收集为 configuration 对象
            if (item.name.indexOf('cpq-cfg-') === 0) { return; }
            data[item.name] = item.value;
        });
        data.model_id = String(data.model_id || '');
        data.customer_id = String(data.customer_id || '');
        data.quantity = String(data.quantity || '1');
        data.configuration = collectConfiguration();
        data.accessories = collectAccessories();
        if (!data.manual_discount) { delete data.manual_discount; delete data.discount_reason; }
        if (!data.currency) { delete data.currency; }
        return data;
    }
    function amountRows(amounts) {
        var labels = {goods:'商品金额',goods_discounted:'折扣后商品金额',fees:'费用',untaxed:'未税金额',tax:'税额',total:'含税金额',converted_total:'换算后金额',control_unit_price:'控制价口径单价',cost_total:'成本合计'};
        var html = '<table class="table table-condensed table-bordered"><tbody>';
        $.each(amounts || {}, function (key, value) { html += '<tr><th>' + escape(labels[key] || key) + '</th><td>' + CpqCommon.moneyFormatter(value) + '</td></tr>'; });
        return html + '</tbody></table>';
    }

    // ------------------------------------------------------------------
    // 执行轨迹可读化渲染（GYTAI-83）：计算过程 + 数据指标 + 引用规则。
    // 金额一律原样字符串输出（moneyFormatter），不做任何前端浮点运算。
    // ------------------------------------------------------------------
    var STEP_LABELS = {
        base_price: '型号基础价',
        option_prices: '选项加价',
        accessory_prices: '配件/服务加价',
        price_rules: '价格规则匹配',
        quantity: '数量放大',
        channel_adjustments: '渠道折扣',
        manual_discount: '手工折扣',
        fees: '费用计算',
        untaxed_amount: '未税金额',
        tax: '税额计算',
        total_with_tax: '含税总额',
        currency_conversion: '汇率换算',
        floor_comparison: '控制价比较',
        approval_level: '审批级别'
    };
    var AMOUNT_LABELS = {goods_discounted:'折扣后商品金额', fees:'费用', untaxed:'未税金额', tax:'税额', total:'含税金额', control_unit:'控制价口径单价', margin_amount:'毛利额'};
    var TARGET_LABELS = {base:'基础价', option:'选项价', service:'配件/服务价', subtotal:'单价小计', freight:'运费'};
    var ADJUSTMENT_LABELS = {fixed:'一口价', amount:'加减额', discount:'折扣比例', factor:'系数'};
    var FEE_TYPE_LABELS = {freight:'运费', insurance:'保险', installation:'安装', other:'其他费用'};
    var CALC_TYPE_LABELS = {fixed:'固定金额', per_quantity:'按数量', percentage:'按折后商品额比例'};
    var TAX_MODE_LABELS = {tax_inclusive:'价内税（含税价倒算税额）', tax_exclusive:'价外税（未税 × 税率）'};
    var APPROVAL_LABELS = {none:'无需审批', line:'产线审批', company:'公司审批', forbidden:'禁止提交'};
    var CHANNEL_SOURCE_LABELS = {agent_level:'代理等级折扣', customer_level:'客户等级折扣'};
    var SUPPRESS_LABELS = {
        exclusive_group: '同互斥组命中更高优先级规则，被抑制',
        non_stackable_target: '同目标已有不可叠加规则生效，被抑制'
    };
    function money(value) { return CpqCommon.moneyFormatter(value); }
    function kv(label, value) {
        return '<div><span class="text-muted">' + escape(label) + '：</span>' + (value == null || value === '' ? '—' : value) + '</div>';
    }
    function ruleTitle(rule) {
        var title = escape(rule.code || ('#' + rule.id));
        if (rule.name) { title += '《' + escape(rule.name) + '》'; }
        if (rule.version != null) { title += ' v' + escape(rule.version); }
        return title;
    }
    function adjustmentText(type, value) {
        return escape(ADJUSTMENT_LABELS[type] || type) + ' ' + money(value);
    }
    function beforeAfterText(before, after) {
        return '<code>' + money(before) + '</code> → <code>' + money(after) + '</code>';
    }
    function qtyRangeText(minQty, maxQty) {
        return escape(minQty == null ? '-' : minQty) + ' ~ ' + (maxQty == null ? '以上' : escape(maxQty));
    }
    function suppressText(state) {
        var text = String(state || '');
        if (text.indexOf('exclusive_group:') === 0) {
            return SUPPRESS_LABELS.exclusive_group + '（互斥组 ' + escape(text.slice(16)) + '）';
        }
        if (text.indexOf('non_stackable_target:') === 0) {
            var target = text.slice(21);
            return SUPPRESS_LABELS.non_stackable_target + '（目标 ' + escape(TARGET_LABELS[target] || target) + '）';
        }
        return escape(text);
    }
    function itemList(rows, headers) {
        var html = '<table class="table table-condensed table-bordered" style="margin-bottom:0;"><thead><tr>';
        $.each(headers, function (_, head) { html += '<th>' + escape(head) + '</th>'; });
        html += '</tr></thead><tbody>';
        $.each(rows, function (_, row) {
            html += '<tr>';
            $.each(row, function (_, cell) { html += '<td>' + cell + '</td>'; });
            html += '</tr>';
        });
        return html + '</tbody></table>';
    }
    function emptyNote(text) { return '<p class="text-muted" style="margin-bottom:0;">' + escape(text) + '</p>'; }
    // 编码 · 名称（GYTAI-85）：当前轨迹 items 携带名称，旧导出文件无名称时仅展示编码
    function codeNameText(code, name) { return escape(code) + (name ? ' · ' + escape(name) : ''); }

    function stepDetail(step) {
        var html = '';
        switch (step.step) {
            case 'base_price':
                if (step.price_book) {
                    html += kv('价格表', ruleTitle(step.price_book) + '（币种 ' + escape(step.price_book.currency) + ' · ' + escape(TAX_MODE_LABELS[step.price_book.tax_mode] || step.price_book.tax_mode) + '）');
                }
                if (step.price_entry) {
                    html += kv('命中条目', '#' + escape(step.price_entry.id) + ' · ' + money(step.price_entry.amount) + ' / ' + escape(step.price_entry.unit) + ' · 数量区间 ' + qtyRangeText(step.price_entry.min_qty, step.price_entry.max_qty));
                }
                return html;
            case 'option_prices':
                if (!step.items || !step.items.length) { return emptyNote('未选择配置选项，按 0 计'); }
                return itemList($.map(step.items, function (item) {
                    return [[
                        codeNameText(item.group_code, item.group_name),
                        codeNameText(item.option_code, item.option_name),
                        money(item.unit_price),
                        escape(item.default_qty),
                        '<strong>' + money(item.amount) + '</strong>' + (item.has_price_entry ? '' : ' <span class="text-warning">（无价格条目，按 0 计）</span>')
                    ]];
                }), ['选项组', '选项', '单价', '数量', '小计']);
            case 'accessory_prices':
                if (!step.items || !step.items.length) { return emptyNote('无配件/服务加购'); }
                return itemList($.map(step.items, function (item) {
                    return [[
                        escape(item.accessory_code),
                        money(item.unit_price),
                        escape(item.quantity),
                        '<strong>' + money(item.amount) + '</strong>'
                    ]];
                }), ['配件/服务', '单价', '数量', '小计']);
            case 'price_rules':
                html = '';
                $.each(step.matched || [], function (_, rule) {
                    html += '<div>' + ruleTitle(rule) + ' · 目标 ' + escape(TARGET_LABELS[rule.target] || rule.target) +
                        ' · ' + adjustmentText(rule.adjustment_type, rule.adjustment_value) +
                        ' · ' + beforeAfterText(rule.before, rule.after) + '</div>';
                });
                $.each(step.suppressed || [], function (_, rule) {
                    html += '<div class="text-muted">' + ruleTitle(rule) + ' — ' + suppressText(rule.state) + '</div>';
                });
                return html || emptyNote('未命中价格规则');
            case 'quantity':
                return kv('计算', money(step.unit_subtotal) + ' × 数量 ' + escape(step.quantity) + ' = <strong>' + money(step.output) + '</strong>');
            case 'channel_adjustments':
                if (!step.applied || !step.applied.length) { return emptyNote('未命中渠道折扣'); }
                $.each(step.applied, function (_, item) {
                    html += '<div>' + escape(CHANNEL_SOURCE_LABELS[item.source] || item.source) + ' ' + escape(item.code) +
                        ' · 折扣比例 ' + money(item.discount) + ' · ' + beforeAfterText(item.before, item.after) + '</div>';
                });
                return html;
            case 'manual_discount':
                html = kv('折扣比例（支付比例语义）', money(step.discount));
                if (step.discount_reason) { html += kv('折扣理由', escape(step.discount_reason)); }
                html += kv('调整', beforeAfterText(step.before, step.output));
                return html;
            case 'fees':
                html = '';
                if (step.items && step.items.length) {
                    html += itemList($.map(step.items, function (item) {
                        var flags = [];
                        if (item.include_in_margin) { flags.push('计入毛利'); }
                        if (item.include_in_floor) { flags.push('计入控制价'); }
                        return [[
                            escape(item.code),
                            escape(FEE_TYPE_LABELS[item.fee_type] || item.fee_type),
                            escape(CALC_TYPE_LABELS[item.calculation_type] || item.calculation_type),
                            '<strong>' + money(item.amount) + '</strong>',
                            flags.length ? escape(flags.join('、')) : '—'
                        ]];
                    }), ['费用规则', '类型', '计算方式', '金额', '口径']);
                } else {
                    html += emptyNote('无费用');
                }
                $.each(step.freight_adjustments || [], function (_, item) {
                    html += '<div>运费调整 ' + escape(item.code) + ' v' + escape(item.version) + ' · ' +
                        adjustmentText(item.adjustment_type, item.adjustment_value) + ' · ' +
                        beforeAfterText(item.before, item.after) + '</div>';
                });
                return html;
            case 'untaxed_amount':
                return kv('计算', money(step.goods_discounted) + ' + 费用 ' + money(step.fees) + ' = <strong>' + money(step.output) + '</strong>');
            case 'tax':
                html = kv('计税模式', escape(TAX_MODE_LABELS[step.tax_mode] || step.tax_mode));
                html += kv('税率', money(step.rate));
                if (step.matched) {
                    html += kv('税率规则', ruleTitle(step.matched) + '（国家/区域 ' + escape(step.matched.country_code) + ' / ' + escape(step.matched.region_code || '—') + ' · 产品类型 ' + escape(step.matched.product_type || '—') + '）');
                }
                if (step.note) { html += kv('说明', '<span class="text-warning">' + escape(step.note) + '</span>'); }
                return html;
            case 'total_with_tax':
                return kv('计算', '未税 ' + money(step.untaxed) + ' + 税额 ' + money(step.tax) + ' = <strong>' + money(step.output) + '</strong>');
            case 'currency_conversion':
                html = '';
                if (step.rate) {
                    var direction = {identity:'同币种', direct:'直接汇率', inverse:'反向汇率倒数'}[step.rate.direction] || step.rate.direction;
                    html += kv('汇率', escape(step.rate.from_currency) + ' → ' + escape(step.rate.to_currency) + ' = ' + money(step.rate.rate) +
                        '（' + escape(direction) + (step.rate.source ? ' · 来源 ' + escape(step.rate.source) : '') +
                        (step.rate.effective_date ? ' · 生效 ' + escape(step.rate.effective_date) : '') + '）');
                }
                html += itemList($.map(step.output || {}, function (value, key) {
                    return [[escape(AMOUNT_LABELS[key] || key), '<strong>' + money(value) + '</strong>']];
                }), ['金额项（输出币种）', '换算后金额']);
                return html;
            case 'floor_comparison':
                html = kv('控制价口径单价', '<strong>' + money(step.control_unit_price) + '</strong>');
                if (step.policy) {
                    html += kv('控制价策略', ruleTitle(step.policy));
                    var floors = [];
                    if (step.policy.guide_price !== undefined && step.policy.guide_price !== null) { floors.push('指导价 ' + money(step.policy.guide_price)); }
                    if (step.policy.line_floor !== undefined && step.policy.line_floor !== null) { floors.push('产线底线 ' + money(step.policy.line_floor)); }
                    if (step.policy.company_floor !== undefined && step.policy.company_floor !== null) { floors.push('公司底线 ' + money(step.policy.company_floor)); }
                    if (step.policy.cost !== undefined && step.policy.cost !== null) { floors.push('成本 ' + money(step.policy.cost)); }
                    if (floors.length) { html += kv('策略指标', escape(floors.join(' · '))); }
                }
                html += kv('比较结论', CpqCommon.classificationFormatter(step.classification));
                return html;
            case 'approval_level':
                return kv('结论', CpqCommon.classificationFormatter(step.classification) + ' → ' +
                    '<strong>' + escape(APPROVAL_LABELS[step.approval_level] || step.approval_level) + '</strong>');
            default:
                var detail = $.extend({}, step);
                delete detail.step;
                return '<pre>' + escape(JSON.stringify(detail, null, 2)) + '</pre>';
        }
    }

    function renderSteps(steps) {
        var html = '<table class="table table-condensed table-bordered cpq-trace-steps"><thead><tr>' +
            '<th style="width:36px;">#</th><th style="width:120px;">计算步骤</th><th>数据 / 指标 / 引用规则</th><th style="width:150px;">输出</th></tr></thead><tbody>';
        $.each(steps || [], function (index, step) {
            var output = step.output;
            if (output === null || output === undefined || typeof output === 'object') { output = '—'; }
            html += '<tr class="trace-step"><td>' + (index + 1) + '</td>' +
                '<td><strong>' + escape(STEP_LABELS[step.step] || step.step) + '</strong></td>' +
                '<td>' + stepDetail(step) + '</td>' +
                '<td>' + (output === '—' ? '—' : money(output)) + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function renderRuleSnapshot(rule) {
        var stateText = rule.state === 'matched'
            ? '<span class="label label-success">命中</span>'
            : '<span class="label label-default">' + suppressText(rule.state) + '</span>';
        var html = '<div>' + ruleTitle(rule) + ' ' + stateText + ' · ' +
            adjustmentText(rule.adjustment_type, rule.adjustment_value) +
            '（目标 ' + escape(TARGET_LABELS[rule.adjustment_target] || rule.adjustment_target) + '）';
        if (rule.exclusive_group) { html += ' · 互斥组 ' + escape(rule.exclusive_group); }
        html += rule.can_stack ? '' : ' · 不可叠加';
        if (rule.minimum_amount !== null && rule.minimum_amount !== undefined) { html += ' · 保底 ' + money(rule.minimum_amount); }
        if (rule.maximum_amount !== null && rule.maximum_amount !== undefined) { html += ' · 封顶 ' + money(rule.maximum_amount); }
        html += ' · 有效期 ' + escape(rule.effective_date) + ' ~ ' + (rule.expiry_date == null ? '长期' : escape(rule.expiry_date));
        html += '</div>';
        if (rule.condition !== null && rule.condition !== undefined) {
            html += '<div class="text-muted" style="padding-left:12px;">条件：<code>' + escape(JSON.stringify(rule.condition)) + '</code></div>';
        }
        return html;
    }

    function renderSnapshots(snapshots) {
        var html = '';
        if (snapshots.price_book) { html += kv('价格表', ruleTitle(snapshots.price_book) + '（币种 ' + escape(snapshots.price_book.currency) + ' · ' + escape(TAX_MODE_LABELS[snapshots.price_book.tax_mode] || snapshots.price_book.tax_mode) + '）'); }
        if (snapshots.price_policy) {
            html += kv('控制价策略', ruleTitle(snapshots.price_policy));
            var dims = snapshots.price_policy.dimensions || {};
            var dimTexts = [];
            $.each(dims, function (key, value) { if (value !== null && value !== undefined && value !== '') { dimTexts.push(key + '=' + value); } });
            if (dimTexts.length) { html += kv('策略维度', escape(dimTexts.join(' · '))); }
        }
        if (snapshots.price_rules && snapshots.price_rules.length) {
            html += kv('价格规则（命中与抑制）', '');
            $.each(snapshots.price_rules, function (_, rule) { html += '<div style="padding-left:12px;">' + renderRuleSnapshot(rule) + '</div>'; });
        }
        if (snapshots.fee_rules && snapshots.fee_rules.length) {
            html += kv('费用规则', '');
            $.each(snapshots.fee_rules, function (_, fee) {
                var flags = [];
                if (fee.include_in_margin) { flags.push('计入毛利'); }
                if (fee.include_in_floor) { flags.push('计入控制价'); }
                html += '<div style="padding-left:12px;">' + ruleTitle(fee) + ' · ' +
                    escape(FEE_TYPE_LABELS[fee.fee_type] || fee.fee_type) + ' · ' +
                    escape(CALC_TYPE_LABELS[fee.calculation_type] || fee.calculation_type) + ' ' + money(fee.value) +
                    (fee.currency ? ' ' + escape(fee.currency) : '') +
                    (flags.length ? ' · ' + escape(flags.join('、')) : '') + '</div>';
            });
        }
        if (snapshots.tax_rule) {
            html += kv('税率规则', ruleTitle(snapshots.tax_rule) + '（国家/区域 ' + escape(snapshots.tax_rule.country_code) + ' / ' + escape(snapshots.tax_rule.region_code || '—') + ' · 税率 ' + money(snapshots.tax_rule.rate) + '）');
        }
        if (snapshots.exchange_rate) {
            var rate = snapshots.exchange_rate;
            html += kv('汇率快照', escape(rate.from_currency) + ' → ' + escape(rate.to_currency) + ' = ' + money(rate.rate) +
                '（' + escape({identity:'同币种', direct:'直接汇率', inverse:'反向汇率倒数'}[rate.direction] || rate.direction) +
                (rate.source ? ' · 来源 ' + escape(rate.source) : '') + '）');
        }
        return html || emptyNote('无主数据快照');
    }

    function renderTraceLine(line, index) {
        var trace = line.price_trace;
        var rawId = 'cpq-raw-trace-' + index;
        var html = '<div class="panel panel-default"><div class="panel-heading">第 ' + escape(trace.line_no != null ? trace.line_no : line.line_no) + ' 行 · 计算过程 / 数据指标 / 引用规则</div><div class="panel-body">';
        html += '<h5 style="margin-top:0;">固定 ' + (line.price_trace.steps || []).length + ' 步计价管线（版本 v' + escape(trace.pipeline_version) + '）</h5>';
        html += renderSteps(trace.steps);
        html += '<h5>引用规则与主数据快照</h5>' + renderSnapshots(trace.snapshots || {});
        html += '<div style="margin-top:10px;"><a href="#' + rawId + '" data-toggle="collapse">原始轨迹 JSON</a>' +
            '<div id="' + rawId + '" class="collapse"><pre>' + escape(JSON.stringify(trace, null, 2)) + '</pre></div></div>';
        return html + '</div></div>';
    }

    function render(result) {
        lastSafeResult = result;
        $('#cpq-pricing-empty,#cpq-pricing-error').addClass('hidden');
        $('#cpq-pricing-result').removeClass('hidden');
        var total = result.totals && (result.totals.total || result.totals.converted_total);
        $('#cpq-total').text(total == null ? '—' : String(total));
        var approval = {none:'无需审批',line:'产线审批',company:'公司审批',forbidden:'禁止提交'};
        $('#cpq-approval').text(approval[result.approval_level] || result.approval_level || '—');
        $('#cpq-submittable').text(result.submittable ? '允许' : '禁止');
        var lines = '';
        $.each(result.lines || [], function (_, line) {
            lines += '<div class="panel panel-info"><div class="panel-heading">第 ' + escape(line.line_no) + ' 行 · 型号 ' + escape(line.model_id) + ' · ' + CpqCommon.classificationFormatter(line.classification) + '</div>' +
                '<div class="panel-body">' + amountRows(line.amounts) + '<p><strong>价格哈希：</strong><code>' + escape(line.price_hash || result.price_hash || '') + '</code></p></div></div>';
        });
        $('#cpq-lines').html(lines || '<p class="text-muted">无价格行。</p>');
        var trace = '';
        var traceIndex = 0;
        $.each(result.lines || [], function (_, line) {
            if (!line.price_trace) { return; }
            trace += renderTraceLine(line, traceIndex);
            traceIndex++;
        });
        $('#cpq-trace').html(trace || '<p class="text-muted">本次为快速试算；点击“解释执行轨迹”获取规则命中顺序。</p>');
        $('#cpq-export').prop('disabled', !trace);
    }
    function request(action) {
        var body;
        try { body = payload(); } catch (error) { Toastr.error(error.message); return; }
        $('#cpq-pricing-error').addClass('hidden');
        Fast.api.ajax({url:'cpq/pricing/' + action,type:'POST',contentType:'application/json; charset=UTF-8',data:JSON.stringify(body)}, function (data) {
            render(data || {}); return false;
        }, function (data, ret) {
            lastSafeResult = null; $('#cpq-export').prop('disabled', true);
            var details = ret && ret.data && ret.data.details ? '\n' + JSON.stringify(ret.data.details, null, 2) : '';
            $('#cpq-pricing-result').addClass('hidden');
            $('#cpq-pricing-error').removeClass('hidden').text((ret && ret.msg ? ret.msg : '试算失败') + details);
            return false;
        });
    }
    var Controller = {index:function () {
        Form.api.bindevent($('#cpq-pricing-form'));
        // 选择产品型号后加载交互配置结构（selectpage 选中与手工清空都会触发 change）
        $('#cpq-pricing-form').on('change', '[name="model_id"]', loadContext);
        $('#cpq-pricing-form').on('change input', '#cpq-config-groups input,#cpq-accessory-rows', updateConfigPreview);
        $('#cpq-accessory-add').on('click', appendAccessoryRow);
        $('#cpq-calculate').on('click', function () { request('calculate'); });
        $('#cpq-explain').on('click', function () { request('explain'); });
        $('#cpq-export').on('click', function () {
            if (!lastSafeResult) { return; }
            var blob = new Blob([JSON.stringify(lastSafeResult, null, 2)], {type:'application/json;charset=utf-8'});
            var url = URL.createObjectURL(blob), link = document.createElement('a');
            link.href = url; link.download = 'cpq-price-trace.json'; link.click(); URL.revokeObjectURL(url);
        });
    }};
    return Controller;
});
