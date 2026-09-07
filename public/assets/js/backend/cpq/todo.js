define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, moment, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);

    // 待办列表操作统一出口：失败弹窗展示服务端业务错误
    function postForm(url, data, onSuccess) {
        Fast.api.ajax({url: url, type: 'POST', data: data}, function (data, ret) {
            Toastr.success(ret && ret.msg ? ret.msg : '操作成功');
            if (typeof onSuccess === 'function') {
                onSuccess();
            }
            return false;
        }, function (data, ret) {
            Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '操作失败'), {icon: 2, title: '操作被拒绝'});
            return false;
        });
    }

    // 转交对话框：候选人取自服务端（职责分离与产品线范围已过滤）
    function transferDialog(taskIds, onDone) {
        Fast.api.ajax({url: 'cpq/approval_task/candidates', type: 'GET', data: {task_id: taskIds[0]}}, function (data, ret) {
            var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
            var candidates = (payload && payload.candidates) || [];
            if (!candidates.length) {
                Toastr.error('该报价产品线内暂无可用转交候选人');
                return false;
            }
            var options = '';
            $.each(candidates, function (_, item) {
                options += '<option value="' + item.id + '">' + escapeHtml(item.nickname + '（' + item.username + '）') + '</option>';
            });
            Layer.open({
                type: 1,
                title: '转交任务（' + taskIds.length + ' 条，服务端逐条重复校验）',
                area: ['440px', '300px'],
                content: '<div style="padding:18px"><div class="form-group"><label>转交给</label>' +
                    '<select id="cpq-transfer-target" class="form-control">' + options + '</select></div>' +
                    '<div class="form-group"><label>转交说明</label>' +
                    '<textarea id="cpq-transfer-comment" class="form-control" rows="3" placeholder="转交原因/补充说明"></textarea></div></div>',
                btn: ['确认转交', '取消'],
                yes: function (index) {
                    Layer.close(index);
                    onDone(parseInt($('#cpq-transfer-target').val(), 10), $.trim($('#cpq-transfer-comment').val()));
                }
            });
            return false;
        }, function (data, ret) {
            Toastr.error(ret && ret.msg ? ret.msg : '候选人加载失败');
            return false;
        });
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/todo/index' + location.search
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['detail', 'batchtransfer', 'urge', 'flow', 'withdraw']);
            var state = {category: 'pending'};

            function quoteCell(row) {
                return '<strong>' + escapeHtml(row.quote_code) + '</strong>' +
                    (row.quote_name ? '<br><small class="text-muted">' + escapeHtml(row.quote_name) + '</small>' : '');
            }

            function taskOperateButtons() {
                var buttons = [];
                if (auth.detail) {
                    buttons.push({
                        name: 'handle', text: '处理', title: '审批详情', icon: 'fa fa-list',
                        classname: 'btn btn-xs btn-info btn-dialog',
                        url: 'cpq/approval_task/detail/ids/{id}',
                        extend: 'data-toggle="tooltip" data-container="body" data-area=\'["90%","92%"]\''
                    });
                }
                buttons.push({
                    name: 'urge', text: '催办', icon: 'fa fa-bell',
                    classname: 'btn btn-xs btn-warning btn-cpq-urge',
                    visible: function (row) {
                        return auth.urge && row.status === 'pending';
                    }
                });
                buttons.push({
                    name: 'transfer', text: '转交', icon: 'fa fa-share',
                    classname: 'btn btn-xs btn-default btn-cpq-transfer',
                    visible: function (row) {
                        return auth.batchtransfer && row.status === 'pending';
                    }
                });
                return buttons;
            }

            function taskColumns(category) {
                var columns = [
                    {checkbox: true},
                    {field: 'business_type', title: '业务类型', operate: false, formatter: function () { return '<span class="label label-info">报价审批</span>'; }},
                    {field: 'quote_code', title: '单号', operate: false, formatter: function (value, row) { return quoteCell(row); }},
                    {field: 'customer_name', title: '客户', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'product_line', title: '产品线', operate: false},
                    {field: 'node_text', title: '节点', operate: false},
                    {field: 'initiator_name', title: '提交人', operate: false},
                    {field: 'total_amount', title: '金额', operate: false, align: 'right', formatter: CpqCommon.moneyFormatter},
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
                if (category === 'processed' || category === 'cc') {
                    columns.push({field: 'status', title: '状态', operate: false, formatter: Table.api.formatter.status, custom: statusCustom});
                }
                if (category === 'cc') {
                    columns.push({field: 'cc_source', title: '抄送来源', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }});
                }
                columns.push({
                    field: 'operate', title: __('Operate'), table: table,
                    events: {
                        'click .btn-cpq-urge': function (e, value, row) {
                            e.stopPropagation();
                            Layer.confirm('确认催办任务 #' + row.id + '？将记录一条催办动作。', {icon: 3, title: '催办确认'}, function (index) {
                                Layer.close(index);
                                postForm('cpq/todo/urge', {task_id: row.id}, function () {
                                    Toastr.success('已发送催办提醒');
                                });
                            });
                        },
                        'click .btn-cpq-transfer': function (e, value, row) {
                            e.stopPropagation();
                            transferDialog([row.id], function (targetId, comment) {
                                postForm('cpq/todo/batchtransfer', {task_ids: [row.id], next_assignee_id: targetId, comment: comment}, function () {
                                    Toastr.success('已转交');
                                    table.bootstrapTable('refresh');
                                });
                            });
                        }
                    },
                    buttons: taskOperateButtons(),
                    formatter: Table.api.formatter.operate
                });
                return columns;
            }

            function instanceColumns() {
                return [
                    {checkbox: false},
                    {field: 'quote_code', title: '单号', operate: false, formatter: function (value, row) { return quoteCell(row); }},
                    {field: 'customer_name', title: '客户', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'product_line', title: '产品线', operate: false},
                    {field: 'status', title: '状态', operate: false, formatter: function (value, row) {
                        var color = statusCustom[value] || 'default';
                        return '<span class="label label-' + color + '">' + escapeHtml(row.status_text || value) + '</span>';
                    }},
                    {field: 'node_text', title: '当前节点', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'current_handlers', title: '当前处理人', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'dwell_seconds', title: '停留时间', operate: false, formatter: function (value, row) {
                        if (parseInt(row.overdue, 10) === 1) {
                            return '<span class="label label-danger">已超时</span> ' + CpqCommon.durationText(value);
                        }
                        return value === null ? '<span class="text-muted">—</span>' : CpqCommon.durationText(value);
                    }},
                    {field: 'urge_count', title: '催办次数', operate: false},
                    {field: 'submitted_at', title: '提交时间', operate: false, formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-urge': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认催办当前节点处理人？', {icon: 3, title: '催办确认'}, function (index) {
                                    Layer.close(index);
                                    postForm('cpq/todo/urge', {task_id: row.current_task_id}, function () {
                                        Toastr.success('已发送催办提醒');
                                        table.bootstrapTable('refresh');
                                    });
                                });
                            },
                            'click .btn-cpq-withdraw': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认撤回报价 ' + escapeHtml(row.quote_code) + ' 的审批？撤回后报价回到可编辑状态。', {icon: 3, title: '撤回确认'}, function (index) {
                                    Layer.close(index);
                                    postForm('cpq/approval_instance/withdraw', {quote_id: row.quote_id}, function () {
                                        Toastr.success('已撤回');
                                        table.bootstrapTable('refresh');
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
                ];
            }

            function queryParams(params) {
                // 后端按 page/limit 分页：将 bootstrap-table 的 offset/limit 换算为 page
                params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                params.category = state.category;
                params.node = $('#cpq-todo-node').val() || '';
                params.product_line = $('#cpq-todo-line').val() || '';
                params.keyword = $.trim($('#cpq-todo-keyword').val() || '');
                return params;
            }

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: queryParams,
                columns: [taskColumns('pending')]
            });
            Table.api.bindevent(table);

            // 分类切换：任务类与「我发起的」列结构不同，整列替换后刷新
            $('#cpq-todo-tabs').on('click', 'button', function () {
                $('#cpq-todo-tabs button').removeClass('active');
                $(this).addClass('active');
                state.category = $(this).data('category');
                var columns = state.category === 'initiated' ? instanceColumns() : taskColumns(state.category);
                table.bootstrapTable('refreshOptions', {columns: [columns], pk: 'id', sortName: 'id'});
                table.bootstrapTable('refresh');
            });

            // 筛选：节点/产品线/关键字（关键字回车触发）
            $('#cpq-todo-node, #cpq-todo-line').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-todo-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });

            // 批量转交（仅待处理分类可用）
            $('#toolbar').on('click', '.btn-cpq-batch-transfer', function () {
                if (!auth.batchtransfer) {
                    Toastr.error('无批量转交权限');
                    return;
                }
                if (state.category !== 'pending') {
                    Toastr.error('仅「待处理」分类支持批量转交');
                    return;
                }
                var selections = table.bootstrapTable('getSelections');
                if (!selections.length) {
                    Toastr.error('请先勾选要转交的任务');
                    return;
                }
                var ids = $.map(selections, function (row) { return row.id; });
                transferDialog(ids, function (targetId, comment) {
                    postForm('cpq/todo/batchtransfer', {task_ids: ids, next_assignee_id: targetId, comment: comment}, function () {
                        table.trigger('uncheckbox');
                        table.bootstrapTable('refresh');
                    });
                });
            });
        }
    };
    return Controller;
});
