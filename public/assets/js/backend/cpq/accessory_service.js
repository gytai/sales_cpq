define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {index_url: 'cpq/accessory_service/index' + location.search, add_url: 'cpq/accessory_service/add', edit_url: 'cpq/accessory_service/edit', del_url: 'cpq/accessory_service/del', multi_url: 'cpq/accessory_service/multi', detail_url: 'cpq/accessory_service/detail', table: 'cpq_accessory_service'}});
            var table = $('#table');
            var baseUrl = 'cpq/accessory_service';
            table.bootstrapTable({url: $.fn.bootstrapTable.defaults.extend.index_url, pk: 'id', sortName: 'id', sortOrder: 'desc', columns: [[
                {checkbox: true},
                {field: 'code', title: '编码', operate: 'LIKE'},
                {field: 'type', title: '类型', operate: 'LIKE'},
                {field: 'name', title: '名称', operate: 'LIKE'},
                {field: 'unit', title: '单位'},
                {field: 'product_line', title: '产品线'},
                {field: 'is_inventory_item', title: '库存物料', searchList: {'1': '是', '0': '否'}, formatter: CpqCommon.booleanFormatter},
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
