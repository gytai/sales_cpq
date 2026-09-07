define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, moment, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);

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

    var Controller = {
        // P75 委托与代理
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/approval_delegation/index' + location.search
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['create', 'approve', 'cancel']);
            var canApprove = !!table.data('can-approve');

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.status = $('#cpq-delegation-status').val() || '';
                    return params;
                },
                columns: [[
                    {checkbox: false},
                    {field: 'delegator_name', title: '委托人', operate: false, formatter: function (value, row) { return escapeHtml(value || ('#' + row.delegator_id)); }},
                    {field: 'delegate_name', title: '代理人', operate: false, formatter: function (value, row) { return escapeHtml(value || ('#' + row.delegate_id)); }},
                    {field: 'business_type', title: '业务类型', operate: false, formatter: function () { return '报价审批'; }},
                    {field: 'product_line', title: '产品线', operate: false, formatter: function (value) {
                        return value ? escapeHtml(value) : '<span class="text-muted">全部</span>';
                    }},
                    {field: 'starts_at', title: '开始时间', operate: false, formatter: Table.api.formatter.datetime},
                    {field: 'ends_at', title: '结束时间', operate: false, formatter: Table.api.formatter.datetime},
                    {field: 'reason', title: '原因', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'status', title: '状态', operate: false, formatter: function (value, row) {
                        var effective = row.effective_status || value;
                        var texts = {pending: '待审批', active: '生效中', cancelled: '已撤销', rejected: '已拒绝', expired: '已过期'};
                        return '<span class="label label-' + (statusCustom[effective] || 'default') + '">' + (texts[effective] || effective) + '</span>';
                    }},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-approve': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认批准该委托？批准后委托在时间窗内生效。', {icon: 3, title: '批准委托'}, function (index) {
                                    Layer.close(index);
                                    postForm('cpq/approval_delegation/approve', {ids: row.id, approve: 1}, function () {
                                        table.bootstrapTable('refresh');
                                    });
                                });
                            },
                            'click .btn-cpq-reject': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认拒绝该委托申请？', {icon: 3, title: '拒绝委托'}, function (index) {
                                    Layer.close(index);
                                    postForm('cpq/approval_delegation/approve', {ids: row.id, approve: 0}, function () {
                                        table.bootstrapTable('refresh');
                                    });
                                });
                            },
                            'click .btn-cpq-cancel': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认撤销该委托？撤销后立即失效。', {icon: 3, title: '撤销委托'}, function (index) {
                                    Layer.close(index);
                                    postForm('cpq/approval_delegation/cancel', {ids: row.id}, function () {
                                        table.bootstrapTable('refresh');
                                    });
                                });
                            }
                        },
                        buttons: [
                            {
                                name: 'approve', text: '批准', icon: 'fa fa-check',
                                classname: 'btn btn-xs btn-success btn-cpq-approve',
                                visible: function (row) {
                                    return auth.approve && canApprove && row.status === 'pending';
                                }
                            },
                            {
                                name: 'reject', text: '拒绝', icon: 'fa fa-times',
                                classname: 'btn btn-xs btn-danger btn-cpq-reject',
                                visible: function (row) {
                                    return auth.approve && canApprove && row.status === 'pending';
                                }
                            },
                            {
                                name: 'cancel', text: '撤销', icon: 'fa fa-ban',
                                classname: 'btn btn-xs btn-warning btn-cpq-cancel',
                                visible: function (row) {
                                    return auth.cancel && (row.status === 'pending' || row.status === 'active');
                                }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-delegation-status').on('change', function () {
                table.bootstrapTable('refresh');
            });

            // 新增委托申请
            $('#toolbar').on('click', '.btn-cpq-delegation-add', function () {
                if (!auth.create) {
                    Toastr.error('无新增委托权限');
                    return;
                }
                var lineOptions = '<option value="">全部产品线</option>';
                $.each(Config.productLineList || [], function (_, line) {
                    lineOptions += '<option value="' + escapeHtml(line) + '">' + escapeHtml(line) + '</option>';
                });
                Layer.open({
                    type: 1,
                    title: '新增委托申请（审批通过后生效）',
                    area: ['480px', '520px'],
                    content: '<div style="padding:18px">' +
                        '<div class="form-group"><label>代理人 <span class="text-danger">*</span></label>' +
                        '<input class="form-control selectpage" id="cpq-dlg-delegate" data-source="cpq/approval_delegation/candidates" data-field="nickname" data-primary-key="id"></div>' +
                        '<div class="form-group"><label>产品线</label><select id="cpq-dlg-line" class="form-control">' + lineOptions + '</select></div>' +
                        '<div class="form-group"><label>开始时间 <span class="text-danger">*</span></label>' +
                        '<input class="form-control datetimepicker" id="cpq-dlg-starts" data-date-format="YYYY-MM-DD HH:mm:ss"></div>' +
                        '<div class="form-group"><label>结束时间 <span class="text-danger">*</span></label>' +
                        '<input class="form-control datetimepicker" id="cpq-dlg-ends" data-date-format="YYYY-MM-DD HH:mm:ss"></div>' +
                        '<div class="form-group"><label>委托原因</label>' +
                        '<textarea id="cpq-dlg-reason" class="form-control" rows="2" placeholder="如 休假/出差期间代理审批"></textarea></div>' +
                        '<p class="text-muted">代理关系不突破代理人原有数据权限；委托人与代理人不可为同一人。</p></div>',
                    btn: ['提交申请', '取消'],
                    success: function (layero) {
                        Form.events.selectpage($(layero));
                        Form.events.datetimepicker($(layero));
                    },
                    yes: function (index) {
                        var delegateId = parseInt($('#cpq-dlg-delegate').val(), 10) || 0;
                        var startsAt = $.trim($('#cpq-dlg-starts').val());
                        var endsAt = $.trim($('#cpq-dlg-ends').val());
                        if (!delegateId) {
                            Toastr.error('请选择代理人');
                            return;
                        }
                        if (!startsAt || !endsAt) {
                            Toastr.error('请选择开始与结束时间');
                            return;
                        }
                        Layer.close(index);
                        postForm('cpq/approval_delegation/create', {
                            delegate_id: delegateId,
                            product_line: $('#cpq-dlg-line').val() || '',
                            starts_at: startsAt,
                            ends_at: endsAt,
                            reason: $.trim($('#cpq-dlg-reason').val())
                        }, function () {
                            table.bootstrapTable('refresh');
                        });
                    }
                });
            });
        },

        // P75 代理操作记录：我作为代理人处理过的动作
        actions: function () {
            Table.api.init({extend: {
                index_url: 'cpq/approval_delegation/actions' + location.search
            }});
            var table = $('#table');

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    return params;
                },
                columns: [[
                    {checkbox: false},
                    {field: 'quote_id', title: '报价ID', operate: false},
                    {field: 'node_text', title: '节点', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'action', title: '动作', operate: false, formatter: function (value, row) {
                        return CpqCommon.actionFormatter(value, row);
                    }},
                    {field: 'delegator_name', title: '委托人', operate: false, formatter: function (value, row) {
                        return '<span class="label label-primary">代理</span> ' + escapeHtml(value || ('#' + row.delegate_from_id));
                    }},
                    {field: 'comment', title: '意见', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'reason_category', title: '原因分类', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'createtime', title: '处理时间', operate: false, formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);
        }
    };
    return Controller;
});
