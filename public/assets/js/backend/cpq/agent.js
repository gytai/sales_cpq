define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/agent/index' + location.search,
                add_url: 'cpq/agent/add',
                edit_url: 'cpq/agent/edit',
                del_url: 'cpq/agent/del',
                multi_url: 'cpq/agent/multi',
                detail_url: 'cpq/agent/detail',
                table: 'cpq_agent'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '代理商编码', operate: 'LIKE'},
                    {field: 'customer.name', title: '关联客户', operate: 'LIKE'},
                    {field: 'agent_level.name', title: '代理等级', operate: 'LIKE'},
                    {field: 'authorized_regions', title: '授权区域', operate: false},
                    {field: 'authorized_lines', title: '授权产品线', operate: false},
                    {field: 'credit_limit', title: '信用额度', operate: false, formatter: CpqCommon.moneyFormatter},
                    {field: 'auth_start_date', title: '授权生效日期', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'auth_end_date', title: '授权失效日期', operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [CpqCommon.detailButton('cpq/agent')], formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        detail: function () { Controller.api.bindevent(); CpqCommon.bindDetail(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
