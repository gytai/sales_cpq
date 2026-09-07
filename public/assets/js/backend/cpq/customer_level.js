define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/customer_level/index' + location.search,
                add_url: 'cpq/customer_level/add',
                edit_url: 'cpq/customer_level/edit',
                del_url: 'cpq/customer_level/del',
                multi_url: 'cpq/customer_level/multi',
                table: 'cpq_customer_level'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'sort',
                sortOrder: 'asc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '等级编码', operate: 'LIKE'},
                    {field: 'name', title: '等级名称', operate: 'LIKE'},
                    {field: 'sort', title: '排序', operate: false},
                    {field: 'default_discount', title: '默认折扣率', operate: false, formatter: CpqCommon.moneyFormatter},
                    {field: 'market_scope', title: '市场范围', searchList: Config.marketScopeList, formatter: Table.api.formatter.status},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), formatter: Table.api.formatter.operate}
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
