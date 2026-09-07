define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, moment, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);
    var levelTexts = {none: '无需审批', line: '产线审批', company: '公司审批'};
    var levelCustom = {none: 'success', line: 'warning', company: 'danger'};

    var Controller = {
        // P72 我发起的审批
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/approval_instance/index' + location.search
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['flow', 'withdraw', 'urge']);

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.instance_status = $('#cpq-instance-status').val() || '';
                    params.keyword = $.trim($('#cpq-instance-keyword').val() || '');
                    return params;
                },
                columns: [[
                    {checkbox: false},
                    {field: 'quote_code', title: '业务单号', operate: false, formatter: function (value, row) {
                        return '<strong>' + escapeHtml(value) + '</strong>' +
                            (row.quote_name ? '<br><small class="text-muted">' + escapeHtml(row.quote_name) + '</small>' : '');
                    }},
                    {field: 'customer_name', title: '客户', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'product_line', title: '产品线', operate: false},
                    {field: 'approval_level', title: '审批等级', operate: false, formatter: function (value) {
                        return '<span class="label label-' + (levelCustom[value] || 'default') + '">' + (levelTexts[value] || value || '—') + '</span>';
                    }},
                    {field: 'status', title: '状态', searchList: Config.statusList, operate: false, formatter: function (value, row) {
                        return '<span class="label label-' + (statusCustom[value] || 'default') + '">' + escapeHtml(row.status_text || value) + '</span>';
                    }},
                    {field: 'node_text', title: '当前节点', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'current_handlers', title: '当前处理人', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'dwell_seconds', title: '停留时间', operate: false, formatter: function (value, row) {
                        if (parseInt(row.overdue, 10) === 1) {
                            return '<span class="label label-danger">SLA 已超时</span> ' + CpqCommon.durationText(value);
                        }
                        return value === null ? '<span class="text-muted">—</span>' : CpqCommon.durationText(value);
                    }},
                    {field: 'urge_count', title: '催办', operate: false, formatter: function (value) {
                        return parseInt(value, 10) > 0 ? '<span class="label label-warning">' + value + ' 次</span>' : '<span class="text-muted">0</span>';
                    }},
                    {field: 'submitted_at', title: '提交时间', operate: false, formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-urge': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认催办当前节点处理人？', {icon: 3, title: '催办确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/approval_task/urge', type: 'POST', data: {task_id: row.current_task_id}}, function () {
                                        Toastr.success('已发送催办提醒');
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '催办失败'), {icon: 2, title: '操作被拒绝'});
                                        return false;
                                    });
                                });
                            },
                            'click .btn-cpq-withdraw': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认撤回报价 ' + escapeHtml(row.quote_code) + ' 的审批？撤回后报价回到可编辑状态。', {icon: 3, title: '撤回确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/approval_instance/withdraw', type: 'POST', data: {quote_id: row.quote_id}}, function () {
                                        Toastr.success('已撤回');
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '撤回失败'), {icon: 2, title: '操作被拒绝'});
                                        return false;
                                    });
                                });
                            }
                        },
                        buttons: [
                            {
                                name: 'flow', text: '流程', title: '完整流程', icon: 'fa fa-sitemap',
                                classname: 'btn btn-xs btn-info btn-dialog',
                                url: 'cpq/approval_instance/flow/ids/{id}',
                                extend: 'data-toggle="tooltip" data-container="body" data-area=\'["80%","88%"]\'',
                                visible: function () { return auth.flow; }
                            },
                            {
                                name: 'urge', text: '催办', icon: 'fa fa-bell',
                                classname: 'btn btn-xs btn-warning btn-cpq-urge',
                                visible: function (row) {
                                    return auth.urge && row.status === 'active' && parseInt(row.current_task_id, 10) > 0;
                                }
                            },
                            {
                                name: 'withdraw', text: '撤回', icon: 'fa fa-undo',
                                classname: 'btn btn-xs btn-danger btn-cpq-withdraw',
                                visible: function (row) {
                                    return auth.withdraw && row.status === 'active';
                                }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-instance-status').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-instance-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
        },

        // 完整流程视图：节点任务 + 动作时间线
        flow: function () {
            var flow = window.__CPQ_FLOW__ || {};
            var instance = flow.instance || {};
            var quote = flow.quote || {};

            var summary = '<dl class="dl-horizontal" style="margin-bottom:0">';
            summary += '<dt>报价</dt><dd><strong>' + escapeHtml(quote.code) + '</strong>　' + escapeHtml(quote.name || '') + '</dd>';
            summary += '<dt>审批等级</dt><dd>' + (levelTexts[instance.approval_level] || instance.approval_level || '—') + '</dd>';
            summary += '<dt>流程状态</dt><dd><span class="label label-' + (statusCustom[instance.status] || 'default') + '">' +
                escapeHtml(flow.instance_status_text || instance.status) + '</span></dd>';
            summary += '<dt>提交时间</dt><dd>' + (instance.submitted_at ? moment.unix(instance.submitted_at).format('YYYY-MM-DD HH:mm:ss') : '—') + '</dd>';
            summary += '<dt>完成时间</dt><dd>' + (instance.completed_at ? moment.unix(instance.completed_at).format('YYYY-MM-DD HH:mm:ss') : '—') + '</dd>';
            summary += '</dl>';
            $('#cpq-flow-summary').html(summary);

            var nodesHtml = '';
            $.each(flow.nodes || [], function (_, node) {
                var isCurrent = node.node === instance.current_node && instance.status === 'active';
                nodesHtml += '<div class="panel ' + (isCurrent ? 'panel-primary' : 'panel-default') + ' node-panel">' +
                    '<div class="panel-heading">' + escapeHtml(node.node_text || node.node) +
                    (isCurrent ? ' <span class="label label-warning">当前节点</span>' : '') + '</div>' +
                    '<div class="panel-body">';
                if (!(node.tasks || []).length) {
                    nodesHtml += '<span class="text-muted">节点未开始</span>';
                }
                $.each(node.tasks || [], function (_, task) {
                    var color = statusCustom[task.status] || 'default';
                    nodesHtml += '<div class="task-chip">#' + escapeHtml(task.id) + ' ' + escapeHtml(task.assignee_name || ('#' + task.assignee_id)) + ' ' +
                        '<span class="label label-' + color + '">' + escapeHtml(task.status_text || task.status) + '</span>' +
                        (parseInt(task.is_required, 10) === 1 ? ' <span class="label label-primary">会签</span>' : '') +
                        '<br><small class="text-muted">到达 ' + (task.arrived_at ? moment.unix(task.arrived_at).format('MM-DD HH:mm') : '—') +
                        '　处理 ' + (task.acted_at ? moment.unix(task.acted_at).format('MM-DD HH:mm') : '—') + '</small>' +
                        (task.comment ? '<br><small>' + escapeHtml(task.comment) + '</small>' : '') +
                        '</div>';
                });
                nodesHtml += '</div></div>';
            });
            $('#cpq-flow-nodes').html(nodesHtml || '<span class="text-muted">无节点信息</span>');

            var actionsHtml = '';
            $.each(flow.actions || [], function (_, action) {
                var color = CpqCommon.actionCustom[action.action] || 'default';
                actionsHtml += '<div class="timeline-item">' +
                    '<span class="label label-' + color + '">' + escapeHtml(action.action_text || action.action) + '</span> ' +
                    '<strong>' + escapeHtml(action.actor_name || ('#' + action.actor_id)) + '</strong>' +
                    (parseInt(action.delegate_from_id, 10) > 0 ? ' <span class="label label-primary">代理处理</span>' : '') +
                    (action.node_text ? ' <small class="text-muted">@ ' + escapeHtml(action.node_text) + '</small>' : '') +
                    '<br><small class="text-muted">' + (action.createtime ? moment.unix(action.createtime).format('YYYY-MM-DD HH:mm:ss') : '') +
                    (action.reason_category ? '　原因：' + escapeHtml(action.reason_category) : '') + '</small>' +
                    (action.comment ? '<div>' + escapeHtml(action.comment) + '</div>' : '') +
                    '</div>';
            });
            $('#cpq-flow-actions').html(actionsHtml || '<span class="text-muted">暂无动作</span>');
        }
    };
    return Controller;
});
