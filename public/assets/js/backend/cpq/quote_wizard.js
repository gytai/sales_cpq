/**
 * 六步报价向导（P50-P58，GYTAI-70）
 *
 * 步骤：1 客户基本信息 → 2 选择产品 → 3 产品配置 → 4 价格与折扣 → 5 商务条款 → 6 预览与提交。
 *
 * 原则：前端实时交互仅作提示；保存、试算、提交全部使用同版本后端重算结果。
 * 金额一律为服务端 Decimal 字符串原样展示，前端不做任何浮点运算。
 */
define(['jquery', 'bootstrap', 'backend', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Form, CpqCommon) {
    'use strict';

    var APPROVAL_ORDER = ['none', 'line', 'company', 'forbidden'];
    var APPROVAL_TEXTS = {none: '无需审批', line: '产线审批', company: '公司审批', forbidden: '禁止提交'};
    var TERM_TYPES = {payment: '付款', trade: '贸易', warranty: '质保', delivery: '交付', other: '其他'};
    var AMOUNT_LABELS = {
        goods: '商品金额', goods_discounted: '折扣后商品金额', fees: '费用', untaxed: '未税金额',
        tax: '税额', total: '含税金额', control_unit_price: '控制价口径单价', cost_total: '成本合计',
        base: '基础价', options: '选项金额', services: '服务金额', subtotal: '单价小计'
    };

    var state;
    var lineSeq = 0;
    var validateTimers = {};

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    function genIdempotencyKey() {
        return 'QW-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
    }

    function jsonPost(url, body, onSuccess, onError) {
        Fast.api.ajax({url: url, type: 'POST', contentType: 'application/json; charset=UTF-8', data: JSON.stringify(body)}, function (data, ret) {
            var payload = ret && ret.data && ret.data.payload !== undefined ? ret.data.payload : data;
            if (typeof onSuccess === 'function') {
                onSuccess(payload);
            }
            return false;
        }, function (data, ret) {
            if (typeof onError === 'function') {
                onError(ret || {});
            }
            return false;
        });
    }

    // ------------------------------------------------------------------
    // 错误 → 步骤/字段定位
    // ------------------------------------------------------------------

    function errorStep(ret) {
        var code = ret && ret.data && ret.data.business_code ? String(ret.data.business_code) : '';
        if (code.indexOf('CPQ_CONFIG') === 0) {
            return 3;
        }
        if (code.indexOf('CPQ_PRICE') === 0 || code.indexOf('CPQ_EXCHANGE') === 0) {
            return 4;
        }
        return 1;
    }

    function showError(ret) {
        var message = ret && ret.msg ? String(ret.msg) : '操作失败';
        var code = ret && ret.data && ret.data.business_code ? String(ret.data.business_code) : '';
        var details = ret && ret.data && ret.data.payload ? ret.data.payload : null;
        var text = message + (code ? ' [' + code + ']' : '');
        if (details && details.block_reasons) {
            $.each(details.block_reasons, function (_, reason) {
                text += '\n' + (reason.message || JSON.stringify(reason));
            });
        } else if (details && typeof details === 'object') {
            // 策略缺失/冲突等结构化明细：按「维度=值」逐行列出（Q-006 提示缺失维度组合）
            var dimensionLabels = {
                model_code: '型号', target_type: '对象类型', currency: '币种', unit: '单位',
                company: '公司', business_unit: '业务板块', market_scope: '市场范围',
                region_code: '区域', customer_level: '客户等级', agent_level: '代理等级',
                product_line: '产品线', date: '试算日期', policies: '命中策略', specificity: '具体度', priority: '优先级'
            };
            var dimensions = [];
            $.each(details, function (key, value) {
                if (key === 'line' || key === 'line_no' || value === null || typeof value === 'object') {
                    return;
                }
                dimensions.push((dimensionLabels[key] || key) + '=' + (value === '' ? '（不限）' : value));
            });
            if (dimensions.length > 0) {
                text += '\n' + (code === 'CPQ_PRICE_POLICY_MISSING' ? '缺失维度组合：' : '错误明细：') + dimensions.join('，');
            }
        }
        $('#cpq-wizard-error').removeClass('hidden').text(text);
        showStep(errorStep(ret));
        $('#cpq-steps li[data-step="' + errorStep(ret) + '"]').addClass('error');
        if (message.indexOf('乐观锁') !== -1 || message.indexOf('已被其他人修改') !== -1) {
            $('#cpq-draft-hint').html('<span class="text-danger">报价已被他人修改，请关闭本页后从列表重新进入编辑。</span>');
        }
    }

    function clearError() {
        $('#cpq-wizard-error').addClass('hidden').text('');
        $('#cpq-steps li').removeClass('error');
    }

    // ------------------------------------------------------------------
    // 状态收集
    // ------------------------------------------------------------------

    function headerData() {
        var data = {};
        $.each($('#cpq-quote-header').serializeArray(), function (_, item) {
            data[item.name] = $.trim(item.value);
        });
        return data;
    }

    function collectPayload() {
        var header = headerData();
        var payload = {
            name: header.name || '',
            description: header.description || '',
            customer_id: header.customer_id || '',
            agent_id: header.agent_id || '',
            sales_org_id: header.sales_org_id || '',
            product_line: header.product_line || '',
            currency: header.currency || 'CNY',
            company: header.company || '',
            market_scope: header.market_scope || '',
            lines: state.lines.map(function (line, index) {
                return {
                    line_no: index + 1,
                    model_id: line.model_id,
                    quantity: line.quantity,
                    unit: line.unit,
                    configuration: line.configuration || {},
                    accessories: line.accessories || [],
                    manual_discount: line.manual_discount || '',
                    discount_reason: line.discount_reason || ''
                };
            }),
            terms: state.terms.map(function (term) {
                return {
                    term_type: term.term_type,
                    term_code: term.term_code,
                    content: term.content,
                    is_editable: parseInt(term.is_editable, 10) === 1 ? 1 : 0
                };
            })
        };
        if (state.id > 0) {
            payload.id = state.id;
            payload.optimistic_lock_version = state.lock;
        }
        return payload;
    }

    function markPriceStale() {
        state.priceResult = null;
        $('#cpq-price-summary').addClass('hidden');
    }

    function updateDraftHint() {
        if (state.id > 0) {
            $('#cpq-draft-hint').text('草稿 ' + (state.code || ('#' + state.id)) + '（乐观锁 v' + state.lock + '）');
        }
    }

    // ------------------------------------------------------------------
    // 保存草稿
    // ------------------------------------------------------------------

    function saveDraft(onSuccess, silent, onError) {
        clearError();
        jsonPost('cpq/quote/save', collectPayload(), function (payload) {
            state.id = parseInt(payload.id, 10);
            state.lock = parseInt(payload.optimistic_lock_version, 10);
            state.code = payload.code;
            state.status = payload.status;
            updateDraftHint();
            if (!silent) {
                Toastr.success('草稿已保存：' + payload.code);
            }
            if (typeof onSuccess === 'function') {
                onSuccess(payload);
            }
        }, function (ret) {
            showError(ret);
            if (typeof onError === 'function') {
                onError(ret);
            }
        });
    }

    // ------------------------------------------------------------------
    // 步骤导航
    // ------------------------------------------------------------------

    function showStep(step) {
        state.step = step;
        $('#cpq-steps li').each(function () {
            var li = $(this);
            var liStep = parseInt(li.data('step'), 10);
            li.toggleClass('active', liStep === step);
            li.toggleClass('done', liStep < step);
        });
        $('.wizard-panel').removeClass('active');
        $('.wizard-panel[data-step="' + step + '"]').addClass('active');
        $('#cpq-prev').prop('disabled', step <= 1);
        $('#cpq-next').toggleClass('hidden', step >= 6);
        if (step === 3) {
            renderConfigLines();
        } else if (step === 4) {
            renderPriceLines();
        } else if (step === 6) {
            renderPreview();
        }
    }

    function validateStep1() {
        var ok = true;
        var header = headerData();
        $.each({name: '报价名称', customer_id: '客户', product_line: '产品线', currency: '币种'}, function (field, label) {
            var input = $('#cpq-quote-header [name="' + field + '"]');
            var valid = header[field] !== undefined && header[field] !== '';
            input.closest('.form-group').toggleClass('has-error', !valid);
            if (!valid) {
                ok = false;
                Toastr.error('请填写' + label);
            }
        });
        return ok;
    }

    function nextStep() {
        var step = state.step;
        if (step === 1 && !validateStep1()) {
            return;
        }
        if (step === 2) {
            if (state.lines.length === 0) {
                Toastr.error('请至少添加一行明细');
                return;
            }
        }
        if (step === 3) {
            validateAllConfigs(function (allValid) {
                if (!allValid) {
                    Toastr.error('存在不合法的配置，请先修正（错误已定位到对应配置组）');
                    return;
                }
                showStep(4);
            });
            return;
        }
        if (step === 4 && !state.priceResult) {
            Toastr.error('请先点击「服务端试算」获取最新价格');
            return;
        }
        showStep(Math.min(6, step + 1));
    }

    // ------------------------------------------------------------------
    // 第 2 步：选品
    // ------------------------------------------------------------------

    function configStatusBadge(line) {
        if (!line.validation) {
            return '<span class="label label-default">未校验</span>';
        }
        if (line.validation.is_valid) {
            return '<span class="label label-success">配置合法</span>';
        }
        return '<span class="label label-danger">配置不合法</span>';
    }

    function renderLinesTable() {
        var body = $('#cpq-lines-table tbody').empty();
        if (state.lines.length === 0) {
            body.append('<tr class="cpq-lines-empty"><td colspan="6" class="text-muted text-center">尚未添加明细行</td></tr>');
            return;
        }
        $.each(state.lines, function (index, line) {
            var row = $('<tr></tr>').attr('data-line-key', line.key);
            row.append('<td>' + (index + 1) + '</td>');
            row.append('<td>' + escapeHtml(line.model_code || ('#' + line.model_id)) + ' ' + escapeHtml(line.model_name || '') + '</td>');
            var qty = $('<input type="text" class="form-control input-sm cpq-line-quantity">').val(line.quantity);
            qty.on('change input', function () {
                line.quantity = $.trim($(this).val()) || '1';
                markPriceStale();
            });
            row.append($('<td></td>').append(qty));
            var unit = $('<input type="text" class="form-control input-sm cpq-line-unit">').val(line.unit);
            unit.on('change input', function () {
                line.unit = $.trim($(this).val()) || 'set';
            });
            row.append($('<td></td>').append(unit));
            row.append('<td class="cpq-line-status">' + configStatusBadge(line) + '</td>');
            var remove = $('<button type="button" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button>');
            remove.on('click', function () {
                state.lines = state.lines.filter(function (item) {
                    return item.key !== line.key;
                });
                markPriceStale();
                renderLinesTable();
            });
            row.append($('<td></td>').append(remove));
            body.append(row);
        });
    }

    function addLine() {
        var modelId = $('#cpq-pick-model').val();
        var quantity = $.trim($('#cpq-pick-quantity').val()) || '1';
        var unit = $.trim($('#cpq-pick-unit').val()) || 'set';
        if (!modelId) {
            Toastr.error('请选择产品型号');
            return;
        }
        // 加载已发布配置结构；未发布型号由服务端拒绝，不能进入报价
        Fast.api.ajax({url: 'cpq/configurator/schema', type: 'GET', data: {model_id: modelId}}, function (schema) {
            state.lines.push({
                key: ++lineSeq,
                model_id: parseInt(modelId, 10),
                model_code: schema && schema.model ? schema.model.code : '',
                model_name: schema && schema.model ? schema.model.name : '',
                quantity: quantity,
                unit: unit,
                configuration: {},
                accessories: [],
                manual_discount: '',
                discount_reason: '',
                schema: schema,
                validation: null
            });
            markPriceStale();
            renderLinesTable();
            $('#cpq-pick-model').val('').selectPageRefresh && $('#cpq-pick-model').selectPageRefresh();
            $('#cpq-pick-quantity').val('1');
            $('#cpq-pick-unit').val('set');
            Toastr.success('已添加明细行，请在第 3 步完成配置');
            return false;
        }, function (data, ret) {
            Toastr.error(ret && ret.msg ? ret.msg : '该型号无已发布配置结构，不能选入报价');
            return false;
        });
    }

    // ------------------------------------------------------------------
    // 第 3 步：产品配置（逐行，服务端校验为准）
    // ------------------------------------------------------------------

    function collectLineConfiguration(line) {
        var configuration = {};
        if (!line.schema) {
            return configuration;
        }
        $.each(line.schema.groups || [], function (_, group) {
            var name = 'cfg-' + line.key + '-' + group.code;
            if (group.input_type === 'multiple') {
                var selected = [];
                $('[name="' + name + '"]:checked').each(function () {
                    selected.push($(this).val());
                });
                if (selected.length) {
                    configuration[group.code] = selected;
                }
            } else if (group.input_type !== 'readonly') {
                var field = $('[name="' + name + '"]');
                var value = group.input_type === 'single' ? field.filter(':checked').val() : field.val();
                if (value !== undefined && value !== '') {
                    configuration[group.code] = value;
                }
            }
        });
        return configuration;
    }

    function renderLineGroups(line, container) {
        container.empty();
        $.each((line.schema && line.schema.groups) || [], function (_, group) {
            var name = 'cfg-' + line.key + '-' + group.code;
            var panel = $('<div class="cfg-group"></div>').attr('data-group-code', group.code);
            panel.append('<div><strong>' + escapeHtml(group.name) + '</strong> <code>' + escapeHtml(group.code) + '</code>' +
                (group.is_required ? ' <span class="label label-danger">必选</span>' : '') +
                (group.help_text ? '<p class="help-block">' + escapeHtml(group.help_text) + '</p>' : '') + '</div>');
            var current = line.configuration ? line.configuration[group.code] : undefined;
            if (group.input_type === 'single') {
                $.each(group.options || [], function (_, option) {
                    var checked = current !== undefined ? String(current) === String(option.code) : current === undefined && group.default_value === option.code;
                    var label = $('<label class="radio-inline"></label>');
                    label.append($('<input type="radio">').attr({name: name, value: option.code}).prop('checked', checked));
                    label.append(document.createTextNode(' ' + option.name));
                    panel.append(label);
                });
            } else if (group.input_type === 'multiple') {
                var selectedValues = $.isArray(current) ? current.map(String) : ($.isArray(group.default_value) ? group.default_value.map(String) : []);
                $.each(group.options || [], function (_, option) {
                    var label = $('<label class="checkbox-inline"></label>');
                    label.append($('<input type="checkbox">').attr({name: name, value: option.code}).prop('checked', $.inArray(String(option.code), selectedValues) !== -1));
                    label.append(document.createTextNode(' ' + option.name));
                    panel.append(label);
                });
            } else {
                var type = group.input_type === 'number' ? 'number' : 'text';
                var input = $('<input class="form-control">').attr('type', type).attr('name', name);
                if (group.input_type === 'number') {
                    input.attr('step', 'any');
                }
                if (group.input_type === 'readonly') {
                    input.prop('readonly', true);
                }
                input.val(current !== undefined && current !== null ? current : (group.default_value === null || group.default_value === undefined ? '' : group.default_value));
                panel.append(input);
            }
            container.append(panel);
        });
    }

    function renderLineIssues(line, validation) {
        var target = $('.cpq-config-line[data-line-key="' + line.key + '"] .cpq-line-issues').empty();
        var badge = validation.is_valid
            ? '<span class="label label-success">配置合法</span>'
            : '<span class="label label-danger">配置不合法</span>';
        target.append(badge + ' ');

        function appendIssues(issues, className) {
            $.each(issues || [], function (_, issue) {
                var code = issue.path ? String(issue.path).split('.').pop() : '';
                var item = $('<a href="javascript:;" class="' + className + '" style="margin-right:10px"></a>')
                    .text((issue.message || '') + (issue.rule_code ? ' [' + issue.rule_code + ']' : ''));
                if (code) {
                    item.on('click', function () {
                        var panel = $('.cpq-config-line[data-line-key="' + line.key + '"] .cfg-group[data-group-code="' + code + '"]');
                        if (panel.length && panel.get(0).scrollIntoView) {
                            panel.get(0).scrollIntoView({behavior: 'smooth', block: 'center'});
                        }
                    });
                }
                target.append(item);
            });
        }

        appendIssues(validation.errors, 'text-danger');
        appendIssues(validation.warnings, 'text-warning');

        // 组高亮：错误红、警告黄
        var lineRoot = $('.cpq-config-line[data-line-key="' + line.key + '"]');
        lineRoot.find('.cfg-group').removeClass('cfg-error cfg-warning');
        $.each(validation.errors || [], function (_, issue) {
            var code = issue.path ? String(issue.path).split('.').pop() : '';
            lineRoot.find('.cfg-group[data-group-code="' + code + '"]').removeClass('cfg-warning').addClass('cfg-error');
        });
        $.each(validation.warnings || [], function (_, issue) {
            var code = issue.path ? String(issue.path).split('.').pop() : '';
            var group = lineRoot.find('.cfg-group[data-group-code="' + code + '"]');
            if (!group.hasClass('cfg-error')) {
                group.addClass('cfg-warning');
            }
        });
        // 动态显隐
        lineRoot.find('.cfg-group').removeClass('hidden');
        $.each(validation.hidden_groups || [], function (_, code) {
            lineRoot.find('.cfg-group[data-group-code="' + code + '"]').addClass('hidden');
        });
    }

    function validateLine(line, onDone) {
        if (!line.schema) {
            if (typeof onDone === 'function') {
                onDone(false);
            }
            return;
        }
        line.configuration = collectLineConfiguration(line);
        Fast.api.ajax({
            url: 'cpq/configurator/validateConfiguration',
            type: 'POST',
            data: {model_id: line.model_id, configuration: JSON.stringify(line.configuration)},
            loading: false
        }, function (validation) {
            line.validation = validation;
            renderLineIssues(line, validation);
            renderLinesTable();
            markPriceStale();
            if (typeof onDone === 'function') {
                onDone(!!validation.is_valid);
            }
            return false;
        }, function (data, ret) {
            line.validation = {is_valid: false, errors: [{message: (ret && ret.msg) || '服务端校验失败'}]};
            renderLineIssues(line, line.validation);
            renderLinesTable();
            if (typeof onDone === 'function') {
                onDone(false);
            }
            return false;
        });
    }

    function validateAllConfigs(onDone) {
        if (state.lines.length === 0) {
            onDone(false);
            return;
        }
        var remaining = state.lines.length;
        var allValid = true;
        $.each(state.lines, function (_, line) {
            validateLine(line, function (valid) {
                allValid = allValid && valid;
                remaining--;
                if (remaining === 0) {
                    onDone(allValid);
                }
            });
        });
    }

    function scheduleLineValidation(line) {
        if (validateTimers[line.key]) {
            clearTimeout(validateTimers[line.key]);
        }
        validateTimers[line.key] = setTimeout(function () {
            delete validateTimers[line.key];
            validateLine(line);
        }, 600);
    }

    function renderConfigLines() {
        var container = $('#cpq-config-lines').empty();
        if (state.lines.length === 0) {
            container.append('<p class="text-muted">请先在第 2 步添加明细行。</p>');
            return;
        }
        $.each(state.lines, function (index, line) {
            var card = $('<div class="line-card cpq-config-line"></div>').attr('data-line-key', line.key);
            card.append('<div class="clearfix"><strong>第 ' + (index + 1) + ' 行 · ' + escapeHtml(line.model_code || ('#' + line.model_id)) + ' ' + escapeHtml(line.model_name || '') + '</strong>' +
                '<button type="button" class="btn btn-success btn-xs pull-right cpq-validate-line"><i class="fa fa-check"></i> 校验本行</button></div>');
            var groups = $('<div style="margin-top:10px"></div>');
            card.append(groups);
            card.append('<div class="cpq-line-issues" style="margin-top:8px"><span class="text-muted">尚未校验</span></div>');
            container.append(card);
            if (line.schema) {
                renderLineGroups(line, groups);
                if (line.validation) {
                    renderLineIssues(line, line.validation);
                } else {
                    validateLine(line);
                }
            } else {
                groups.append('<p class="text-muted">正在加载配置结构…</p>');
                Fast.api.ajax({url: 'cpq/configurator/schema', type: 'GET', data: {model_id: line.model_id}}, function (schema) {
                    line.schema = schema;
                    renderLineGroups(line, groups);
                    validateLine(line);
                    return false;
                });
            }
        });
    }

    // ------------------------------------------------------------------
    // 第 4 步：价格与折扣（金额以后端试算为准）
    // ------------------------------------------------------------------

    function priceLineByNo(lineNo) {
        var found = null;
        $.each((state.priceResult && state.priceResult.lines) || [], function (_, line) {
            if (parseInt(line.line_no, 10) === lineNo) {
                found = line;
                return false;
            }
        });
        return found;
    }

    function amountTable(title, amounts) {
        var html = '';
        $.each(amounts || {}, function (key, value) {
            html += '<tr><th>' + escapeHtml(AMOUNT_LABELS[key] || key) + '</th><td class="text-amount">' + escapeHtml(value == null ? '' : String(value)) + '</td></tr>';
        });
        if (!html) {
            return '';
        }
        return '<div class="col-sm-6"><table class="table table-condensed table-bordered amount-table"><thead><tr><th colspan="2">' + escapeHtml(title) + '</th></tr></thead><tbody>' + html + '</tbody></table></div>';
    }

    function renderPriceLines() {
        var container = $('#cpq-price-lines').empty();
        if (state.lines.length === 0) {
            container.append('<p class="text-muted">请先在第 2 步添加明细行。</p>');
            return;
        }
        // 多行最严格风险：取分级最高的行级 classification 做整单风险提示
        var strictest = null;
        if (state.priceResult) {
            $.each(state.priceResult.lines || [], function (_, line) {
                var rank = $.inArray(line.approval_level, APPROVAL_ORDER);
                if (rank !== -1 && (strictest === null || rank > strictest.rank)) {
                    strictest = {rank: rank, line_no: line.line_no, approval_level: line.approval_level};
                }
            });
        }
        $.each(state.lines, function (index, line) {
            var priced = priceLineByNo(index + 1);
            var isStrictest = strictest && priced && parseInt(priced.line_no, 10) === strictest.line_no && strictest.rank >= $.inArray('line', APPROVAL_ORDER);
            var card = $('<div class="line-card"></div>');
            if (isStrictest) {
                card.addClass(strictest.approval_level === 'forbidden' ? 'line-error' : 'line-warning');
            }
            var header = $('<div class="clearfix"></div>');
            header.append('<strong>第 ' + (index + 1) + ' 行 · ' + escapeHtml(line.model_code || ('#' + line.model_id)) + ' × ' + escapeHtml(line.quantity) + '</strong> ');
            if (priced) {
                header.append(CpqCommon.classificationFormatter(priced.classification) + ' ');
                header.append('<span class="label label-' + (priced.approval_level === 'none' ? 'success' : 'warning') + '">' + (APPROVAL_TEXTS[priced.approval_level] || priced.approval_level) + '</span>');
                if (isStrictest) {
                    header.append(' <span class="label label-inverse">整单最严格风险行</span>');
                }
            } else {
                header.append('<span class="label label-default">未试算</span>');
            }
            card.append(header);
            var discountRow = $('<div class="row" style="margin-top:8px"></div>');
            var discount = $('<input type="text" class="form-control input-sm" placeholder="手工折扣 0～1，例如 0.95">').val(line.manual_discount || '');
            discount.on('change input', function () {
                line.manual_discount = $.trim($(this).val());
                markPriceStale();
            });
            var reason = $('<input type="text" class="form-control input-sm" placeholder="折扣原因（低于指导价时必填）">').val(line.discount_reason || '');
            reason.on('change input', function () {
                line.discount_reason = $.trim($(this).val());
            });
            discountRow.append($('<div class="col-sm-3"></div>').append(discount));
            discountRow.append($('<div class="col-sm-5"></div>').append(reason));
            card.append(discountRow);
            if (priced) {
                var amounts = $('<div class="row" style="margin-top:8px"></div>');
                amounts.append(amountTable('单价构成', priced.unit_amounts));
                amounts.append(amountTable('行金额', priced.amounts));
                card.append(amounts);
                card.append('<p class="help-block" style="margin-bottom:0">行价格哈希：<code class="text-amount">' + escapeHtml(priced.price_hash || '') + '</code></p>');
            }
            container.append(card);
        });
        if (state.priceResult) {
            renderPriceSummary();
        } else {
            $('#cpq-price-summary').addClass('hidden');
        }
    }

    function renderPriceSummary() {
        var result = state.priceResult;
        if (!result) {
            return;
        }
        $('#cpq-price-summary').removeClass('hidden');
        var totals = result.totals || {};
        $('#cpq-sum-total').text(totals.total == null ? '—' : String(totals.total));
        $('#cpq-sum-approval').text(APPROVAL_TEXTS[result.approval_level] || result.approval_level || '—');
        $('#cpq-sum-submittable').text(result.submittable ? '允许' : '禁止');
        $('#cpq-sum-hash').text(result.price_hash || '—');
        var reasons = '';
        $.each(result.block_reasons || [], function (_, reason) {
            reasons += '<div class="alert alert-danger" style="margin-bottom:6px"><i class="fa fa-ban"></i> ' + escapeHtml(reason.message || JSON.stringify(reason)) + '</div>';
        });
        $('#cpq-block-reasons').html(reasons);
    }

    function recalculate(onDone) {
        if (state.lines.length === 0) {
            Toastr.error('请至少添加一行明细');
            return;
        }
        // 先试算必须落库：自动保存草稿，再以同版本后端重算结果为准
        saveDraft(function () {
            jsonPost('cpq/quote/recalculate', {id: state.id}, function (result) {
                state.priceResult = result;
                renderPriceLines();
                renderPriceSummary();
                Toastr.success('试算完成（服务端 Decimal 结果）');
                if (typeof onDone === 'function') {
                    onDone(result);
                }
            }, function (ret) {
                showError(ret);
            });
        }, true);
    }

    // ------------------------------------------------------------------
    // 第 5 步：商务条款
    // ------------------------------------------------------------------

    function renderTerms() {
        var container = $('#cpq-terms');
        container.find('.terms-row').remove();
        $('#cpq-terms-empty').toggle(state.terms.length === 0);
        $.each(state.terms, function (index, term) {
            var locked = parseInt(term.is_editable, 10) !== 1;
            var row = $('<div class="row terms-row"></div>');
            var type = $('<select class="form-control input-sm"></select>');
            $.each(TERM_TYPES, function (value, label) {
                type.append('<option value="' + value + '"' + (term.term_type === value ? ' selected' : '') + '>' + label + '</option>');
            });
            type.prop('disabled', locked).on('change', function () {
                term.term_type = $(this).val();
            });
            var code = $('<input type="text" class="form-control input-sm" placeholder="条款编码">').val(term.term_code || '');
            code.prop('disabled', locked).on('change input', function () {
                term.term_code = $.trim($(this).val());
            });
            var content = $('<textarea class="form-control input-sm" rows="2" placeholder="条款内容"></textarea>').val(term.content || '');
            content.prop('disabled', locked).on('change input', function () {
                term.content = $(this).val();
            });
            row.append($('<div class="col-sm-2"></div>').append(type));
            row.append($('<div class="col-sm-2"></div>').append(code));
            row.append($('<div class="col-sm-7"></div>').append(content));
            var actionCell = $('<div class="col-sm-1"></div>');
            if (locked) {
                actionCell.append('<span class="label label-inverse">锁定</span>');
            } else {
                var remove = $('<button type="button" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i></button>');
                remove.on('click', function () {
                    state.terms.splice(index, 1);
                    renderTerms();
                });
                actionCell.append(remove);
            }
            row.append(actionCell);
            container.append(row);
        });
    }

    // ------------------------------------------------------------------
    // 第 6 步：预览与提交
    // ------------------------------------------------------------------

    function renderPreview() {
        var header = headerData();
        var html = '<div class="panel panel-default"><div class="panel-heading">报价预览</div><div class="panel-body">';
        html += '<dl class="dl-horizontal">';
        html += '<dt>报价名称</dt><dd>' + escapeHtml(header.name || '—') + '</dd>';
        html += '<dt>产品线 / 币种</dt><dd>' + escapeHtml(header.product_line || '—') + ' / ' + escapeHtml(header.currency || '—') + '</dd>';
        html += '<dt>客户ID</dt><dd>' + escapeHtml(header.customer_id || '—') + '</dd>';
        html += '<dt>明细行数</dt><dd>' + state.lines.length + '</dd>';
        html += '<dt>条款数</dt><dd>' + state.terms.length + '</dd>';
        html += '</dl>';
        html += '<table class="table table-condensed table-bordered"><thead><tr><th>行号</th><th>型号</th><th>数量</th><th>配置指纹</th><th>手工折扣</th><th>试算含税金额</th></tr></thead><tbody>';
        $.each(state.lines, function (index, line) {
            var priced = priceLineByNo(index + 1);
            html += '<tr><td>' + (index + 1) + '</td><td>' + escapeHtml(line.model_code || ('#' + line.model_id)) + '</td>' +
                '<td class="text-amount">' + escapeHtml(line.quantity) + ' ' + escapeHtml(line.unit) + '</td>' +
                '<td class="text-amount"><small>' + escapeHtml(line.configuration_hash || (line.validation && line.validation.configuration_hash) || '—') + '</small></td>' +
                '<td class="text-amount">' + escapeHtml(line.manual_discount || '—') + '</td>' +
                '<td class="text-amount">' + (priced && priced.amounts ? escapeHtml(priced.amounts.total) : '<span class="text-muted">未试算</span>') + '</td></tr>';
        });
        html += '</tbody></table>';
        if (state.priceResult) {
            html += '<p>含税总额：<strong class="text-amount">' + escapeHtml((state.priceResult.totals || {}).total || '—') + '</strong>　' +
                '整单审批级别：<strong>' + escapeHtml(APPROVAL_TEXTS[state.priceResult.approval_level] || state.priceResult.approval_level || '—') + '</strong>　' +
                '价格哈希：<code class="text-amount">' + escapeHtml(state.priceResult.price_hash || '—') + '</code></p>';
        } else {
            html += '<p class="text-warning">尚未试算或数据已变更；提交时服务端仍将最终重算，建议先回到第 4 步试算确认金额。</p>';
        }
        html += '</div></div>';
        $('#cpq-preview').html(html);
        $('#cpq-submit-hint').text(state.id > 0 ? '' : '（提交前将自动保存草稿）');
    }

    function submitQuote() {
        clearError();
        if (!validateStep1()) {
            showStep(1);
            return;
        }
        if (state.lines.length === 0) {
            Toastr.error('请至少添加一行明细');
            showStep(2);
            return;
        }
        Layer.confirm('确认提交报价？提交后服务端将最终重算并冻结快照，报价进入审批且不可直接修改。', {icon: 3, title: '提交确认'}, function (confirmIndex) {
            Layer.close(confirmIndex);
            // 防重复提交：提交链路（保存草稿 + 最终重算冻结）进行中禁用按钮，失败恢复
            var submitButton = $('#cpq-submit').prop('disabled', true);
            saveDraft(function () {
                jsonPost('cpq/quote/submit', {id: state.id, idempotency_key: state.idempotencyKey}, function (payload) {
                    Toastr.success('提交成功：v' + payload.revision_no + '，审批级别 ' + (APPROVAL_TEXTS[payload.approval_level] || payload.approval_level));
                    state.idempotencyKey = genIdempotencyKey();
                    Fast.api.open('cpq/quote/detail/ids/' + state.id, '报价详情 ' + (payload.quote && payload.quote.code ? payload.quote.code : ''));
                }, function (ret) {
                    submitButton.prop('disabled', false);
                    // 后端错误回到对应步骤和字段；配置错误行定位到具体行
                    var details = ret && ret.data && ret.data.payload ? ret.data.payload : null;
                    var lineNo = details && (details.line || details.line_no) ? parseInt(details.line || details.line_no, 10) : 0;
                    showError(ret);
                    if (lineNo > 0 && state.lines[lineNo - 1]) {
                        renderConfigLines();
                        var panel = $('.cpq-config-line[data-line-key="' + state.lines[lineNo - 1].key + '"]');
                        if (panel.length && panel.get(0).scrollIntoView) {
                            panel.get(0).scrollIntoView({behavior: 'smooth', block: 'center'});
                        }
                    }
                });
            }, true, function (ret) {
                submitButton.prop('disabled', false);
            });
        });
    }

    // ------------------------------------------------------------------
    // 初始化与草稿恢复
    // ------------------------------------------------------------------

    function restoreQuote(quote) {
        state.id = parseInt(quote.id, 10) || 0;
        state.lock = parseInt(quote.optimistic_lock_version, 10) || 1;
        state.code = quote.code || '';
        state.status = quote.status || 'draft';
        $.each(quote.lines || [], function (_, line) {
            state.lines.push({
                key: ++lineSeq,
                model_id: parseInt(line.model_id, 10),
                model_code: line.model_code || '',
                model_name: line.model_name || '',
                quantity: String(line.quantity || '1'),
                unit: line.unit || 'set',
                configuration: line.configuration || {},
                accessories: line.accessories || [],
                manual_discount: line.manual_discount || '',
                discount_reason: line.discount_reason || '',
                schema: null,
                validation: null
            });
        });
        $.each(quote.terms || [], function (_, term) {
            state.terms.push({
                term_type: term.term_type || 'other',
                term_code: term.term_code || '',
                content: term.content || '',
                is_editable: parseInt(term.is_editable, 10)
            });
        });
    }

    function run() {
        state = {
            id: 0,
            lock: 1,
            code: '',
            status: 'draft',
            step: 1,
            lines: [],
            terms: [],
            priceResult: null,
            idempotencyKey: genIdempotencyKey()
        };
        var initial = window.__CPQ_QUOTE__ || {};
        if (initial && initial.id) {
            restoreQuote(initial);
        }

        Form.api.bindevent($('.cpq-wizard'));
        renderLinesTable();
        renderTerms();
        updateDraftHint();
        showStep(1);

        $('#cpq-prev').on('click', function () {
            showStep(Math.max(1, state.step - 1));
        });
        $('#cpq-next').on('click', nextStep);
        $('#cpq-steps').on('click', 'li', function () {
            var target = parseInt($(this).data('step'), 10);
            if (target <= state.step) {
                showStep(target);
            }
        });

        $('#cpq-save-draft').on('click', function () {
            if (!validateStep1()) {
                showStep(1);
                return;
            }
            saveDraft();
        });
        $('#cpq-add-line').on('click', addLine);
        $('#cpq-validate-all').on('click', function () {
            validateAllConfigs(function (allValid) {
                Toastr[allValid ? 'success' : 'error'](allValid ? '全部配置合法' : '存在不合法的配置，请按标红提示修正');
            });
        });
        $('#cpq-config-lines').on('click', '.cpq-validate-line', function () {
            var key = parseInt($(this).closest('.cpq-config-line').data('line-key'), 10);
            $.each(state.lines, function (_, line) {
                if (line.key === key) {
                    validateLine(line, function (valid) {
                        Toastr[valid ? 'success' : 'error'](valid ? '本行配置合法' : '本行配置不合法');
                    });
                    return false;
                }
            });
        });
        $('#cpq-config-lines').on('change input', 'input', function () {
            var key = parseInt($(this).closest('.cpq-config-line').data('line-key'), 10);
            $.each(state.lines, function (_, line) {
                if (line.key === key) {
                    scheduleLineValidation(line);
                    return false;
                }
            });
        });
        $('#cpq-recalc').on('click', function () {
            recalculate();
        });
        $('#cpq-add-term').on('click', function () {
            state.terms.push({term_type: 'payment', term_code: '', content: '', is_editable: 1});
            renderTerms();
        });
        $('#cpq-submit').on('click', submitQuote);

        // 头部上下文中任何影响价格的变化都使试算结果失效
        $('#cpq-quote-header').on('change input', '[name="customer_id"],[name="agent_id"],[name="currency"],[name="product_line"],[name="company"],[name="market_scope"]', markPriceStale);
    }

    return {run: run};
});
