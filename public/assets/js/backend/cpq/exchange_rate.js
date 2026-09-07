define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/exchange_rate/index' + location.search,
                add_url: 'cpq/exchange_rate/add',
                edit_url: 'cpq/exchange_rate/edit',
                del_url: 'cpq/exchange_rate/del',
                multi_url: 'cpq/exchange_rate/multi',
                table: 'cpq_exchange_rate'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'source_currency', title: '源币种', operate: 'LIKE'},
                    {field: 'target_currency', title: '目标币种', operate: 'LIKE'},
                    {field: 'rate', title: '汇率', operate: false, formatter: CpqCommon.moneyFormatter},
                    {field: 'source', title: '来源'},
                    {field: 'effective_date', title: '生效日期', operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'expiry_date', title: '失效日期', operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
            CpqCommon.importPreviewToolbar('#toolbar', 'cpq/exchange_rate', CpqCommon.readAuth(table, ['importpreview']));
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
