define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/release_version/index' + location.search,
                table: 'cpq_release_version'
            }});
            var table = $('#table');
            var baseUrl = 'cpq/release_version';
            var auth = CpqCommon.readAuth(table, ['withdraw', 'rollback']);
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'id', title: 'ID'},
                    {field: 'object_type', title: '对象类型'},
                    {field: 'object_id', title: '对象ID'},
                    {field: 'version', title: '版本', operate: false},
                    {field: 'change_summary', title: '变更摘要', operate: 'LIKE'},
                    {field: 'affected_product_count', title: '影响产品数', operate: false},
                    {field: 'affected_customer_count', title: '影响客户数', operate: false},
                    {field: 'content_hash', title: '内容哈希', operate: false, formatter: function (value) {
                        if (value === null || value === undefined || value === '') {
                            return '';
                        }
                        var text = String(value);
                        return '<span title="' + $('<span>').text(text).html() + '">' +
                            $('<span>').text(text.length > 12 ? text.substring(0, 12) : text).html() + '</span>';
                    }},
                    {field: 'planned_effective_at', title: '计划生效时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'effective_at', title: '实际生效时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'status', title: '状态', searchList: Config.statusList, custom: CpqCommon.statusCustom, formatter: Table.api.formatter.status},
                    {field: 'createtime', title: '创建时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {field: 'operate', title: __('Operate'), table: table, events: CpqCommon.operateEvents(), buttons: [
                        {
                            name: 'withdraw',
                            text: '撤回',
                            icon: 'fa fa-undo',
                            classname: 'btn btn-xs btn-warning btn-ajax',
                            url: baseUrl + '/withdraw',
                            confirm: '确认撤回该发布版本？',
                            refresh: true,
                            visible: function (row) {
                                return auth.withdraw && row.status === 'pending';
                            }
                        },
                        {
                            name: 'rollback',
                            text: '回滚发布',
                            icon: 'fa fa-history',
                            classname: 'btn btn-xs btn-danger',
                            visible: function (row) {
                                return auth.rollback && row.status === 'published';
                            },
                            events: {
                                'click': function (e, value, row) {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    Layer.open({
                                        type: 1,
                                        title: '回滚发布：版本 ' + $('<span>').text(String(row.version)).html(),
                                        area: ['480px', '280px'],
                                        content: '<div style="padding:20px"><div class="form-group"><label>变更摘要</label>' +
                                            '<textarea id="cpq-rollback-summary" class="form-control" rows="4" placeholder="请填写本次回滚发布的变更摘要"></textarea></div>' +
                                            '<p class="text-muted">将基于该历史版本创建一条新的回滚发布记录。</p></div>',
                                        btn: ['确认回滚', '取消'],
                                        yes: function (index) {
                                            Fast.api.ajax({
                                                url: baseUrl + '/rollback',
                                                type: 'POST',
                                                data: {ids: row.id, change_summary: $('#cpq-rollback-summary').val()}
                                            }, function () {
                                                Layer.close(index);
                                                table.bootstrapTable('refresh');
                                                return false;
                                            }, function (data, ret) {
                                                var message = (ret && ret.msg) ? String(ret.msg) : '回滚发布失败';
                                                Layer.alert(
                                                    '<div style="max-height:320px;overflow:auto;word-break:break-all">' +
                                                    $('<div>').text(message).html().replace(/\n/g, '<br>') + '</div>',
                                                    {icon: 2, title: '回滚发布失败'}
                                                );
                                                return false;
                                            });
                                        }
                                    });
                                    return false;
                                }
                            }
                        }
                    ], formatter: Table.api.formatter.operate}
                ]]
            });
            Table.api.bindevent(table);
        },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
