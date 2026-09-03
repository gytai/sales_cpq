define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/model_option_group/index' + location.search, add_url: 'cpq/model_option_group/add', edit_url: 'cpq/model_option_group/edit', del_url: 'cpq/model_option_group/del', multi_url: 'cpq/model_option_group/multi', table: 'cpq_model_option_group'}});
            var table = $('#table');
            table.bootstrapTable({url: $.fn.bootstrapTable.defaults.extend.index_url, pk: 'id', sortName: 'sort', columns: [[
                {checkbox: true},
                {field: 'product_model.name', title: '产品型号', operate: 'LIKE'},
                {field: 'option_group.name', title: '配置组', operate: 'LIKE'},
                {field: 'is_visible', title: '可见', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                {field: 'is_required', title: '必选', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                {field: 'default_value', title: '默认值', operate: false},
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
