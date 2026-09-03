define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/option_group/index' + location.search,
                add_url: 'cpq/option_group/add',
                edit_url: 'cpq/option_group/edit',
                del_url: 'cpq/option_group/del',
                multi_url: 'cpq/option_group/multi',
                table: 'cpq_option_group'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'sort',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '配置组编码', operate: 'LIKE'},
                    {field: 'name', title: '配置组名称', operate: 'LIKE'},
                    {field: 'input_type', title: '控件类型', searchList: Config.inputTypeList, formatter: Table.api.formatter.normal},
                    {field: 'is_required', title: '必选', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                    {field: 'min_select', title: '最少选择', operate: false},
                    {field: 'max_select', title: '最多选择', operate: false},
                    {field: 'affects_price', title: '影响价格', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                    {field: 'affects_bom', title: '影响BOM', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
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
