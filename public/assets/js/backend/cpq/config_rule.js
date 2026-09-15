define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {

    // 与后端 RuleDsl 白名单保持一致（仅用于编辑器渲染，合法性以后端校验为准）
    var OPERATORS = [
        ['=', '等于'], ['!=', '不等于'], ['>', '大于'], ['>=', '大于等于'], ['<', '小于'], ['<=', '小于等于'],
        ['in', '包含于（多值）'], ['not_in', '不包含于（多值）'], ['contains', '含有选项'], ['selected', '已选择'],
        ['empty', '为空'], ['not_empty', '非空']
    ];
    var ACTIONS = [
        ['require', '依赖：必须选择目标'], ['exclude', '互斥：不能选择目标'], ['one_of', '目标取值必须在候选内'],
        ['min_max', '目标数量范围'], ['hide', '隐藏目标配置组'], ['show', '显示目标配置组'],
        ['default', '目标默认值'], ['set', '强制设置目标值'], ['formula', '目标按公式计算'], ['warning', '提示警告']
    ];
    var FORMULA_OPERATIONS = [['sum', '求和'], ['subtract', '相减'], ['multiply', '相乘'], ['divide', '相除']];

    // ------------------------------------------------------------------
    // 条件：结构化编辑 <-> JSON 双向同步
    // ------------------------------------------------------------------

    function isLeaf(node) {
        return node && typeof node === 'object' && !$.isArray(node)
            && !node.all && !node.any && !node.not
            && typeof node.field === 'string';
    }

    // JSON 条件 → 编辑器模型；不可结构化表达时返回 null
    function parseCondition(json) {
        var condition;
        try {
            condition = JSON.parse(json);
        } catch (e) {
            return null;
        }
        if (!condition || $.isArray(condition) || typeof condition !== 'object') {
            return null;
        }
        var model = {mode: 'all', not: false, leaves: []};
        var node = condition;
        if (node.not) {
            model.not = true;
            node = node.not;
            if (!node || typeof node !== 'object') {
                return null;
            }
        }
        var keys = Object.keys(node);
        if (keys.length === 0) {
            return model;
        }
        var children = node.all || node.any;
        if (!children || !$.isArray(children) || keys.length > 1) {
            return null;
        }
        model.mode = node.all ? 'all' : 'any';
        for (var i = 0; i < children.length; i++) {
            var child = children[i];
            var leafNot = false;
            if (child && child.not) {
                leafNot = true;
                child = child.not;
            }
            if (!isLeaf(child)) {
                return null;
            }
            model.leaves.push({
                field: child.field || '',
                operator: String(child.operator || '=').toLowerCase(),
                value: child.value,
                not: leafNot
            });
        }
        return model;
    }

    // 编辑器模型 → JSON 条件对象
    function buildCondition(model) {
        var leaves = $.map(model.leaves, function (leaf) {
            var node = {field: leaf.field, operator: leaf.operator};
            if (leaf.operator !== 'empty' && leaf.operator !== 'not_empty') {
                if (leaf.operator === 'in' || leaf.operator === 'not_in') {
                    node.value = $.map(String(leaf.value === undefined || leaf.value === null ? '' : leaf.value).split(','), function (item) {
                        return $.trim(item);
                    }).filter(function (item) {
                        return item !== '';
                    });
                } else if ($.inArray(leaf.operator, ['>', '>=', '<', '<=']) !== -1 && isFinite(leaf.value)) {
                    node.value = parseFloat(leaf.value);
                } else if ($.isArray(leaf.value)) {
                    node.value = leaf.value;
                } else {
                    node.value = leaf.value === undefined || leaf.value === null ? '' : String(leaf.value);
                }
            }
            return leaf.not ? {not: node} : node;
        });
        var node = {};
        if (leaves.length) {
            node[model.mode] = leaves;
        }
        return model.not ? {not: node} : node;
    }

    function leafValueText(value) {
        if (value === undefined || value === null) {
            return '';
        }
        return $.isArray(value) ? value.join(',') : String(value);
    }

    function renderConditionLeaf(leaf) {
        var row = $('<div class="form-inline" style="margin-bottom:6px"></div>');
        var field = $('<input class="form-control input-sm cpq-cond-field" style="width:220px" list="cpq-field-list" placeholder="configuration.配置组编码">')
            .val(leaf.field || '');
        var operator = $('<select class="form-control input-sm cpq-cond-operator"></select>');
        $.each(OPERATORS, function (_, pair) {
            operator.append($('<option></option>').attr('value', pair[0]).text(pair[1]));
        });
        operator.val(leaf.operator || '=');
        var value = $('<input class="form-control input-sm cpq-cond-value" style="width:180px" placeholder="取值，多值用逗号分隔">')
            .val(leafValueText(leaf.value));
        var notLabel = $('<label class="checkbox-inline" style="margin-left:6px"></label>')
            .append($('<input type="checkbox" class="cpq-cond-leaf-not">').prop('checked', !!leaf.not))
            .append(document.createTextNode('取反'));
        var remove = $('<button type="button" class="btn btn-xs btn-danger" style="margin-left:6px"><i class="fa fa-trash"></i></button>');
        remove.on('click', function () {
            row.remove();
            syncCondition();
        });
        row.append(field).append(' ').append(operator).append(' ').append(value).append(notLabel).append(remove);
        return row;
    }

    function readConditionModel() {
        var model = {
            mode: $('#cpq-cond-mode').val() || 'all',
            not: $('#cpq-cond-not').prop('checked'),
            leaves: []
        };
        $('#cpq-cond-list .cpq-cond-field').each(function () {
            var row = $(this).closest('.form-inline');
            model.leaves.push({
                field: $.trim($(this).val()),
                operator: row.find('.cpq-cond-operator').val(),
                value: row.find('.cpq-cond-value').val(),
                not: row.find('.cpq-cond-leaf-not').prop('checked')
            });
        });
        return model;
    }

    // 结构化编辑 → JSON 预览（结构化面板可表达时才回写）
    function syncCondition() {
        if ($('#cpq-cond-list').data('fallback')) {
            return;
        }
        var leaves = readConditionModel().leaves;
        for (var i = 0; i < leaves.length; i++) {
            if (leaves[i].field === '') {
                return; // 存在未完成行时暂不回写，避免覆盖合法 JSON
            }
        }
        $('#cpq-cond-json').val(JSON.stringify(buildCondition(readConditionModel()), null, 2)).trigger('change');
    }

    // JSON 预览 → 结构化编辑
    function parseConditionToEditor(silent) {
        var json = $('#cpq-cond-json').val();
        var model = parseCondition(json);
        var list = $('#cpq-cond-list');
        if (model === null) {
            if (!silent) {
                Toastr.error('条件 JSON 无法解析为结构化编辑，已保留 JSON 原文');
            }
            list.data('fallback', true).empty();
            $('#cpq-cond-fallback').removeClass('hidden');
            return;
        }
        list.data('fallback', false).empty();
        $('#cpq-cond-fallback').addClass('hidden');
        $('#cpq-cond-mode').val(model.mode);
        $('#cpq-cond-not').prop('checked', model.not);
        $.each(model.leaves, function (_, leaf) {
            list.append(renderConditionLeaf(leaf));
        });
    }

    // ------------------------------------------------------------------
    // 动作：结构化编辑 <-> JSON 双向同步
    // ------------------------------------------------------------------

    function parseActions(json) {
        var actions;
        try {
            actions = JSON.parse(json);
        } catch (e) {
            return null;
        }
        if (!actions || typeof actions !== 'object') {
            return null;
        }
        if (!$.isArray(actions)) {
            actions = actions.action ? [actions] : null;
            if (!actions) {
                return null;
            }
        }
        for (var i = 0; i < actions.length; i++) {
            var action = actions[i];
            if (!action || typeof action !== 'object' || $.isArray(action)) {
                return null;
            }
            var type = String(action.action || '').toLowerCase();
            var known = false;
            $.each(ACTIONS, function (_, pair) {
                if (pair[0] === type) {
                    known = true;
                    return false;
                }
            });
            if (!known) {
                return null;
            }
            action.action = type;
        }
        return actions;
    }

    function actionExtras(row, action) {
        // 按动作类型渲染受控的附加字段；未知键在 row._raw 中原样保留
        var extras = $('<span class="cpq-act-extras"></span>');
        var type = action.action;
        function input(cls, placeholder, value, width) {
            return $('<input class="form-control input-sm ' + cls + '" style="width:' + (width || 140) + 'px;display:inline-block;margin-left:6px">')
                .attr('placeholder', placeholder)
                .val(value === undefined || value === null ? '' : ($.isArray(value) ? value.join(',') : String(value)));
        }
        if (type === 'require' || type === 'exclude') {
            extras.append(input('cpq-act-value', '取值（可空）', leafValueText(action.value)));
        } else if (type === 'one_of') {
            extras.append(input('cpq-act-value', '候选取值，逗号分隔（必填）', leafValueText(action.value), 200));
        } else if (type === 'min_max') {
            extras.append(input('cpq-act-min', '最小值', action.min, 90));
            extras.append(input('cpq-act-max', '最大值', action.max, 90));
        } else if (type === 'default' || type === 'set') {
            extras.append(input('cpq-act-value', '取值（必填）', leafValueText(action.value), 160));
        } else if (type === 'formula') {
            var operation = $('<select class="form-control input-sm cpq-act-operation" style="width:110px;display:inline-block;margin-left:6px"></select>');
            $.each(FORMULA_OPERATIONS, function (_, pair) {
                operation.append($('<option></option>').attr('value', pair[0]).text(pair[1]));
            });
            operation.val(String(action.operation || 'sum').toLowerCase());
            extras.append(operation);
            var operands = $.map(action.operands || [], function (operand) {
                if (operand && typeof operand === 'object') {
                    return operand.field !== undefined ? operand.field : String(operand.value);
                }
                return String(operand);
            });
            extras.append(input('cpq-act-operands', '操作数，逗号分隔；字段写 configuration.xxx', operands.join(','), 260));
            extras.append(input('cpq-act-scale', '小数位', action.scale === undefined ? 4 : action.scale, 70));
        } else if (type === 'warning') {
            extras.append('<span class="text-muted small" style="margin-left:6px">提示文案取「提示信息」字段</span>');
        }
        return extras;
    }

    function renderActionRow(action) {
        var row = $('<div class="form-inline cpq-act-row" style="margin-bottom:6px"></div>');
        row.data('raw', action);
        var type = $('<select class="form-control input-sm cpq-act-type" style="width:180px"></select>');
        $.each(ACTIONS, function (_, pair) {
            type.append($('<option></option>').attr('value', pair[0]).text(pair[1]));
        });
        type.val(action.action);
        var target = $('<input class="form-control input-sm cpq-act-target" style="width:180px;margin-left:6px" list="cpq-target-list" placeholder="目标配置组编码">')
            .val(action.target || '');
        var remove = $('<button type="button" class="btn btn-xs btn-danger" style="margin-left:6px"><i class="fa fa-trash"></i></button>');
        var extras = actionExtras(row, action);
        remove.on('click', function () {
            row.remove();
            syncActions();
        });
        type.on('change', function () {
            var rebuilt = actionExtras(row, {action: type.val()});
            row.find('.cpq-act-extras').replaceWith(rebuilt);
            syncActions();
        });
        row.append(type).append(target).append(extras).append(remove);
        return row;
    }

    function numericOrNull(text) {
        text = $.trim(text);
        return text !== '' && isFinite(text) ? parseFloat(text) : null;
    }

    function readActions() {
        var actions = [];
        var incomplete = false;
        $('#cpq-act-list .cpq-act-row').each(function () {
            var row = $(this);
            var raw = $.extend({}, row.data('raw') || {});
            var type = row.find('.cpq-act-type').val();
            raw.action = type;
            raw.target = $.trim(row.find('.cpq-act-target').val());
            if (raw.target === '') {
                incomplete = true;
            }
            if (type === 'min_max') {
                var min = numericOrNull(row.find('.cpq-act-min').val());
                var max = numericOrNull(row.find('.cpq-act-max').val());
                delete raw.value;
                if (min === null) {
                    delete raw.min;
                } else {
                    raw.min = min;
                }
                if (max === null) {
                    delete raw.max;
                } else {
                    raw.max = max;
                }
            } else if (type === 'formula') {
                raw.operation = row.find('.cpq-act-operation').val();
                var scale = numericOrNull(row.find('.cpq-act-scale').val());
                raw.scale = scale === null ? 4 : scale;
                raw.operands = $.map($.trim(row.find('.cpq-act-operands').val()).split(','), function (token) {
                    token = $.trim(token);
                    if (token === '') {
                        return null;
                    }
                    return isFinite(token) ? parseFloat(token) : {field: token};
                }).filter(function (operand) {
                    return operand !== null;
                });
                delete raw.value;
                delete raw.min;
                delete raw.max;
            } else if (type === 'hide' || type === 'show' || type === 'warning') {
                delete raw.value;
                delete raw.min;
                delete raw.max;
            } else {
                var valueText = row.find('.cpq-act-value').length ? row.find('.cpq-act-value').val() : '';
                if (type === 'one_of') {
                    raw.value = $.map(String(valueText).split(','), function (item) {
                        return $.trim(item);
                    }).filter(function (item) {
                        return item !== '';
                    });
                } else if (valueText !== '') {
                    raw.value = valueText;
                } else {
                    delete raw.value;
                }
                delete raw.min;
                delete raw.max;
            }
            actions.push(raw);
        });
        return incomplete ? null : actions;
    }

    function syncActions() {
        var actions = readActions();
        if (actions === null) {
            return; // 存在未完成行时暂不回写
        }
        $('#cpq-act-json').val(JSON.stringify(actions, null, 2)).trigger('change');
    }

    function parseActionsToEditor(silent) {
        var actions = parseActions($('#cpq-act-json').val());
        var list = $('#cpq-act-list');
        if (actions === null) {
            if (!silent) {
                Toastr.error('动作 JSON 无法解析为结构化编辑，请检查动作类型是否合法');
            }
            return;
        }
        list.empty();
        $.each(actions, function (_, action) {
            list.append(renderActionRow(action));
        });
    }

    // ------------------------------------------------------------------
    // 型号联动：字段/目标候选提示
    // ------------------------------------------------------------------

    function loadSchemaHints() {
        var modelId = $('[name="row[model_id]"]').val();
        $('#cpq-field-list').empty();
        $('#cpq-target-list').empty();
        if (!modelId) {
            return;
        }
        Fast.api.ajax({url: 'cpq/configurator/schema', type: 'get', data: {model_id: modelId}}, function (schema) {
            $.each((schema && schema.groups) || [], function (_, group) {
                $('#cpq-field-list').append($('<option></option>').attr('value', 'configuration.' + group.code).text(group.name));
                $('#cpq-target-list').append($('<option></option>').attr('value', group.code).text(group.name));
            });
            return false;
        }, function () {
            return false;
        });
    }

    // ------------------------------------------------------------------
    // 单规则测试 / 冲突检测
    // ------------------------------------------------------------------

    function rulePayload() {
        return {
            code: $.trim($('[name="row[code]"]').val()),
            model_id: $('[name="row[model_id]"]').val(),
            product_line: $.trim($('[name="row[product_line]"]').val()),
            severity: $('[name="row[severity]"]').val() || 'blocking',
            priority: $('[name="row[priority]"]').val() || 0,
            message: $('[name="row[message]"]').val() || '',
            condition_json: $('#cpq-cond-json').val(),
            action_json: $('#cpq-act-json').val()
        };
    }

    function renderTestResult(result) {
        var box = $('#cpq-test-result').empty();
        var matchedLabel = result.matched
            ? '<span class="label label-success">规则命中</span>'
            : '<span class="label label-default">规则未命中</span>';
        var validLabel = result.is_valid
            ? ' <span class="label label-success">配置合法</span>'
            : ' <span class="label label-danger">配置不合法</span>';
        box.append($('<p style="margin-top:8px"></p>').html(matchedLabel + validLabel));

        function appendIssues(title, issues, className) {
            if (!issues || !issues.length) {
                return;
            }
            var list = $('<ul class="' + className + '" style="margin-bottom:4px"></ul>');
            $.each(issues, function (_, issue) {
                list.append($('<li></li>').text((issue.path ? '[' + issue.path + '] ' : '') + issue.message));
            });
            box.append($('<strong></strong>').text(title)).append(list);
        }

        appendIssues('错误', result.errors, 'text-danger');
        appendIssues('警告', result.warnings, 'text-warning');
        if (result.hidden_groups && result.hidden_groups.length) {
            box.append($('<p class="text-muted"></p>').text('隐藏配置组：' + result.hidden_groups.join('、')));
        }
        box.append($('<p class="help-block">试算后配置</p>'));
        box.append($('<pre style="max-height:220px;overflow:auto"></pre>').text(JSON.stringify(result.configuration, null, 2)));
        if (result.configuration_hash) {
            box.append($('<p class="help-block" style="word-break:break-all"></p>').text('配置指纹：' + result.configuration_hash));
        }
    }

    function renderAnalyzeResult(issues) {
        var box = $('#cpq-analyze-result').empty();
        box.append($('<p style="margin-top:8px"></p>').html(issues.is_clean
            ? '<span class="label label-success">未发现循环依赖、永真冲突或不可达选项</span>'
            : '<span class="label label-danger">发现静态分析问题，发布会失败</span>'));

        function appendList(title, items, className) {
            if (!items || !items.length) {
                return;
            }
            var list = $('<ul class="' + className + '" style="margin-bottom:4px"></ul>');
            $.each(items, function (_, item) {
                var text = typeof item === 'string'
                    ? item
                    : ('路径 ' + (item.path || []).join(' → ') + '，涉及规则 ' + (item.rule_codes || []).join('、'));
                list.append($('<li></li>').text(text));
            });
            box.append($('<strong></strong>').text(title)).append(list);
        }

        appendList('循环依赖', issues.cycles, 'text-danger');
        appendList('永真冲突', issues.conflicts, 'text-danger');
        appendList('不可达选项', issues.unreachable, 'text-warning');
    }

    function initRuleEditor() {
        parseConditionToEditor(true);
        parseActionsToEditor(true);
        // 统一 JSON 预览格式（可解析时回写规范化 JSON）
        if (!$('#cpq-cond-list').data('fallback')) {
            syncCondition();
        }
        if (parseActions($('#cpq-act-json').val()) !== null) {
            syncActions();
        }

        loadSchemaHints();
        $('[name="row[model_id]"]').on('change', loadSchemaHints);

        $('#cpq-cond-add').on('click', function () {
            if ($('#cpq-cond-list').data('fallback')) {
                Toastr.error('当前条件 JSON 无法结构化编辑，请先修正 JSON');
                return;
            }
            $('#cpq-cond-list').append(renderConditionLeaf({field: '', operator: '=', value: '', not: false}));
        });
        $('#cpq-act-add').on('click', function () {
            $('#cpq-act-list').append(renderActionRow({action: 'require', target: ''}));
        });

        // 结构化编辑的任何变化都同步到 JSON 预览（提交以 JSON 字段为准）
        $('#cpq-cond-mode,#cpq-cond-not').on('change', syncCondition);
        $('#cpq-cond-list').on('change input', 'input,select', syncCondition);
        $('#cpq-act-list').on('change input', 'input,select', syncActions);
        // 手工修改 JSON 后失焦解析回结构化编辑
        $('#cpq-cond-json').on('blur', function () {
            parseConditionToEditor(false);
        });
        $('#cpq-act-json').on('blur', function () {
            parseActionsToEditor(false);
        });

        $('#cpq-test-run').on('click', function () {
            var payload = rulePayload();
            if (!payload.model_id) {
                Toastr.error('单规则测试要求先选择适用型号');
                return;
            }
            payload.configuration = $.trim($('#cpq-test-config').val()) || '{}';
            Fast.api.ajax({url: 'cpq/config_rule/testrule', type: 'post', data: payload}, function (result) {
                renderTestResult(result);
                return false;
            });
        });
        $('#cpq-analyze-run').on('click', function () {
            Fast.api.ajax({url: 'cpq/config_rule/analyze', type: 'post', data: rulePayload()}, function (issues) {
                renderAnalyzeResult(issues);
                return false;
            });
        });
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/config_rule/index' + location.search,
                add_url: 'cpq/config_rule/add',
                edit_url: 'cpq/config_rule/edit',
                del_url: 'cpq/config_rule/del',
                multi_url: 'cpq/config_rule/multi',
                detail_url: 'cpq/config_rule/detail',
                table: 'cpq_config_rule'
            }});
            var table = $('#table');
            var baseUrl = 'cpq/config_rule';
            var auth = CpqCommon.readAuth(table, ['submit', 'publish', 'expire', 'copy']);
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'priority',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '规则编码', operate: 'LIKE'},
                    {field: 'name', title: '规则名称', operate: 'LIKE'},
                    {field: 'type', title: '规则类型', searchList: Config.typeList, formatter: Table.api.formatter.normal},
                    {field: 'product_model.name', title: '适用型号', operate: 'LIKE'},
                    {field: 'product_line', title: '产品线'},
                    {field: 'priority', title: '优先级'},
                    {field: 'severity', title: '严重级别', searchList: Config.severityList, formatter: Table.api.formatter.normal},
                    {field: 'version', title: '版本', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: CpqCommon.operateEvents(),
                        buttons: [CpqCommon.detailButton(baseUrl)]
                            .concat(CpqCommon.versionGuardButtons())
                            .concat(CpqCommon.versionButtons(baseUrl, auth)),
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () {
            initRuleEditor();
            Controller.api.bindevent();
        },
        edit: function () {
            initRuleEditor();
            Controller.api.bindevent();
        },
        detail: function () {
            initRuleEditor();
            Controller.api.bindevent();
            CpqCommon.bindDetail();
        },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
