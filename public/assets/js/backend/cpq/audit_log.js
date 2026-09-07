/**
 * CPQ 审计日志（P106，GYTAI-78）
 * 只读查询页：多条件参数化筛选 + 单条详情弹层，不提供任何写操作。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var actionText = {
        create: '创建', update: '更新', delete: '删除', submit: '提交', publish: '发布',
        expire: '过期', copy: '复制', toggle_status: '启停',
        grant_product_line: '授产品线', revoke_product_line: '撤产品线',
        grant_org: '授组织', revoke_org: '撤组织',
        reset_credential: '重置凭证', download_error_report: '下载错误报告'
    };

    function showDetail(row) {
        Fast.api.ajax({url: 'cpq/audit_log/detail', type: 'GET', data: {ids: row.id}}, function (data, ret) {
            var log = (ret && ret.data) || row;
            var detail = '';
            if (log.detail_json) {
                var parsed;
                try {
                    parsed = $.parseJSON(log.detail_json);
                } catch (e) {
                    parsed = null;
                }
                detail = '<pre style="max-height:220px;overflow:auto;white-space:pre-wrap;word-break:break-all;background:#f7f7f9;border-radius:3px;padding:8px">' +
                    escapeHtml(parsed ? JSON.stringify(parsed, null, 2) : String(log.detail_json)) + '</pre>';
            } else {
                detail = '<p class="text-muted">（无变更明细）</p>';
            }
            var html = '<table class="table table-condensed table-bordered">' +
                '<tr><th style="width:100px">日志ID</th><td>' + escapeHtml(log.id) + '</td></tr>' +
                '<tr><th>追踪号</th><td><code>' + escapeHtml(log.trace_id || '—') + '</code></td></tr>' +
                '<tr><th>操作人</th><td>' + escapeHtml((log.username || '—') + '（ID ' + log.user_id + '）') + '</td></tr>' +
                '<tr><th>动作</th><td>' + escapeHtml((actionText[log.action] || log.action) + '（' + log.action + '）') + '</td></tr>' +
                '<tr><th>对象</th><td>' + escapeHtml(log.object_type + ' #' + log.object_id + (log.object_code ? '（' + log.object_code + '）' : '')) + '</td></tr>' +
                '<tr><th>来源IP</th><td>' + escapeHtml(log.ip || '—') + '</td></tr>' +
                '<tr><th>时间</th><td>' + escapeHtml(log.createtime ? new Date(parseInt(log.createtime, 10) * 1000).toLocaleString() : '—') + '</td></tr>' +
                '</table>' +
                '<p class="text-muted" style="margin:10px 0 4px">变更明细：</p>' + detail;
            Layer.open({
                type: 1,
                title: '审计日志详情',
                area: ['720px', '560px'],
                content: '<div style="padding:15px;max-height:520px;overflow:auto">' + html + '</div>',
                btn: ['关闭']
            });
            return false;
        }, function (data, ret) {
            Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '详情加载失败'), {icon: 2, title: '加载失败'});
            return false;
        });
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/audit_log/index' + location.search}});
            var table = $('#table');
            var canDetail = !!$('#table').data('auth-detail');

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    // 后端按 page/limit 分页：将 bootstrap-table 的 offset/limit 换算为 page
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.action = $.trim($('#cpq-audit-action').val() || '');
                    params.object_type = $.trim($('#cpq-audit-object-type').val() || '');
                    params.object_id = parseInt($('#cpq-audit-object-id').val(), 10) || 0;
                    params.user_id = parseInt($('#cpq-audit-user-id').val(), 10) || 0;
                    params.created_from = $.trim($('#cpq-audit-from').val() || '');
                    params.created_to = $.trim($('#cpq-audit-to').val() || '');
                    return params;
                },
                columns: [[
                    {field: 'id', title: 'ID', width: 70},
                    {field: 'trace_id', title: '追踪号', formatter: function (value) { return '<code>' + escapeHtml(value || '—') + '</code>'; }},
                    {field: 'username', title: '操作人', formatter: function (value, row) {
                        return escapeHtml((value || '—') + '（ID ' + row.user_id + '）');
                    }},
                    {field: 'action', title: '动作', formatter: function (value) {
                        return escapeHtml((actionText[value] || value) + '（' + value + '）');
                    }},
                    {field: 'object_type', title: '对象表', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'object_id', title: '对象ID', width: 80},
                    {field: 'object_code', title: '对象编码', formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'ip', title: '来源IP', formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'createtime', title: '时间', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table, width: 80,
                        events: {
                            'click .btn-cpq-detail': function (e, value, row) {
                                e.stopPropagation();
                                showDetail(row);
                            }
                        },
                        buttons: [
                            {
                                name: 'detail', text: '详情', title: '查看明细', icon: 'fa fa-search',
                                classname: 'btn btn-xs btn-info btn-cpq-detail',
                                visible: function () { return canDetail; }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-audit-action').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
            $('#cpq-audit-object-type').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
            $('#cpq-audit-object-id').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
            $('#cpq-audit-user-id').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
            $('#cpq-audit-from').on('changeDate', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-audit-to').on('changeDate', function () {
                table.bootstrapTable('refresh');
            });
        }
    };
    return Controller;
});
