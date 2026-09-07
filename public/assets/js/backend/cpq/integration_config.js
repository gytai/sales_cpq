/**
 * CPQ 接口管理（P104，GYTAI-78）
 * 配置列表 + 基本信息 dialog + 凭证重置（只写不读，永不回显）+ 启停。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var systemText = {crm: 'CRM', erp: 'ERP', mail: '邮件', other: '其他'};
    var authText = {none: '无鉴权', hmac: 'HMAC', oauth2: 'OAuth2'};
    var statusText = {enabled: '启用', disabled: '停用'};

    function openResetCredential(row, table) {
        Layer.open({
            type: 1,
            title: '重置凭证：' + row.code,
            area: ['560px', '380px'],
            content: '<div style="padding:18px">' +
                '<div class="alert alert-info" style="word-break:break-all">出于安全考虑，原凭证不可查看。本操作以输入内容<b>整体覆盖</b>现有凭证（AES-256-GCM 加密落库），并写入审计日志。</div>' +
                '<div class="form-group"><label>凭证 JSON（键值对对象）</label>' +
                '<textarea id="cpq-itg-credentials" class="form-control" rows="7" style="font-family:monospace" placeholder="{&quot;client_id&quot;:&quot;xxx&quot;,&quot;client_secret&quot;:&quot;yyy&quot;}"></textarea></div>' +
                '</div>',
            btn: ['确认重置', '取消'],
            yes: function (index) {
                var raw = $.trim($('#cpq-itg-credentials').val());
                if (!raw) {
                    Toastr.error('请输入凭证 JSON');
                    return;
                }
                var parsed;
                try {
                    parsed = $.parseJSON(raw);
                } catch (e) {
                    Toastr.error('凭证不是合法的 JSON');
                    return;
                }
                if (!$.isPlainObject(parsed) || $.isEmptyObject(parsed)) {
                    Toastr.error('凭证必须是 JSON 对象（键值对）');
                    return;
                }
                Fast.api.ajax({
                    url: 'cpq/integration_config/resetcredential',
                    type: 'POST',
                    data: {id: row.id, credentials: raw}
                }, function (data, ret) {
                    Toastr.success(ret && ret.msg ? ret.msg : '凭证已重置');
                    Layer.close(index);
                    table.bootstrapTable('refresh');
                    return false;
                }, function (data, ret) {
                    Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '重置失败'), {icon: 2, title: '重置被拒绝'});
                    return false;
                });
            }
        });
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/integration_config/index' + location.search,
                add_url: 'cpq/integration_config/add',
                edit_url: 'cpq/integration_config/edit'
            }});
            var table = $('#table');
            var canWrite = !!Config.canWrite;

            if (canWrite) {
                $('#toolbar').prepend('<a href="javascript:;" class="btn btn-success btn-cpq-add"><i class="fa fa-plus"></i> 新增接口</a>');
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
                    params.system_type = $('#cpq-itg-type').val() || '';
                    params.status = $('#cpq-itg-status').val() || '';
                    params.keyword = $.trim($('#cpq-itg-keyword').val() || '');
                    return params;
                },
                columns: [[
                    {field: 'id', title: 'ID', width: 60},
                    {field: 'code', title: '编码', formatter: function (value) { return '<strong>' + escapeHtml(value) + '</strong>'; }},
                    {field: 'name', title: '名称', formatter: function (value) { return escapeHtml(value); }},
                    {field: 'system_type', title: '系统类型', formatter: function (value) { return escapeHtml(systemText[value] || value); }},
                    {field: 'base_url', title: 'Base URL', formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'auth_type', title: '鉴权', formatter: function (value) { return escapeHtml(authText[value] || value); }},
                    {field: 'credentials_configured', title: '凭证', width: 80, formatter: function (value) {
                        return value
                            ? '<span class="label label-success">已配置</span>'
                            : '<span class="label label-default">未配置</span>';
                    }},
                    {field: 'status', title: '状态', formatter: function (value) {
                        var color = value === 'enabled' ? 'success' : 'gray';
                        return '<span class="label label-' + color + '">' + escapeHtml(statusText[value] || value) + '</span>';
                    }},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-resetcredential': function (e, value, row) {
                                e.stopPropagation();
                                openResetCredential(row, table);
                            },
                            'click .btn-cpq-toggle': function (e, value, row) {
                                e.stopPropagation();
                                var target = row.status === 'enabled' ? 'disabled' : 'enabled';
                                Layer.confirm('确认' + (target === 'enabled' ? '启用' : '停用') + '接口「' + escapeHtml(row.name) + '」？', {icon: 3, title: '状态确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/integration_config/toggle', type: 'POST', data: {id: row.id, status: target}}, function (data, ret) {
                                        Toastr.success(ret && ret.msg ? ret.msg : '操作成功');
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '操作失败'), {icon: 2, title: '操作被拒绝'});
                                        return false;
                                    });
                                });
                            }
                        },
                        buttons: [
                            {
                                name: 'edit', text: '编辑', title: '编辑基本信息', icon: 'fa fa-pencil',
                                classname: 'btn btn-xs btn-success btn-dialog',
                                url: 'cpq/integration_config/edit/ids/{id}',
                                extend: 'data-toggle="tooltip" data-container="body" data-area=\'["600px","620px"]\'',
                                visible: function () { return canWrite; }
                            },
                            {
                                name: 'resetcredential', text: '重置凭证', title: '重置凭证', icon: 'fa fa-key',
                                classname: 'btn btn-xs btn-warning btn-cpq-resetcredential',
                                visible: function () { return canWrite; }
                            },
                            {
                                name: 'toggle', text: '启停', title: '启用/停用', icon: 'fa fa-power-off',
                                classname: 'btn btn-xs btn-info btn-cpq-toggle',
                                visible: function () { return canWrite; }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#toolbar').on('click', '.btn-cpq-add', function () {
                Fast.api.open('cpq/integration_config/add', '新增接口配置', {area: ['600px', '620px'], callback: function () {
                    table.bootstrapTable('refresh');
                }});
            });
            $('#cpq-itg-type').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-itg-status').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-itg-keyword').on('keydown', function (e) {
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
