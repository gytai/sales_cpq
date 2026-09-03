define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/product_model/index' + location.search,
                add_url: 'cpq/product_model/add',
                edit_url: 'cpq/product_model/edit',
                del_url: 'cpq/product_model/del',
                multi_url: 'cpq/product_model/multi',
                table: 'cpq_product_model'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'weigh',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '型号编码', operate: 'LIKE'},
                    {field: 'name', title: '型号名称', operate: 'LIKE'},
                    {field: 'series.name', title: '产品系列', operate: 'LIKE'},
                    {field: 'category_code', title: '产品分类'},
                    {field: 'base_item_code', title: '基础物料编码', operate: 'LIKE'},
                    {field: 'unit', title: '单位'},
                    {field: 'version', title: '版本', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, buttons: CpqCommon.versionButtons('cpq/product_model'), formatter: Table.api.formatter.operate}
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
