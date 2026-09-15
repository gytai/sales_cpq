define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/tax_rule/index' + location.search,
                add_url: 'cpq/tax_rule/add',
                edit_url: 'cpq/tax_rule/edit',
                del_url: 'cpq/tax_rule/del',
                multi_url: 'cpq/tax_rule/multi',
                table: 'cpq_tax_rule'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '税率编码', operate: 'LIKE'},
                    {field: 'country_code', title: '国家编码', operate: 'LIKE'},
                    {field: 'region_code', title: '区域编码', operate: 'LIKE'},
                    {field: 'product_type', title: '产品类型', operate: 'LIKE'},
                    {field: 'rate', title: '税率', operate: false, formatter: CpqCommon.moneyFormatter},
                    {field: 'effective_date', title: '生效日期', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.date},
                    {field: 'expiry_date', title: '失效日期', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.date},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
            CpqCommon.importPreviewToolbar('#toolbar', 'cpq/tax_rule', CpqCommon.readAuth(table, ['importpreview']));
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
