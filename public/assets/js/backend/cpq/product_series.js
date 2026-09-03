define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/product_series/index' + location.search,
                add_url: 'cpq/product_series/add',
                edit_url: 'cpq/product_series/edit',
                del_url: 'cpq/product_series/del',
                multi_url: 'cpq/product_series/multi',
                table: 'cpq_product_series'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '系列编码', operate: 'LIKE'},
                    {field: 'name', title: '系列名称', operate: 'LIKE'},
                    {field: 'business_unit', title: '业务板块'},
                    {field: 'product_line', title: '产品线'},
                    {field: 'default_currency', title: '币种'},
                    {field: 'version', title: '版本', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, buttons: CpqCommon.versionButtons('cpq/product_series'), formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
