define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/region/index' + location.search,
                add_url: 'cpq/region/add',
                edit_url: 'cpq/region/edit',
                del_url: 'cpq/region/del',
                multi_url: 'cpq/region/multi',
                table: 'cpq_region'
            }});
            var table = $('#table');
            var baseUrl = 'cpq/region';
            var auth = CpqCommon.readAuth(table, ['move']);
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'path',
                sortOrder: 'asc',
                pagination: false,
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '区域编码', operate: 'LIKE'},
                    {field: 'name', title: '区域名称', operate: 'LIKE', formatter: CpqCommon.treeNameFormatter},
                    {field: 'default_currency', title: '默认币种'},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.moveButton(baseUrl, auth)], formatter: Table.api.formatter.operate}
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
