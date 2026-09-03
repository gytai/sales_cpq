define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/config_rule/index' + location.search,
                add_url: 'cpq/config_rule/add',
                edit_url: 'cpq/config_rule/edit',
                del_url: 'cpq/config_rule/del',
                multi_url: 'cpq/config_rule/multi',
                table: 'cpq_config_rule'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'priority',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '规则编码', operate: 'LIKE'},
                    {field: 'name', title: '规则名称', operate: 'LIKE'},
                    {field: 'type', title: '规则类型', searchList: Config.typeList, formatter: Table.api.formatter.normal},
                    {field: 'product_model.name', title: '适用型号', operate: 'LIKE'},
                    {field: 'product_line', title: '适用产品线'},
                    {field: 'priority', title: '优先级'},
                    {field: 'severity', title: '严重级别', searchList: Config.severityList, formatter: Table.api.formatter.normal},
                    {field: 'version', title: '版本', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, buttons: CpqCommon.versionButtons('cpq/config_rule'), formatter: Table.api.formatter.operate}
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
