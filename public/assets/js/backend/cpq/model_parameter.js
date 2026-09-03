define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/model_parameter/index' + location.search, add_url: 'cpq/model_parameter/add', edit_url: 'cpq/model_parameter/edit', del_url: 'cpq/model_parameter/del', multi_url: 'cpq/model_parameter/multi', table: 'cpq_model_parameter'}});
            var table = $('#table');
            table.bootstrapTable({url: $.fn.bootstrapTable.defaults.extend.index_url, pk: 'id', sortName: 'sort', columns: [[
                {checkbox: true},
                {field: 'product_model.name', title: '产品型号', operate: 'LIKE'},
                {field: 'parameter_definition.name', title: '技术参数', operate: 'LIKE'},
                {field: 'value', title: '参数值', operate: 'LIKE'},
                {field: 'is_configurable', title: '可配置', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                {field: 'is_required', title: '必填', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                {field: 'sort', title: '排序'},
                {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
            ]]});
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
