define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/fee_rule/index' + location.search,
                add_url: 'cpq/fee_rule/add',
                edit_url: 'cpq/fee_rule/edit',
                del_url: 'cpq/fee_rule/del',
                multi_url: 'cpq/fee_rule/multi',
                table: 'cpq_fee_rule'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '规则编码', operate: 'LIKE'},
                    {field: 'name', title: '规则名称', operate: 'LIKE'},
                    {field: 'fee_type', title: '费用类型', operate: 'LIKE'},
                    {field: 'calculation_type', title: '计算方式', searchList: Config.calculationTypeList, formatter: Table.api.formatter.status},
                    {field: 'value', title: '计算值', operate: false, formatter: CpqCommon.moneyFormatter},
                    {field: 'currency', title: '币种'},
                    {field: 'include_in_margin', title: '计入毛利', operate: false, formatter: CpqCommon.booleanFormatter},
                    {field: 'include_in_floor', title: '计入底价', operate: false, formatter: CpqCommon.booleanFormatter},
                    {field: 'priority', title: '优先级', operate: false},
                    {field: 'effective_date', title: '生效日期', operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'expiry_date', title: '失效日期', operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'status', title: '状态', searchList: Config.statusList, formatter: Table.api.formatter.status, custom: CpqCommon.statusCustom},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
            CpqCommon.importPreviewToolbar('#toolbar', 'cpq/fee_rule', CpqCommon.readAuth(table, ['importpreview']));
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {
            bindevent: function () {
                Form.api.bindevent($('form[role=form]'), undefined, undefined, function () {
                    var value = $.trim($('textarea[name="row[condition_json]"]').val());
                    if (value === '') {
                        return true;
                    }
                    try {
                        JSON.parse(value);
                        return true;
                    } catch (e) {
                        Toastr.error('适用条件必须是合法的 JSON 格式：' + e.message);
                        return false;
                    }
                });
            }
        }
    };
    return Controller;
});
