/**
 * CPQ 数据范围授权管理（P100，GYTAI-78）
 * 管理员列表 + 「授权管理」弹层：产品线授权、组织成员授权与有效范围预览。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    function postForm(url, data, onSuccess) {
        Fast.api.ajax({url: url, type: 'POST', data: data}, function (data, ret) {
            Toastr.success(ret && ret.msg ? ret.msg : '操作成功');
            if (typeof onSuccess === 'function') {
                onSuccess();
            }
            return false;
        }, function (data, ret) {
            Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '操作失败'), {icon: 2, title: '操作被拒绝'});
            return false;
        });
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/data_scope/index' + location.search
            }});
            var table = $('#table');
            var canWrite = !!Config.canWrite;

            function effectiveHtml(effective) {
                var lines = effective.product_lines === null ? '全部产品线（不受限）'
                    : (effective.product_lines && effective.product_lines.length ? effective.product_lines.join('、') : '（无产品线授权）');
                var orgIds = (effective.sales_org_ids || []).join(', ');
                var regionIds = (effective.region_ids || []).join(', ');
                return '<div class="well well-sm" style="margin:0 0 12px">' +
                    '<b>有效范围预览（QuoteDataScopeService 计算）</b><br>' +
                    '产品线：' + escapeHtml(lines) + '<br>' +
                    '销售组织ID：' + escapeHtml(orgIds || '（无）') + '　区域ID：' + escapeHtml(regionIds || '（无）') + '<br>' +
                    '不受限：' + (effective.unrestricted ? '是' : '否') + '　仅本人报价：' + (effective.owner_only ? '是' : '否') +
                    '</div>';
            }

            function linesHtml(lines) {
                var html = '<table class="table table-condensed table-bordered"><thead><tr><th>ID</th><th>产品线</th><th>授权时间</th>' +
                    (canWrite ? '<th style="width:70px">操作</th>' : '') + '</tr></thead><tbody>';
                if (!lines.length) {
                    html += '<tr><td colspan="' + (canWrite ? 4 : 3) + '" class="text-muted">暂无产品线授权</td></tr>';
                }
                $.each(lines, function (_, line) {
                    html += '<tr><td>' + line.id + '</td><td>' + escapeHtml(line.product_line) + '</td>' +
                        '<td>' + escapeHtml(line.createtime ? new Date(line.createtime * 1000).toLocaleString() : '') + '</td>' +
                        (canWrite ? '<td><a href="javascript:;" class="btn btn-xs btn-danger cpq-revoke-line" data-id="' + line.id + '" data-line="' + escapeHtml(line.product_line) + '">撤销</a></td>' : '') +
                        '</tr>';
                });
                return html + '</tbody></table>';
            }

            function orgsHtml(orgs) {
                var statusText = {normal: '正常', hidden: '已停用'};
                var html = '<table class="table table-condensed table-bordered"><thead><tr><th>ID</th><th>组织</th><th>角色</th><th>生效</th><th>失效</th><th>状态</th>' +
                    (canWrite ? '<th style="width:70px">操作</th>' : '') + '</tr></thead><tbody>';
                if (!orgs.length) {
                    html += '<tr><td colspan="' + (canWrite ? 7 : 6) + '" class="text-muted">暂无组织成员记录</td></tr>';
                }
                $.each(orgs, function (_, org) {
                    var orgName = org.org_name ? org.org_name : ('#' + org.org_id);
                    html += '<tr><td>' + org.id + '</td><td>' + escapeHtml(orgName) + '</td><td>' + escapeHtml(org.role) + '</td>' +
                        '<td>' + escapeHtml(org.effective_date || '—') + '</td><td>' + escapeHtml(org.expiry_date || '—') + '</td>' +
                        '<td>' + escapeHtml(statusText[org.status] || org.status) + '</td>' +
                        (canWrite ? '<td>' + (org.status === 'normal'
                            ? '<a href="javascript:;" class="btn btn-xs btn-danger cpq-revoke-org" data-id="' + org.id + '" data-org="' + escapeHtml(orgName) + '">撤销</a>'
                            : '') + '</td>' : '') +
                        '</tr>';
                });
                return html + '</tbody></table>';
            }

            function grantFormsHtml(adminId) {
                if (!canWrite) {
                    return '';
                }
                return '<div class="row" style="margin-top:6px">' +
                    '<div class="col-sm-6"><div class="panel panel-default" style="margin-bottom:10px"><div class="panel-heading"><b>授予产品线</b></div><div class="panel-body">' +
                    '<div class="input-group"><input type="text" id="cpq-grant-line" class="form-control" maxlength="64" placeholder="产品线编码，* 表示全部">' +
                    '<span class="input-group-btn"><button type="button" class="btn btn-primary" id="cpq-grant-line-btn">授权</button></span></div>' +
                    '<p class="help-block">1-64 位字母/数字/._-；重复授权幂等。</p></div></div></div>' +
                    '<div class="col-sm-6"><div class="panel panel-default" style="margin-bottom:10px"><div class="panel-heading"><b>授予组织成员</b></div><div class="panel-body">' +
                    '<div class="form-group"><select id="cpq-grant-org" class="form-control"><option value="">加载组织中…</option></select></div>' +
                    '<div class="form-group"><select id="cpq-grant-role" class="form-control"><option value="sales">sales（销售）</option><option value="sales_manager">sales_manager（销售经理）</option></select></div>' +
                    '<div class="form-group"><input type="text" id="cpq-grant-effective" class="form-control" placeholder="生效日期 YYYY-MM-DD（可空）"></div>' +
                    '<div class="form-group"><input type="text" id="cpq-grant-expiry" class="form-control" placeholder="失效日期 YYYY-MM-DD（可空）"></div>' +
                    '<button type="button" class="btn btn-primary" id="cpq-grant-org-btn">授权</button>' +
                    '</div></div></div></div>';
            }

            function bindManageEvents(layerIndex, adminRow) {
                var layerBody = $('#layui-layer' + layerIndex);
                if (!canWrite) {
                    return;
                }
                Fast.api.ajax({url: 'cpq/data_scope/orgs', type: 'GET'}, function (data, ret) {
                    var rows = (ret && ret.data && ret.data.rows) || [];
                    var options = '';
                    $.each(rows, function (_, org) {
                        var indent = '';
                        for (var i = 1; i < (parseInt(org.level, 10) || 1); i++) {
                            indent += '　';
                        }
                        options += '<option value="' + org.id + '">' + escapeHtml(indent + org.name) + '</option>';
                    });
                    layerBody.find('#cpq-grant-org').html(options || '<option value="">（无可用组织）</option>');
                    return false;
                }, function () {
                    layerBody.find('#cpq-grant-org').html('<option value="">组织加载失败</option>');
                    return false;
                });

                layerBody.on('click', '#cpq-grant-line-btn', function () {
                    var productLine = $.trim(layerBody.find('#cpq-grant-line').val());
                    if (!productLine) {
                        Toastr.error('请输入产品线编码');
                        return;
                    }
                    postForm('cpq/data_scope/grantline', {admin_id: adminRow.id, product_line: productLine}, function () {
                        reloadManage(adminRow);
                        table.bootstrapTable('refresh');
                    });
                });
                layerBody.on('click', '#cpq-grant-org-btn', function () {
                    var orgId = parseInt(layerBody.find('#cpq-grant-org').val(), 10) || 0;
                    if (!orgId) {
                        Toastr.error('请选择销售组织');
                        return;
                    }
                    postForm('cpq/data_scope/grantorg', {
                        admin_id: adminRow.id,
                        org_id: orgId,
                        role: layerBody.find('#cpq-grant-role').val(),
                        effective_date: $.trim(layerBody.find('#cpq-grant-effective').val()),
                        expiry_date: $.trim(layerBody.find('#cpq-grant-expiry').val())
                    }, function () {
                        reloadManage(adminRow);
                        table.bootstrapTable('refresh');
                    });
                });
                layerBody.on('click', '.cpq-revoke-line', function () {
                    var id = $(this).data('id');
                    var line = $(this).data('line');
                    Layer.confirm('确认撤销产品线「' + escapeHtml(line) + '」的授权？撤销立即生效。', {icon: 3, title: '撤销确认'}, function (confirmIndex) {
                        Layer.close(confirmIndex);
                        postForm('cpq/data_scope/revokeline', {id: id}, function () {
                            reloadManage(adminRow);
                            table.bootstrapTable('refresh');
                        });
                    });
                });
                layerBody.on('click', '.cpq-revoke-org', function () {
                    var id = $(this).data('id');
                    var orgName = $(this).data('org');
                    Layer.confirm('确认撤销组织「' + escapeHtml(orgName) + '」的成员资格？记录将置为停用并保留历史。', {icon: 3, title: '撤销确认'}, function (confirmIndex) {
                        Layer.close(confirmIndex);
                        postForm('cpq/data_scope/revokeorg', {id: id}, function () {
                            reloadManage(adminRow);
                            table.bootstrapTable('refresh');
                        });
                    });
                });
            }

            function openManage(adminRow) {
                Fast.api.ajax({url: 'cpq/data_scope/detail', type: 'GET', data: {admin_id: adminRow.id}}, function (data, ret) {
                    var payload = ret && ret.data ? ret.data : {};
                    var admin = payload.admin || adminRow;
                    var content = '<div style="padding:15px">' +
                        '<p><b>' + escapeHtml((admin.nickname || '') + '（' + (admin.username || '') + '）') + '</b></p>' +
                        effectiveHtml(payload.effective || {}) +
                        '<p><b>产品线授权</b></p>' + linesHtml(payload.lines || []) +
                        '<p><b>组织成员</b></p>' + orgsHtml(payload.orgs || []) +
                        grantFormsHtml(adminRow.id) +
                        '</div>';
                    var layerIndex = Layer.open({
                        type: 1,
                        title: '授权管理：' + (admin.username || adminRow.username),
                        area: ['860px', '600px'],
                        content: content,
                        success: function (layero, index) {
                            bindManageEvents(index, adminRow);
                        }
                    });
                    layerBodyMap = {index: layerIndex, adminRow: adminRow};
                    return false;
                }, function (data, ret) {
                    Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '授权明细加载失败'), {icon: 2, title: '加载失败'});
                    return false;
                });
            }

            var layerBodyMap = null;

            function reloadManage(adminRow) {
                if (layerBodyMap && layerBodyMap.index) {
                    Layer.close(layerBodyMap.index);
                    layerBodyMap = null;
                }
                openManage(adminRow);
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
                    params.keyword = $.trim($('#cpq-scope-keyword').val() || '');
                    return params;
                },
                columns: [[
                    {field: 'id', title: 'ID', width: 60},
                    {field: 'username', title: '用户名'},
                    {field: 'nickname', title: '昵称', formatter: function (value) { return escapeHtml(value || ''); }},
                    {field: 'roles', title: 'CPQ 角色', formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'product_line_summary', title: '产品线授权', formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'org_summary', title: '组织授权', formatter: function (value) { return escapeHtml(value || '—'); }},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-manage': function (e, value, row) {
                                e.stopPropagation();
                                openManage(row);
                            }
                        },
                        buttons: [{
                            name: 'manage', text: '授权管理', title: '授权管理', icon: 'fa fa-shield',
                            classname: 'btn btn-xs btn-primary btn-cpq-manage'
                        }],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-scope-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });
        }
    };
    return Controller;
});
