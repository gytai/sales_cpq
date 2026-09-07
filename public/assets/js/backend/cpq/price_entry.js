define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/price_entry/index' + location.search,
                add_url: 'cpq/price_entry/add',
                edit_url: 'cpq/price_entry/edit',
                del_url: 'cpq/price_entry/del',
                multi_url: 'cpq/price_entry/multi',
                table: 'cpq_price_entry'
            }});
            var table = $('#table');
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'price_book_id', title: '价格表ID'},
                    {field: 'target_type', title: '对象类型', searchList: Config.targetTypeList, formatter: Table.api.formatter.status},
                    {field: 'target_id', title: '对象ID'},
                    {field: 'target_name', title: '对象名称'},
                    {field: 'amount', title: '金额', operate: false, formatter: CpqCommon.moneyFormatter},
                    {field: 'unit', title: '单位'},
                    {field: 'min_qty', title: '最小数量', formatter: CpqCommon.moneyFormatter},
                    {field: 'max_qty', title: '最大数量', formatter: CpqCommon.moneyFormatter},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
            CpqCommon.importPreviewToolbar('#toolbar', 'cpq/price_entry', CpqCommon.readAuth(table, ['importpreview']));
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {
            bindevent: function () {
                var form = $('form[role=form]');
                Form.api.bindevent(form);

                // 定价对象：按「对象类型」联动的可搜索下拉（selectpage 数据源 cpq/price_entry/selecttarget）
                var $type = form.find('#cpq-target-type');
                var $target = form.find('#cpq-target-id');
                if ($target.length) {
                    require(['selectpage'], function () {
                        $target.selectPage({
                            params: function () {
                                return {target_type: $type.val()};
                            },
                            eAjaxSuccess: function (data) {
                                data.list = typeof data.rows !== 'undefined' ? data.rows : (typeof data.list !== 'undefined' ? data.list : []);
                                data.totalRow = typeof data.total !== 'undefined' ? data.total : (typeof data.totalRow !== 'undefined' ? data.totalRow : data.list.length);
                                return data;
                            }
                        });
                        // 切换对象类型后清空已选对象，避免跨类型残留错误 id
                        $type.on('change', function () {
                            $target.selectPageClear();
                        });
                    });
                }
            }
        }
    };
    return Controller;
});
