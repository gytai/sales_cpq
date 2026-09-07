define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/option_value/index' + location.search,
                add_url: 'cpq/option_value/add',
                edit_url: 'cpq/option_value/edit',
                del_url: 'cpq/option_value/del',
                multi_url: 'cpq/option_value/multi',
                detail_url: 'cpq/option_value/detail',
                table: 'cpq_option_value'
            }});
            var table = $('#table');
            var baseUrl = 'cpq/option_value';
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'option_group.name', title: '配置组', operate: 'LIKE'},
                    {field: 'code', title: '选项编码', operate: 'LIKE'},
                    {field: 'name', title: '选项名称', operate: 'LIKE'},
                    {field: 'material_code', title: '物料编码', operate: 'LIKE'},
                    {field: 'no_material', title: '不产生物料', searchList: {1: '是', 0: '否'}, formatter: function (value) {
                        return value == 1 ? '<span class="label label-warning">是</span>' : '<span class="label label-default">否</span>';
                    }},
                    {field: 'default_qty', title: '默认数量', operate: false},
                    {field: 'price_key', title: '价格引用键'},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.detailButton(baseUrl)], formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        detail: function () {
            Controller.api.bindevent();
            CpqCommon.bindDetail();
        },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
