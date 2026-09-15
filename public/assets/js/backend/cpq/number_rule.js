/**
 * CPQ 编号规则管理（P102，GYTAI-78）
 * 规则列表 + 新增/编辑 dialog + 格式试算 + 计数器查看。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var periodText = {none: '不重置', year: '按年', month: '按月', day: '按日'};

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/number_rule/index' + location.search,
                add_url: 'cpq/number_rule/add',
                edit_url: 'cpq/number_rule/edit'
            }});
            var table = $('#table');
            var canWrite = !!Config.canWrite;

            if (canWrite) {
                $('#toolbar').prepend('<a href="javascript:;" class="btn btn-success btn-cpq-add"><i class="fa fa-plus"></i> 新增规则</a>');
            }
            $('#toolbar').append('<a href="javascript:;" class="btn btn-info btn-cpq-preview" style="margin-left:8px"><i class="fa fa-eye"></i> 格式试算</a>');

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'asc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    // 后端按 page/limit 分页：将 bootstrap-table 的 offset/limit 换算为 page
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.status = $('#cpq-rule-status').val() || '';
                    params.keyword = $.trim($('#cpq-rule-keyword').val() || '');
                    return params;
                },
                columns: [[
                    {field: 'id', title: 'ID', width: 60},
                    {field: 'code', title: '规则编码', formatter: function (value) { return '<strong>' + escapeHtml(value) + '</strong>'; }},
                    {field: 'name', title: '规则名称', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'pattern', title: '编号格式', formatter: function (value) { return '<code>' + escapeHtml(value) + '</code>'; }},
                    {field: 'period_type', title: '周期', formatter: function (value) { return escapeHtml(periodText[value] || value); }},
                    {field: 'initial_value', title: '起始序号', width: 80},
                    {field: 'counters_count', title: '计数器', width: 70},
                    {field: 'max_current_value', title: '当前最大序号', width: 100},
                    {field: 'status', title: '状态', formatter: function (value) {
                        var color = CpqCommon.statusCustom[value] || 'default';
                        return '<span class="label label-' + color + '">' + escapeHtml(Config.statusList[value] || value) + '</span>';
                    }},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-counters': function (e, value, row) {
                                e.stopPropagation();
                                Controller.showCounters(row);
                            }
                        },
                        buttons: [
                            {
                                name: 'counters', text: '计数器', title: '查看计数器', icon: 'fa fa-list-ol',
                                classname: 'btn btn-xs btn-info btn-cpq-counters'
                            },
                            {
                                name: 'edit', text: '编辑', title: '编辑', icon: 'fa fa-pencil',
                                classname: 'btn btn-xs btn-success btn-dialog',
                                url: 'cpq/number_rule/edit/ids/{id}',
                                extend: 'data-toggle="tooltip" data-container="body" data-area=\'["560px","520px"]\'',
                                visible: function () { return canWrite; }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#toolbar').on('click', '.btn-cpq-add', function () {
                Fast.api.open('cpq/number_rule/add', '新增编号规则', {area: ['560px', '520px'], callback: function () {
                    table.bootstrapTable('refresh');
                }});
            });
            $('#toolbar').on('click', '.btn-cpq-preview', function () {
                Layer.open({
                    type: 1,
                    title: '编号格式试算（以序号 123 渲染，不产生真实编号）',
                    area: ['520px', '300px'],
                    content: '<div style="padding:18px">' +
                        '<div class="form-group"><label>编号格式</label><input type="text" id="cpq-preview-pattern" class="form-control" value="{YYYY}{SEQ6}"></div>' +
                        '<div class="form-group"><label>范围（可选，对应 {SCOPE}）</label><input type="text" id="cpq-preview-scope" class="form-control" placeholder="如 EAST"></div>' +
                        '<div id="cpq-preview-result" style="font-size:16px"></div></div>',
                    btn: ['试算', '关闭'],
                    yes: function () {
                        Fast.api.ajax({
                            url: 'cpq/number_rule/preview',
                            type: 'POST',
                            data: {
                                pattern: $.trim($('#cpq-preview-pattern').val()),
                                scope: $.trim($('#cpq-preview-scope').val())
                            }
                        }, function (data, ret) {
                            var preview = ret && ret.data && ret.data.preview ? ret.data.preview : '';
                            $('#cpq-preview-result').html('试算结果：<code>' + escapeHtml(preview) + '</code>');
                            return false;
                        }, function (data, ret) {
                            Toastr.error(ret && ret.msg ? ret.msg : '试算失败');
                            return false;
                        });
                    }
                });
            });
            $('#cpq-rule-status').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-rule-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () {
            // 已产生计数器的规则修改格式时服务端返回 need_confirm：二次确认后带 force 重提
            Form.api.bindevent($('form[role=form]'), null, function (data, ret) {
                if (ret && ret.data && ret.data.need_confirm) {
                    Layer.confirm(escapeHtml(ret.msg || '该规则已产生编号计数器，确认修改编号格式？'), {icon: 3, title: '二次确认'}, function (index) {
                        Layer.close(index);
                        $('input[name="row[force]"]').val('1');
                        $('form[role=form]').submit();
                    });
                    return false;
                }
                Toastr.error(ret && ret.msg ? ret.msg : '保存失败');
                return false;
            });
        },
        showCounters: function (rule) {
            Fast.api.ajax({url: 'cpq/number_rule/counters', type: 'GET', data: {rule_id: rule.id}}, function (data, ret) {
                var rows = (ret && ret.data && ret.data.rows) || [];
                var html = '<table class="table table-condensed table-bordered"><thead><tr><th>范围(scope)</th><th>周期(period)</th><th>当前序号</th><th>更新时间</th></tr></thead><tbody>';
                if (!rows.length) {
                    html += '<tr><td colspan="4" class="text-muted">暂无计数器（尚未产生编号）</td></tr>';
                }
                $.each(rows, function (_, counter) {
                    html += '<tr><td>' + escapeHtml(counter.scope_key === '' ? '（全局）' : counter.scope_key) + '</td>' +
                        '<td>' + escapeHtml(counter.period_key === '' ? '（不重置）' : counter.period_key) + '</td>' +
                        '<td>' + escapeHtml(counter.current_value) + '</td>' +
                        '<td>' + escapeHtml(counter.updatetime ? new Date(counter.updatetime * 1000).toLocaleString() : '') + '</td></tr>';
                });
                html += '</tbody></table>';
                Layer.open({
                    type: 1,
                    title: '计数器：' + rule.name + '（' + rule.code + '）',
                    area: ['620px', '420px'],
                    content: '<div style="padding:15px;max-height:380px;overflow:auto">' + html + '</div>',
                    btn: ['关闭']
                });
                return false;
            }, function (data, ret) {
                Toastr.error(ret && ret.msg ? ret.msg : '计数器加载失败');
                return false;
            });
        },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
