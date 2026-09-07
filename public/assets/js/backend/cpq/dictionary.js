/**
 * CPQ 字典与参数维护（P101，GYTAI-78）
 * 字典值列表 + 新增/编辑 dialog + 启停切换 + 删除（引用保护由服务端强制）。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusText = {enabled: '启用', disabled: '停用'};

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/dictionary/index' + location.search,
                add_url: 'cpq/dictionary/add',
                edit_url: 'cpq/dictionary/edit',
                del_url: 'cpq/dictionary/del'
            }});
            var table = $('#table');
            var canWrite = !!Config.canWrite;

            if (canWrite) {
                $('#toolbar').prepend(
                    '<a href="javascript:;" class="btn btn-success btn-cpq-add"><i class="fa fa-plus"></i> 新增字典值</a>' +
                    '<a href="javascript:;" class="btn btn-danger btn-cpq-del"><i class="fa fa-trash"></i> 删除</a>');
            }

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'asc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    // 后端按 page/limit 分页：将 bootstrap-table 的 offset/limit 换算为 page
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.dictionary_code = $('#cpq-dict-code').val() || '';
                    params.keyword = $.trim($('#cpq-dict-keyword').val() || '');
                    return params;
                },
                columns: [[
                    {checkbox: canWrite},
                    {field: 'id', title: 'ID', width: 60},
                    {field: 'dictionary_code', title: '字典分类', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'value_code', title: '值编码', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'label', title: '显示名', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'sort', title: '排序', width: 60},
                    {field: 'ref_count', title: '引用数', width: 70, formatter: function (value) {
                        return parseInt(value, 10) > 0 ? '<span class="label label-warning">' + parseInt(value, 10) + '</span>' : '0';
                    }},
                    {field: 'status', title: '状态', formatter: function (value) {
                        var color = value === 'enabled' ? 'success' : 'gray';
                        return '<span class="label label-' + color + '">' + escapeHtml(statusText[value] || value) + '</span>';
                    }},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: $.extend(CpqCommon.operateEvents(), {
                            'click .btn-cpq-toggle': function (e, value, row) {
                                e.stopPropagation();
                                var target = row.status === 'enabled' ? 'disabled' : 'enabled';
                                Layer.confirm('确认' + (target === 'enabled' ? '启用' : '停用') + '「' + escapeHtml(row.label) + '」？', {icon: 3, title: '状态确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/dictionary/toggle', type: 'POST', data: {id: row.id, status: target}}, function (data, ret) {
                                        Toastr.success(ret && ret.msg ? ret.msg : '操作成功');
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '操作失败'), {icon: 2, title: '操作被拒绝'});
                                        return false;
                                    });
                                });
                            }
                        }),
                        buttons: [
                            {
                                name: 'edit', text: '编辑', title: '编辑', icon: 'fa fa-pencil',
                                classname: 'btn btn-xs btn-success btn-dialog',
                                url: 'cpq/dictionary/edit/ids/{id}',
                                extend: 'data-toggle="tooltip" data-container="body" data-area=\'["560px","480px"]\'',
                                visible: function () { return canWrite; }
                            },
                            {
                                name: 'toggle', text: '启停', title: '启用/停用', icon: 'fa fa-power-off',
                                classname: 'btn btn-xs btn-warning btn-cpq-toggle',
                                visible: function () { return canWrite; }
                            },
                            {
                                name: 'del', text: '删除', title: '删除', icon: 'fa fa-trash',
                                classname: 'btn btn-xs btn-danger btn-delone',
                                visible: function () { return canWrite; }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#toolbar').on('click', '.btn-cpq-add', function () {
                Fast.api.open('cpq/dictionary/add', '新增字典值', {area: ['560px', '480px'], callback: function () {
                    table.bootstrapTable('refresh');
                }});
            });
            $('#toolbar').on('click', '.btn-cpq-del', function () {
                var selections = table.bootstrapTable('getSelections');
                if (!selections.length) {
                    Toastr.error('请先勾选要删除的字典值');
                    return;
                }
                var ids = $.map(selections, function (row) { return row.id; });
                Layer.confirm('确认删除勾选的 ' + ids.length + ' 条字典值？被引用的值将被拒绝并汇总提示。', {icon: 3, title: '删除确认'}, function (index) {
                    Layer.close(index);
                    Fast.api.ajax({url: 'cpq/dictionary/del', type: 'POST', data: {ids: ids.join(',')}}, function (data, ret) {
                        Toastr.success(ret && ret.msg ? ret.msg : '删除完成');
                        table.trigger('uncheckbox');
                        table.bootstrapTable('refresh');
                        return false;
                    }, function (data, ret) {
                        var message = ret && ret.msg ? String(ret.msg) : '删除失败';
                        Layer.alert('<div style="max-height:320px;overflow:auto;word-break:break-all">' +
                            $('<div>').text(message).html().replace(/\n/g, '<br>') + '</div>', {icon: 2, title: '删除结果'});
                        table.bootstrapTable('refresh');
                        return false;
                    });
                });
            });
            $('#cpq-dict-code').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-dict-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
        },
        add: function () { Controller.api.bindevent(); },
        edit: function () { Controller.api.bindevent(); },
        api: {bindevent: function () { Form.api.bindevent($('form[role=form]')); }}
    };
    return Controller;
});
