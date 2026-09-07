define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/parameter_definition/index' + location.search, add_url: 'cpq/parameter_definition/add', edit_url: 'cpq/parameter_definition/edit', del_url: 'cpq/parameter_definition/del', multi_url: 'cpq/parameter_definition/multi', detail_url: 'cpq/parameter_definition/detail', table: 'cpq_parameter_definition'}});
            var table = $('#table');
            var baseUrl = 'cpq/parameter_definition';
            table.bootstrapTable({url: $.fn.bootstrapTable.defaults.extend.index_url, pk: 'id', sortName: 'id', sortOrder: 'desc', columns: [[
                {checkbox: true},
                {field: 'code', title: '参数编码', operate: 'LIKE'},
                {field: 'name', title: '参数名称', operate: 'LIKE'},
                {field: 'value_type', title: '值类型', searchList: Config.valueTypeList, formatter: Table.api.formatter.normal},
                {field: 'unit', title: '单位'},
                {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.detailButton(baseUrl)], formatter: Table.api.formatter.operate}
            ]]});
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
