define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/customer/index' + location.search,
                add_url: 'cpq/customer/add',
                edit_url: 'cpq/customer/edit',
                del_url: 'cpq/customer/del',
                multi_url: 'cpq/customer/multi',
                detail_url: 'cpq/customer/detail',
                table: 'cpq_customer'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '客户编码', operate: 'LIKE'},
                    {field: 'name', title: '客户名称', operate: 'LIKE'},
                    {field: 'type', title: '客户类型', searchList: Config.typeList, formatter: Table.api.formatter.status},
                    {field: 'customer_level.name', title: '客户等级', operate: 'LIKE'},
                    {field: 'region.name', title: '销售区域', operate: 'LIKE'},
                    {field: 'credit_code', title: '统一信用代码', operate: 'LIKE'},
                    {field: 'country_code', title: '国家/地区'},
                    {field: 'default_currency', title: '默认币种'},
                    {field: 'sales_org.name', title: '销售组织', operate: 'LIKE'},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.detailButton('cpq/customer')], formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
            CpqCommon.importPreviewToolbar('#toolbar', 'cpq/customer', CpqCommon.readAuth(table, ['importpreview']));
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        detail: function () { Controller.api.bindevent(); CpqCommon.bindDetail(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
