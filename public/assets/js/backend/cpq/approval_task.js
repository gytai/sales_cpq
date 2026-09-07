define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, moment, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);

    var levelTexts = {none: '无需审批', line: '产线审批', company: '公司审批'};
    var levelCustom = {none: 'success', line: 'warning', company: 'danger'};
    function levelFormatter(value) {
        return '<span class="label label-' + (levelCustom[value] || 'default') + '">' + (levelTexts[value] || value || '—') + '</span>';
    }

    function severityBadge(severity) {
        var map = {high: 'danger', medium: 'warning', info: 'info'};
        var text = {high: '高', medium: '中', info: '提示'};
        return '<span class="label label-' + (map[severity] || 'default') + '">' + (text[severity] || severity) + '</span>';
    }

    // 审批动作统一出口（JSON 载荷 + 幂等键），失败弹窗展示服务端业务错误与业务码
    function postAction(data, onSuccess) {
        Fast.api.ajax({
            url: 'cpq/approval_task/action',
            type: 'POST',
            contentType: 'application/json; charset=UTF-8',
            data: JSON.stringify(data)
        }, function (resp, ret) {
            var payload = ret && ret.data && ret.data.payload ? ret.data.payload : resp;
            if (payload && payload.idempotent) {
                Toastr.info('该动作已提交过（幂等），未重复执行');
            } else {
                Toastr.success('审批动作已完成');
            }
            if (typeof onSuccess === 'function') {
                onSuccess(payload);
            }
            return false;
        }, function (data, ret) {
            var message = ret && ret.msg ? ret.msg : '操作失败';
            var code = ret && ret.data && ret.data.business_code ? ' [' + ret.data.business_code + ']' : '';
            Layer.alert(escapeHtml(message + code), {icon: 2, title: '操作被拒绝'});
            return false;
        });
    }

    // 审批意见对话框：意见 + 原因分类 +（加签/转交）目标候选人
    function actionDialog(taskId, action, title, onConfirm) {
        var actionTexts = {approve: '批准', reject: '驳回', 'return': '退回修改', add_sign: '加签', transfer: '转交'};
        var needTarget = action === 'add_sign' || action === 'transfer';
        var render = function (candidates) {
            var targetHtml = '';
            if (needTarget) {
                if (!candidates.length) {
                    Toastr.error('该报价产品线内暂无可用候选人');
                    return;
                }
                targetHtml = '<div class="form-group"><label>' + (action === 'add_sign' ? '加签处理人（会签，须处理完毕节点才推进）' : '转交给') + '</label>' +
                    '<select id="cpq-act-target" class="form-control">';
                $.each(candidates, function (_, item) {
                    targetHtml += '<option value="' + item.id + '">' + escapeHtml(item.nickname + '（' + item.username + '）') + '</option>';
                });
                targetHtml += '</select></div>';
            }
            Layer.open({
                type: 1,
                title: title + '：' + actionTexts[action],
                area: ['460px', needTarget ? '420px' : '340px'],
                content: '<div style="padding:18px">' +
                    '<div class="form-group"><label>审批意见' + (action === 'approve' ? '（可留空）' : '（必填）') + '</label>' +
                    '<textarea id="cpq-act-comment" class="form-control" rows="3" placeholder="请填写审批意见"></textarea></div>' +
                    '<div class="form-group"><label>原因分类</label>' +
                    '<input id="cpq-act-reason" class="form-control" list="cpq-act-reason-list" placeholder="如 价格偏低/资料不全/其他">' +
                    '<datalist id="cpq-act-reason-list"><option value="价格偏低"><option value="毛利不足"><option value="资料不全"><option value="条款偏差"><option value="其他"></datalist></div>' +
                    targetHtml + '</div>',
                btn: ['确认' + actionTexts[action], '取消'],
                yes: function (index) {
                    var comment = $.trim($('#cpq-act-comment').val());
                    if (action !== 'approve' && !comment) {
                        Toastr.error('请填写审批意见');
                        return;
                    }
                    Layer.close(index);
                    onConfirm({
                        comment: comment,
                        reason_category: $.trim($('#cpq-act-reason').val()),
                        next_assignee_id: needTarget ? parseInt($('#cpq-act-target').val(), 10) : 0
                    });
                }
            });
        };
        if (needTarget) {
            Fast.api.ajax({url: 'cpq/approval_task/candidates', type: 'GET', data: {task_id: taskId}}, function (data, ret) {
                var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
                render((payload && payload.candidates) || []);
                return false;
            }, function (data, ret) {
                Toastr.error(ret && ret.msg ? ret.msg : '候选人加载失败');
                return false;
            });
        } else {
            render([]);
        }
    }

    var Controller = {
        // P70 我的待审批
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/approval_task/index' + location.search,
                detail_url: 'cpq/approval_task/detail'
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['detail', 'urge', 'action']);
            var state = {category: 'pending'};

            function buildColumns(category) {
                var columns = [
                    {checkbox: false},
                    {field: 'quote_code', title: '业务单号', operate: false, formatter: function (value, row) {
                        return '<strong>' + escapeHtml(value) + '</strong>' +
                            (row.quote_name ? '<br><small class="text-muted">' + escapeHtml(row.quote_name) + '</small>' : '');
                    }},
                    {field: 'customer_name', title: '客户', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'product_line', title: '产品线', operate: false},
                    {field: 'total_amount', title: '金额', operate: false, align: 'right', formatter: CpqCommon.moneyFormatter},
                    {field: 'approval_level', title: '审批等级', operate: false, formatter: levelFormatter},
                    {field: 'node_text', title: '节点', operate: false},
                    {field: 'initiator_name', title: '申请人', operate: false},
                    {field: 'arrived_at', title: '到达时间', operate: false, formatter: Table.api.formatter.datetime},
                    {field: 'sla_remaining', title: 'SLA', operate: false, formatter: CpqCommon.slaFormatter}
                ];
                if (category === 'pending') {
                    columns.push({field: 'delegator_name', title: '来源', operate: false, formatter: function (value, row) {
                        return parseInt(row.is_delegate_view, 10) === 1
                            ? '<span class="label label-primary">代理 ' + escapeHtml(value || ('#' + row.assignee_id)) + '</span>'
                            : '<span class="text-muted">本人</span>';
                    }});
                }
                if (category !== 'pending') {
                    columns.push({field: 'status', title: '状态', operate: false, formatter: Table.api.formatter.status, custom: statusCustom});
                    columns.push({field: 'action', title: '动作', operate: false, formatter: function (value, row) {
                        if (!value) {
                            return '<span class="text-muted">—</span>';
                        }
                        var texts = {confirm: '销售确认', approve: '批准', reject: '驳回', 'return': '退回修改', transfer: '转交', add_sign: '加签'};
                        var color = CpqCommon.actionCustom[value] || 'default';
                        return '<span class="label label-' + color + '">' + (texts[value] || value) + '</span>';
                    }});
                }
                if (category === 'cc') {
                    columns.push({field: 'cc_source', title: '抄送来源', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }});
                }
                columns.push({
                    field: 'operate', title: __('Operate'), table: table,
                    events: {
                        'click .btn-cpq-urge': function (e, value, row) {
                            e.stopPropagation();
                            Layer.confirm('确认催办任务 #' + row.id + '？', {icon: 3, title: '催办确认'}, function (index) {
                                Layer.close(index);
                                Fast.api.ajax({url: 'cpq/approval_task/urge', type: 'POST', data: {task_id: row.id}}, function () {
                                    Toastr.success('已发送催办提醒');
                                    return false;
                                });
                            });
                        }
                    },
                    buttons: [
                        {
                            name: 'detail', text: category === 'pending' ? '进入审批' : '查看', title: '审批详情', icon: 'fa fa-list',
                            classname: 'btn btn-xs btn-info btn-dialog',
                            url: 'cpq/approval_task/detail/ids/{id}',
                            extend: 'data-toggle="tooltip" data-container="body" data-area=\'["90%","92%"]\'',
                            visible: function () { return auth.detail; }
                        },
                        {
                            name: 'urge', text: '催办', icon: 'fa fa-bell',
                            classname: 'btn btn-xs btn-warning btn-cpq-urge',
                            visible: function (row) {
                                return auth.urge && row.status === 'pending' && parseInt(row.is_delegate_view, 10) === 0;
                            }
                        }
                    ],
                    formatter: Table.api.formatter.operate
                });
                return columns;
            }

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.category = state.category;
                    params.node = $('#cpq-approval-node').val() || '';
                    params.product_line = $('#cpq-approval-line').val() || '';
                    params.keyword = $.trim($('#cpq-approval-keyword').val() || '');
                    return params;
                },
                columns: [buildColumns('pending')]
            });
            Table.api.bindevent(table);

            $('#cpq-approval-tabs').on('click', 'button', function () {
                $('#cpq-approval-tabs button').removeClass('active');
                $(this).addClass('active');
                state.category = $(this).data('category');
                table.bootstrapTable('refreshOptions', {columns: [buildColumns(state.category)]});
                table.bootstrapTable('refresh');
            });
            $('#cpq-approval-node, #cpq-approval-line').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-approval-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
        },

        // P71 报价审批详情：配置/价格/条款/风险/版本差异/轨迹 + 受限审批操作
        detail: function () {
            var detail = window.__CPQ_APPROVAL__ || {};
            var quote = detail.quote || {};
            var task = detail.task || {};
            var instance = detail.instance || {};
            var taskId = parseInt(detail.task && detail.task.id, 10) || 0;

            // 顶部：版本失效与代理提示
            if (parseInt(detail.version_stale, 10) === 1) {
                $('#cpq-apd-stale').removeClass('hidden').html('<i class="fa fa-exclamation-triangle"></i> ' +
                    '报价版本已变化（当前 v' + escapeHtml(quote.current_revision_no) + '，本任务对应 v' + escapeHtml(task.revision_no) +
                    '）或报价已不在审批中，本任务已失效；请等待新版本重新提交后处理。');
            }
            if (parseInt(detail.is_delegate, 10) === 1) {
                $('#cpq-apd-delegate').removeClass('hidden').html('<i class="fa fa-user-plus"></i> ' +
                    '你正在以代理人身份处理 <strong>' + escapeHtml(detail.delegator_name || '') + '</strong> 的审批任务，代理不突破你原有的数据权限。');
            }

            // 报价摘要
            var summary = '<dl class="dl-horizontal">';
            summary += '<dt>报价单号</dt><dd><strong>' + escapeHtml(quote.code) + '</strong>　' + escapeHtml(quote.name || '') + '</dd>';
            summary += '<dt>报价状态</dt><dd><span class="label label-' + (statusCustom[quote.status] || 'default') + '">' + escapeHtml(quote.status_text || quote.status) + '</span></dd>';
            summary += '<dt>客户</dt><dd>' + escapeHtml(quote.customer_name || '—') + '</dd>';
            summary += '<dt>产品线 / 币种</dt><dd>' + escapeHtml(quote.product_line || '—') + ' / ' + escapeHtml(quote.currency || '—') + '</dd>';
            summary += '<dt>负责人 / 提交人</dt><dd>' + escapeHtml(quote.owner_name || '—') + ' / ' + escapeHtml(quote.initiator_name || '—') + '</dd>';
            summary += '<dt>提交时间</dt><dd>' + (quote.submitted_at ? moment.unix(quote.submitted_at).format('YYYY-MM-DD HH:mm:ss') : '—') + '</dd>';
            summary += '<dt>冻结版本</dt><dd>v' + escapeHtml(task.revision_no) + '（当前 v' + escapeHtml(quote.current_revision_no) + '）</dd>';
            summary += '</dl>';
            $('#cpq-apd-quote').html(summary);

            // 审批路径 + SLA
            var pathHtml = '';
            $.each(detail.nodes || [], function (_, node) {
                var isCurrent = node.node === instance.current_node && instance.status === 'active';
                var chips = '';
                $.each(node.tasks || [], function (_, nodeTask) {
                    var color = statusCustom[nodeTask.status] || 'default';
                    chips += '<div><small>' + escapeHtml(nodeTask.assignee_name || ('#' + nodeTask.assignee_id)) + ' ' +
                        '<span class="label label-' + color + '">' + escapeHtml(nodeTask.status) + '</span>' +
                        (parseInt(nodeTask.is_required, 10) === 1 ? ' <span class="label label-primary">会签</span>' : '') + '</small></div>';
                });
                pathHtml += '<div class="node-chip' + (isCurrent ? ' node-current' : '') + '"><strong>' +
                    escapeHtml(node.node_text || node.node) + '</strong>' + (chips || ' <small class="text-muted">未开始</small>') + '</div>';
            });
            $('#cpq-apd-path').html(pathHtml || '<span class="text-muted">无路径信息</span>');
            var sla = detail.sla || {};
            var slaHtml = '<dl class="dl-horizontal" style="margin-bottom:0">';
            slaHtml += '<dt>SLA 截止</dt><dd>' + (sla.deadline ? moment.unix(sla.deadline).format('YYYY-MM-DD HH:mm:ss') : '—') + '</dd>';
            slaHtml += '<dt>剩余时间</dt><dd>' + CpqCommon.slaFormatter(sla.remaining, {overdue: sla.overdue}) + '</dd>';
            slaHtml += '<dt>任务状态</dt><dd><span class="label label-' + (statusCustom[task.status] || 'default') + '">' + escapeHtml(task.status) + '</span></dd>';
            slaHtml += '</dl>';
            $('#cpq-apd-sla').html(slaHtml);

            // 配置与明细行（冻结价格快照行）
            var lineRows = '';
            $.each(detail.lines || [], function (_, line) {
                lineRows += '<tr><td>' + escapeHtml(line.line_no) + '</td>' +
                    '<td>' + escapeHtml(line.model_code || '') + '</td>' +
                    '<td class="text-amount">' + escapeHtml(line.quantity) + '</td>' +
                    '<td class="text-amount">' + escapeHtml(line.unit_subtotal) + '</td>' +
                    '<td class="text-amount">' + escapeHtml(line.total_amount) + '</td>' +
                    '<td>' + CpqCommon.classificationFormatter(line.classification || 'normal') + '</td>' +
                    '<td class="text-amount">' + escapeHtml(line.manual_discount || '—') +
                    (line.discount_reason ? '<br><small class="text-muted">' + escapeHtml(line.discount_reason) + '</small>' : '') + '</td></tr>';
            });
            $('#cpq-apd-lines').html(lineRows || '<tr><td colspan="7" class="text-muted text-center">无明细行</td></tr>');

            // 价格（脱敏后的服务端结果原样展示）
            var pricing = detail.pricing || {};
            var amountLabels = {goods_discounted: '折扣后商品金额', fees: '费用', untaxed: '未税金额', tax: '税额', total: '含税总额', margin_amount: '毛利额'};
            var priceRows = '';
            $.each(pricing.lines || [], function (_, line) {
                var converted = line.converted || {};
                var amounts = '';
                $.each(amountLabels, function (key, label) {
                    if (converted[key] !== undefined) {
                        amounts += '<div><small class="text-muted">' + label + '</small> <span class="text-amount">' + escapeHtml(converted[key]) + '</span></div>';
                    }
                });
                priceRows += '<tr><td>' + escapeHtml(line.line_no) + '</td>' +
                    '<td>' + escapeHtml(line.model_code || (line.model && line.model.code) || '') + '</td>' +
                    '<td>' + CpqCommon.classificationFormatter(line.classification || 'normal') + '</td>' +
                    '<td>' + (amounts || '<span class="text-muted">—</span>') + '</td></tr>';
            });
            $('#cpq-apd-price-lines').html(priceRows || '<tr><td colspan="4" class="text-muted text-center">无价格数据</td></tr>');
            var totalsRows = '';
            $.each(amountLabels, function (key, label) {
                if (pricing.totals && pricing.totals[key] !== undefined) {
                    totalsRows += '<tr><th style="width:160px">' + label + '</th><td class="text-amount">' + escapeHtml(pricing.totals[key]) + '</td></tr>';
                }
            });
            $('#cpq-apd-price-totals').html(totalsRows || '<tr><td class="text-muted">无汇总数据</td></tr>');

            // 商务条款
            var termTypes = {payment: '付款', trade: '贸易', warranty: '质保', delivery: '交付', other: '其他'};
            var termRows = '';
            $.each(detail.terms || [], function (_, term) {
                termRows += '<tr><td>' + escapeHtml(termTypes[term.term_type] || term.term_type) + '</td>' +
                    '<td>' + escapeHtml(term.term_code || '—') + '</td>' +
                    '<td><pre>' + escapeHtml(term.content || '') + '</pre></td></tr>';
            });
            $('#cpq-apd-terms').html(termRows || '<tr><td colspan="3" class="text-muted text-center">无条款</td></tr>');

            // 风险项
            var risksHtml = '';
            $.each(detail.risks || [], function (_, risk) {
                risksHtml += '<div class="risk-item">' + severityBadge(risk.severity) + ' <strong>' +
                    escapeHtml(risk.title) + '</strong><br><small>' + escapeHtml(risk.detail) + '</small></div>';
            });
            $('#cpq-apd-risks').html(risksHtml || '<span class="text-muted">无风险项</span>');

            // 历史审批
            var actionRows = '';
            $.each(detail.actions || [], function (_, action) {
                var color = CpqCommon.actionCustom[action.action] || 'default';
                actionRows += '<div class="timeline-item timeline-' + (color === 'default' ? 'info' : color) + '">' +
                    '<span class="label label-' + color + '">' + escapeHtml(action.action_text || action.action) + '</span> ' +
                    '<strong>' + escapeHtml(action.actor_name || ('#' + action.actor_id)) + '</strong>' +
                    (parseInt(action.delegate_from_id, 10) > 0 ? ' <span class="label label-primary">代理处理</span>' : '') +
                    (action.node_text ? ' <small class="text-muted">@ ' + escapeHtml(action.node_text) + '</small>' : '') +
                    '<br><small class="text-muted">' + (action.createtime ? moment.unix(action.createtime).format('YYYY-MM-DD HH:mm:ss') : '') +
                    (action.reason_category ? '　原因：' + escapeHtml(action.reason_category) : '') + '</small>' +
                    (action.comment ? '<div>' + escapeHtml(action.comment) + '</div>' : '') +
                    '</div>';
            });
            $('#cpq-apd-actions').html(actionRows || '<span class="text-muted">暂无审批动作</span>');

            // 审批操作：can_act 由服务端按 本人/代理人 + 待处理 + 版本一致 计算
            var canAct = parseInt(detail.can_act, 10) === 1;
            var hint = $('#cpq-apd-operate-hint');
            if (!canAct) {
                $('#cpq-apd-operate button').prop('disabled', true);
                hint.text(parseInt(detail.version_stale, 10) === 1
                    ? '任务已失效（版本变化），不可操作。'
                    : '当前任务不可操作：仅待处理任务的审批人或其有效代理人可处理。');
            } else {
                hint.text('动作仅包含批准/驳回/退回/加签/转交；提交时携带幂等键与版本校验，服务端逐项重复校验。');
            }
            $('#cpq-apd-operate').on('click', 'button', function () {
                if (!canAct) {
                    return;
                }
                var action = $(this).data('action');
                actionDialog(taskId, action, '审批 ' + escapeHtml(quote.code || ''), function (form) {
                    postAction({
                        task_id: taskId,
                        action: action,
                        comment: form.comment,
                        reason_category: form.reason_category,
                        next_assignee_id: form.next_assignee_id,
                        idempotency_key: CpqCommon.genActionKey('APPROVAL')
                    }, function () {
                        canAct = false;
                        $('#cpq-apd-operate button').prop('disabled', true);
                        hint.text('动作已完成，任务状态以刷新后为准。');
                        location.reload();
                    });
                });
            });

            // 版本差异
            var diff = detail.version_diff;
            if (!diff) {
                $('#cpq-apd-diff').html('<span class="text-muted">首个冻结版本，无对比基线。</span>');
            } else {
                var changeTexts = {added: '新增', removed: '删除', modified: '修改'};
                var fieldTexts = {model: '型号', quantity: '数量', configuration: '配置', price: '价格'};
                var diffHtml = '<p class="text-muted">v' + escapeHtml(diff.from_revision_no) + ' → v' + escapeHtml(diff.to_revision_no) +
                    '，明细行变化 ' + escapeHtml(diff.summary && diff.summary.line_changes) + ' 处。</p>';
                var diffTotals = (diff.summary && diff.summary.totals) || {};
                diffHtml += '<table class="table table-bordered table-condensed"><thead><tr><th>口径</th><th>v' +
                    escapeHtml(diff.from_revision_no) + '</th><th>v' + escapeHtml(diff.to_revision_no) + '</th></tr></thead><tbody>';
                var totalsKeys = {};
                $.each([diffTotals.from || {}, diffTotals.to || {}], function (_, totals) {
                    $.each(totals, function (key) { totalsKeys[key] = true; });
                });
                $.each(totalsKeys, function (key) {
                    var left = diffTotals.from && diffTotals.from[key] != null ? String(diffTotals.from[key]) : '—';
                    var right = diffTotals.to && diffTotals.to[key] != null ? String(diffTotals.to[key]) : '—';
                    diffHtml += '<tr' + (left !== right ? ' class="warning"' : '') + '><th>' + escapeHtml(amountLabels[key] || key) +
                        '</th><td class="text-amount">' + escapeHtml(left) + '</td><td class="text-amount">' + escapeHtml(right) + '</td></tr>';
                });
                diffHtml += '</tbody></table>';
                if ((diff.lines || []).length) {
                    diffHtml += '<table class="table table-bordered table-condensed"><thead><tr><th style="width:70px">行号</th><th style="width:80px">变化</th><th>字段差异</th></tr></thead><tbody>';
                    $.each(diff.lines, function (_, line) {
                        var rowClass = line.change === 'added' ? 'diff-added' : (line.change === 'removed' ? 'diff-removed' : 'diff-modified');
                        var fields = '';
                        $.each(line.fields || {}, function (field, change) {
                            fields += '<div><strong>' + escapeHtml(fieldTexts[field] || field) + '</strong>：<code>' +
                                escapeHtml(change.from == null ? '—' : String(change.from)) + '</code> → <code>' +
                                escapeHtml(change.to == null ? '—' : String(change.to)) + '</code></div>';
                        });
                        diffHtml += '<tr class="' + rowClass + '"><td>' + escapeHtml(line.line_no) + '</td><td>' +
                            escapeHtml(changeTexts[line.change] || line.change) + '</td><td>' + (fields || '—') + '</td></tr>';
                    });
                    diffHtml += '</tbody></table>';
                }
                $('#cpq-apd-diff').html(diffHtml);
            }

            // 规则执行轨迹：按节点的任务表 + 动作时间线摘要
            var traceHtml = '';
            $.each(detail.nodes || [], function (_, node) {
                traceHtml += '<h5 style="margin-top:0"><i class="fa fa-circle-o"></i> ' + escapeHtml(node.node_text || node.node) + '</h5>';
                traceHtml += '<table class="table table-condensed table-bordered"><thead><tr><th>任务#</th><th>处理人</th><th>类型</th><th>状态</th><th>动作</th><th>到达</th><th>处理时间</th><th>意见</th></tr></thead><tbody>';
                if (!(node.tasks || []).length) {
                    traceHtml += '<tr><td colspan="8" class="text-muted text-center">节点未开始</td></tr>';
                }
                $.each(node.tasks || [], function (_, nodeTask) {
                    var color = statusCustom[nodeTask.status] || 'default';
                    traceHtml += '<tr><td>' + escapeHtml(nodeTask.id) + '</td>' +
                        '<td>' + escapeHtml(nodeTask.assignee_name || ('#' + nodeTask.assignee_id)) + '</td>' +
                        '<td>' + (parseInt(nodeTask.is_required, 10) === 1 ? '会签（加签）' : '候选（或签）') + '</td>' +
                        '<td><span class="label label-' + color + '">' + escapeHtml(nodeTask.status) + '</span></td>' +
                        '<td>' + escapeHtml(nodeTask.action || '—') + '</td>' +
                        '<td>' + (nodeTask.arrived_at ? moment.unix(nodeTask.arrived_at).format('MM-DD HH:mm') : '—') + '</td>' +
                        '<td>' + (nodeTask.acted_at ? moment.unix(nodeTask.acted_at).format('MM-DD HH:mm') : '—') + '</td>' +
                        '<td>' + escapeHtml(nodeTask.comment || '') + '</td></tr>';
                });
                traceHtml += '</tbody></table>';
            });
            $('#cpq-apd-trace').html(traceHtml || '<span class="text-muted">无轨迹</span>');
        }
    };
    return Controller;
});
