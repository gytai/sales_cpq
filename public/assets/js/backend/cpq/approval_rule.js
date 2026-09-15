define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);

    // 显式候选人：selectpage 多选（逗号分隔）↔ 服务端 JSON 数组
    function bindCandidatesField(form) {
        var hidden = $('#cpq-rule-candidates-json', form);
        var picker = $('#cpq-rule-candidates', form);
        // 编辑回显：JSON 数组 → 逗号分隔
        try {
            var initial = JSON.parse(hidden.val() || '[]');
            if ($.isArray(initial) && initial.length) {
                picker.val(initial.join(','));
            }
        } catch (e) { /* 非法初值忽略，服务端会校验 */ }
        // 提交前：逗号分隔 → JSON 数组（先于 Form 提交处理执行）
        form.on('submit.cpqCandidates', function () {
            var ids = $.map(String(picker.val() || '').split(','), function (id) {
                return parseInt($.trim(id), 10) || null;
            });
            ids = $.grep(ids, function (id) { return id !== null; });
            hidden.val(JSON.stringify(ids));
        });
    }

    var Controller = {
        // P74 审批规则：固定路径候选人/SLA 维护 + 规则模拟
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/approval_rule/index' + location.search,
                add_url: 'cpq/approval_rule/add',
                edit_url: 'cpq/approval_rule/edit',
                del_url: 'cpq/approval_rule/del',
                multi_url: 'cpq/approval_rule/multi',
                table: 'cpq_approval_rule'
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['edit', 'del', 'toggle']);

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '规则编码', operate: 'LIKE'},
                    {field: 'name', title: '规则名称', operate: 'LIKE'},
                    {field: 'node', title: '审批节点', searchList: Config.nodeList, formatter: function (value) {
                        return escapeHtml((Config.nodeList && Config.nodeList[value]) || value);
                    }},
                    {field: 'approver_role', title: '审批角色', operate: false, formatter: function (value) {
                        return value ? escapeHtml(value) : '<span class="text-muted">仅显式候选人</span>';
                    }},
                    {field: 'product_line', title: '产品线', operate: 'LIKE', formatter: function (value) {
                        return value ? escapeHtml(value) : '<span class="text-muted">全部（兜底）</span>';
                    }},
                    {field: 'candidate_admin_ids', title: '显式候选人', operate: false, formatter: function (value) {
                        if (!value) {
                            return '<span class="text-muted">—</span>';
                        }
                        try {
                            var ids = JSON.parse(value);
                            return '<span class="text-amount">' + escapeHtml($.isArray(ids) ? ids.join('、') : String(value)) + '</span>';
                        } catch (e) {
                            return escapeHtml(String(value));
                        }
                    }},
                    {field: 'sla_hours', title: 'SLA(小时)', operate: false},
                    {field: 'version', title: '版本', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, custom: statusCustom, formatter: Table.api.formatter.status},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: $.extend({}, Table.api.events.operate, {
                            'click .btn-cpq-toggle': function (e, value, row) {
                                e.stopPropagation();
                                e.preventDefault();
                                var next = row.status === 'enabled' ? '停用' : '启用';
                                Layer.confirm('确认' + next + '规则 ' + escapeHtml(row.code) + '？' +
                                    (row.status !== 'enabled' ? '启用前服务端会校验候选人可解析。' : '停用后该节点将回退到其他规则。'),
                                    {icon: 3, title: next + '确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/approval_rule/toggle', type: 'POST', data: {ids: row.id}}, function () {
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '操作失败'), {icon: 2, title: '操作被拒绝'});
                                        return false;
                                    });
                                });
                            }
                        }),
                        formatter: function (value, row) {
                            // 自定义渲染：启停按钮文案随状态变化；编辑/删除复用默认事件
                            var html = '';
                            if (auth.edit && row.status === 'disabled') {
                                html += '<a href="javascript:;" class="btn btn-xs btn-success btn-editone" title="编辑（启用中不可编辑）"><i class="fa fa-pencil"></i></a> ';
                            }
                            if (auth.toggle) {
                                var enabling = row.status !== 'enabled';
                                html += '<a href="javascript:;" class="btn btn-xs ' + (enabling ? 'btn-primary' : 'btn-warning') + ' btn-cpq-toggle" title="' +
                                    (enabling ? '启用' : '停用') + '"><i class="fa ' + (enabling ? 'fa-play' : 'fa-pause') + '"></i> ' +
                                    (enabling ? '启用' : '停用') + '</a> ';
                            }
                            if (auth.del && row.status === 'disabled') {
                                html += '<a href="javascript:;" class="btn btn-xs btn-danger btn-delone" title="删除（仅停用可删）"><i class="fa fa-trash"></i></a>';
                            }
                            return html;
                        }
                    }
                ]]
            });
            Table.api.bindevent(table);

            // 规则模拟：给定审批等级与产品线，解析固定路径候选人与 SLA
            $('#toolbar').on('click', '.btn-cpq-simulate', function () {
                var lineOptions = '<option value="">（空=兜底规则）</option>';
                $.each(Config.productLineList || [], function (_, line) {
                    lineOptions += '<option value="' + escapeHtml(line) + '">' + escapeHtml(line) + '</option>';
                });
                Layer.open({
                    type: 1,
                    title: '审批规则模拟（只读试算，不影响数据）',
                    area: ['640px', '520px'],
                    content: '<div style="padding:18px">' +
                        '<div class="form-group"><label>审批等级</label>' +
                        '<select id="cpq-sim-level" class="form-control">' +
                        '<option value="line">需产线审批（产线价格审批）</option>' +
                        '<option value="company">需公司审批（产线 → 公司价格审批）</option>' +
                        '<option value="none">正常价格（销售确认）</option></select></div>' +
                        '<div class="form-group"><label>产品线</label><select id="cpq-sim-line" class="form-control">' + lineOptions + '</select></div>' +
                        '<div id="cpq-sim-result" style="max-height:280px;overflow:auto"></div></div>',
                    btn: ['运行模拟', '关闭'],
                    yes: function () {
                        Fast.api.ajax({
                            url: 'cpq/approval_rule/simulate',
                            type: 'POST',
                            data: {level: $('#cpq-sim-level').val(), product_line: $('#cpq-sim-line').val()}
                        }, function (data, ret) {
                            var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
                            var html = '<table class="table table-condensed table-bordered"><thead>' +
                                '<tr><th>节点</th><th>命中规则</th><th>SLA(小时)</th><th>候选人</th></tr></thead><tbody>';
                            $.each((payload && payload.nodes) || [], function (_, node) {
                                var candidates = '';
                                if (node.error) {
                                    candidates = '<span class="text-danger">' + escapeHtml(node.error) + '</span>';
                                } else if (node.candidates && node.candidates.length) {
                                    candidates = escapeHtml($.map(node.candidates, function (c) { return String(c); }).join('、'));
                                } else {
                                    candidates = '<span class="text-muted">无</span>';
                                }
                                html += '<tr><td>' + escapeHtml(node.node_text || node.node) + '</td>' +
                                    '<td>' + escapeHtml(node.rule_code || '—') + '</td>' +
                                    '<td>' + escapeHtml(node.sla_hours) + '</td>' +
                                    '<td>' + candidates + '</td></tr>';
                            });
                            html += '</tbody></table>';
                            $('#cpq-sim-result').html(html);
                            return false;
                        }, function (data, ret) {
                            $('#cpq-sim-result').html('<p class="text-danger">' + escapeHtml(ret && ret.msg ? ret.msg : '模拟失败') + '</p>');
                            return false;
                        });
                        return false;
                    }
                });
            });
        },

        add: function () {
            var form = $('form[role=form]');
            bindCandidatesField(form);
            Controller.api.bindevent(form);
        },

        edit: function () {
            var form = $('form[role=form]');
            bindCandidatesField(form);
            Controller.api.bindevent(form);
        },

        api: {
            bindevent: function (form) {
                Form.api.bindevent(form);
            }
        }
    };
    return Controller;
});
