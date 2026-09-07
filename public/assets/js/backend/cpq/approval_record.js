define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, moment, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var Controller = {
        // P73 审批记录：全量动作流水（只增不删）
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/approval_record/index' + location.search
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
                    params.action = $('#cpq-record-action').val() || '';
                    params.product_line = $('#cpq-record-line').val() || '';
                    params.createtime = $('#cpq-record-time').val() || '';
                    params.keyword = $.trim($('#cpq-record-keyword').val() || '');
                    return params;
                },
                columns: [[
                    {checkbox: false},
                    {field: 'quote_code', title: '业务单号', operate: false, formatter: function (value, row) {
                        return '<span class="label label-info">报价</span> <strong>' + escapeHtml(value) + '</strong>' +
                            (row.quote_name ? '<br><small class="text-muted">' + escapeHtml(row.quote_name) + '</small>' : '');
                    }},
                    {field: 'product_line', title: '产品线', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'node_text', title: '节点', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'action', title: '动作', operate: false, formatter: function (value, row) {
                        return CpqCommon.actionFormatter(value, row);
                    }},
                    {field: 'actor_name', title: '处理人', operate: false, formatter: function (value, row) {
                        var html = escapeHtml(value || ('#' + row.actor_id));
                        if (parseInt(row.delegate_from_id, 10) > 0) {
                            html += '<br><small><span class="label label-primary">代理</span> 委托人：' + escapeHtml(row.delegator_name || ('#' + row.delegate_from_id)) + '</small>';
                        }
                        return html;
                    }},
                    {field: 'comment', title: '意见', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'reason_category', title: '原因分类', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'hash', title: '数据哈希（前 → 后）', operate: false, formatter: function (value, row) {
                        var before = row.before_hash ? String(row.before_hash).substr(0, 8) : '—';
                        var after = row.after_hash ? String(row.after_hash).substr(0, 8) : '—';
                        return '<span class="text-amount" style="font-family:Menlo,Consolas,monospace"><small>' +
                            escapeHtml(before) + ' → ' + escapeHtml(after) + '</small></span>';
                    }},
                    {field: 'ip', title: '来源IP', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'createtime', title: '处理时间', operate: false, formatter: Table.api.formatter.datetime}
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-record-action, #cpq-record-line').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-record-time').on('apply.daterangepicker cancel.daterangepicker', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-record-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });

            // 时间范围选择器（datetimerange 惯例）
            if ($.fn.daterangepicker) {
                $('#cpq-record-time').daterangepicker({
                    autoUpdateInput: false,
                    locale: {format: 'YYYY-MM-DD', cancelLabel: '清除', applyLabel: '确定', customRangeLabel: '自定义'}
                }).on('apply.daterangepicker', function (ev, picker) {
                    $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
                }).on('cancel.daterangepicker', function () {
                    $(this).val('');
                });
            }
        }
    };
    return Controller;
});
