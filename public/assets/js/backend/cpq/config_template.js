define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/config_template/index' + location.search, add_url: 'cpq/config_template/add', edit_url: 'cpq/config_template/edit', del_url: 'cpq/config_template/del', multi_url: 'cpq/config_template/multi', table: 'cpq_config_template'}});
            var table = $('#table');
            table.bootstrapTable({url: $.fn.bootstrapTable.defaults.extend.index_url, pk: 'id', sortName: 'id', sortOrder: 'desc', columns: [[
                {checkbox: true},
                {field: 'code', title: '模板编码', operate: 'LIKE'},
                {field: 'name', title: '模板名称', operate: 'LIKE'},
                {field: 'product_model.name', title: '产品型号', operate: 'LIKE'},
                {field: 'market_scope', title: '适用市场'},
                {field: 'customer_level', title: '客户等级'},
                {field: 'version', title: '版本', operate: false},
                {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status},
                {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, buttons: CpqCommon.versionButtons('cpq/config_template'), formatter: Table.api.formatter.operate}
            ]]});
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
