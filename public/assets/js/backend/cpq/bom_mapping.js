define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/bom_mapping/index' + location.search, add_url: 'cpq/bom_mapping/add', edit_url: 'cpq/bom_mapping/edit', del_url: 'cpq/bom_mapping/del', multi_url: 'cpq/bom_mapping/multi', detail_url: 'cpq/bom_mapping/detail', table: 'cpq_bom_mapping'}});
            var table = $('#table');
            var baseUrl = 'cpq/bom_mapping';
            var auth = CpqCommon.readAuth(table, ['publish', 'expire', 'copy']);
            table.bootstrapTable({url: $.fn.bootstrapTable.defaults.extend.index_url, pk: 'id', sortName: 'id', sortOrder: 'desc', columns: [[
                {checkbox: true},
                {field: 'product_model.name', title: '产品型号', operate: 'LIKE'},
                {field: 'option_value.name', title: '配置选项', operate: 'LIKE'},
                {field: 'material_code', title: '物料编码', operate: 'LIKE'},
                {field: 'qty_formula', title: '数量公式', operate: false},
                {field: 'unit', title: '单位'},
                {field: 'loss_rate', title: '损耗率'},
                {field: 'version', title: '版本', operate: false},
                {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.detailButton(baseUrl)].concat(CpqCommon.versionGuardButtons()).concat(CpqCommon.versionButtons(baseUrl, auth, true)), formatter: Table.api.formatter.operate}
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
