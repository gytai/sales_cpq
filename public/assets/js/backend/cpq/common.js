define(['jquery', 'table'], function ($, Table) {

    // 权限感知：auth 未提供时保持向后兼容（全部展示）；
    // 提供时（由视图通过 data-auth-* 注入 $auth->check 结果）逐项判断。
    function permitted(auth, key) {
        if (!auth) {
            return true;
        }
        return !!auth[key];
    }

    // 从列表 table 的 data-auth-* 属性读取权限映射（模板用 $auth->check 输出）
    function readAuth(table, keys) {
        var auth = {};
        $.each(keys, function (_, key) {
            auth[key] = !!table.data('auth-' + key);
        });
        return auth;
    }

    var CpqCommon = {
        // CPQ 状态徽标统一配色（Bootstrap label 颜色，扩展 FastAdmin 默认 custom 映射）
        statusCustom: {
            draft: 'gray',
            pending: 'warning',
            published: 'success',
            expired: 'danger',
            normal: 'success',
            hidden: 'gray',
            disabled: 'gray',
            enabled: 'success',
            withdrawn: 'gray'
        },
        booleanFormatter: function (value) {
            return parseInt(value, 10) === 1
                ? '<span class="label label-success">是</span>'
                : '<span class="label label-default">否</span>';
        },

        readAuth: readAuth,

        // 只读详情按钮（btn-dialog 由 backend.js 全局处理；权限经 data-operate-detail 注入）
        detailButton: function (baseUrl) {
            return {
                name: 'detail',
                text: '详情',
                title: '详情',
                icon: 'fa fa-list',
                classname: 'btn btn-xs btn-info btn-dialog',
                url: baseUrl + '/detail/ids/{id}',
                extend: 'data-toggle="tooltip" data-container="body" data-area=\'["80%","85%"]\''
            };
        },

        // 版本化记录的编辑/删除守卫：已发布/已失效不可编辑（隐藏编辑按钮，
        // 服务端 assertEditable 重复校验）；仅草稿可删除（服务端 assertDraftDeletable 重复校验）
        versionGuardButtons: function () {
            return [
                {
                    name: 'edit',
                    icon: 'fa fa-pencil',
                    title: '编辑',
                    extend: 'data-toggle="tooltip" data-container="body"',
                    classname: 'btn btn-xs btn-success btn-editone',
                    visible: function (row) {
                        return row.status === 'draft' || row.status === 'pending';
                    }
                },
                {
                    name: 'del',
                    icon: 'fa fa-trash',
                    title: '删除',
                    extend: 'data-toggle="tooltip"',
                    classname: 'btn btn-xs btn-danger btn-delone',
                    visible: function (row) {
                        return row.status === 'draft';
                    }
                }
            ];
        },

        // 版本生命周期按钮：提交/发布/停用/复制新版本
        // directPublish 为 true 时免审批（BOM 映射）：草稿直接发布，无提交按钮
        versionButtons: function (baseUrl, auth, directPublish) {
            var buttons = [];
            if (!directPublish) {
                buttons.push({
                    name: 'submit',
                    text: '提交',
                    icon: 'fa fa-send',
                    classname: 'btn btn-xs btn-info btn-ajax',
                    url: baseUrl + '/submit',
                    confirm: '确认提交审批？提交后不可直接编辑。',
                    refresh: true,
                    visible: function (row) {
                        return permitted(auth, 'submit') && row.status === 'draft';
                    }
                });
            }
            buttons.push({
                name: 'publish',
                text: '发布',
                icon: 'fa fa-check',
                classname: 'btn btn-xs btn-success btn-ajax',
                url: baseUrl + '/publish',
                confirm: directPublish ? '确认发布当前草稿？发布后不可直接修改。' : '确认发布当前版本？发布后不可直接修改。',
                refresh: true,
                visible: function (row) {
                    return permitted(auth, 'publish') && row.status === (directPublish ? 'draft' : 'pending');
                }
            });
            buttons.push({
                name: 'expire',
                text: '停用',
                icon: 'fa fa-ban',
                classname: 'btn btn-xs btn-warning btn-ajax',
                url: baseUrl + '/expire',
                confirm: '确认停用当前已发布版本？停用后不再对配置器生效。',
                refresh: true,
                visible: function (row) {
                    return permitted(auth, 'expire') && row.status === 'published';
                }
            });
            buttons.push({
                name: 'copy',
                text: '复制新版本',
                icon: 'fa fa-copy',
                classname: 'btn btn-xs btn-primary btn-ajax',
                url: baseUrl + '/copy',
                confirm: '确认复制为下一版本草稿？原版本保持不变。',
                refresh: true,
                visible: function (row) {
                    return permitted(auth, 'copy') && (row.status === 'published' || row.status === 'expired');
                }
            });
            return buttons;
        },

        // 免审批直接发布（BOM 映射）：保留旧接口签名，内部走统一实现
        directPublishButtons: function (baseUrl, auth) {
            return CpqCommon.versionButtons(baseUrl, auth, true);
        },

        // 删除失败时以弹窗结构化展示服务端返回的引用来源（引用影响提示），
        // 覆盖 Table.api.events.operate 的默认 btn-delone 处理器
        operateEvents: function () {
            var events = $.extend({}, Table.api.events.operate);
            events['click .btn-delone'] = function (e, value, row) {
                e.stopPropagation();
                e.preventDefault();
                var table = $(this).closest('table');
                var options = table.bootstrapTable('getOptions');
                var ids = row[options.pk];
                Layer.confirm('确认删除该记录？删除前服务端会检查版本状态与引用。', {icon: 3, title: '删除确认'}, function (confirmIndex) {
                    Layer.close(confirmIndex);
                    Fast.api.ajax({
                        url: options.extend.del_url,
                        type: 'POST',
                        data: {action: 'del', ids: ids}
                    }, function () {
                        table.trigger('uncheckbox');
                        table.bootstrapTable('refresh');
                        return false;
                    }, function (data, ret) {
                        // 引用影响提示：服务端消息已含引用表与数量，弹窗完整展示
                        var message = (ret && ret.msg) ? String(ret.msg) : '删除失败';
                        Layer.alert(
                            '<div style="max-height:320px;overflow:auto;word-break:break-all">' +
                            $('<div>').text(message).html().replace(/\n/g, '<br>') + '</div>',
                            {icon: 2, title: '无法删除'}
                        );
                        return false;
                    });
                });
            };
            return events;
        },

        // 详情页只读模式：禁用全部输入并隐藏提交栏
        bindDetail: function () {
            var form = $('form[role=form]');
            form.find('input, select, textarea, button').prop('disabled', true);
            form.find('.layer-footer').hide();
            if ($.fn.selectpicker) {
                form.find('.selectpicker').selectpicker('refresh');
            }
        },

        // ------------------------------------------------------------------
        // M2 共享组件（GYTAI-74）
        // ------------------------------------------------------------------

        // 控制价分级徽标（定价引擎 classification 四档）
        classificationCustom: {
            normal: 'success',
            line_approval: 'warning',
            company_approval: 'danger',
            forbidden: 'inverse'
        },
        classificationFormatter: function (value) {
            var texts = {
                normal: '正常',
                line_approval: '需产线审批',
                company_approval: '需公司审批',
                forbidden: '禁止提交'
            };
            var color = CpqCommon.classificationCustom[value] || 'default';
            return '<span class="label label-' + color + '">' + (texts[value] || value) + '</span>';
        },

        // 金额展示：后端 DECIMAL 字符串原样渲染，前端不做任何浮点运算
        moneyFormatter: function (value) {
            return value === null || value === undefined || value === '' ? '' : $('<span>').text(String(value)).html();
        },

        // 树形列表名称列：按 level 缩进（需 sortName='path' 保证父子相邻）
        treeNameFormatter: function (value, row) {
            var level = Math.max(1, parseInt(row.level, 10) || 1);
            var indent = '';
            for (var i = 1; i < level; i++) {
                indent += '<span style="display:inline-block;width:18px"></span>';
            }
            return indent + (level > 1 ? '<i class="fa fa-angle-right text-muted"></i> ' : '<i class="fa fa-folder-open-o text-warning"></i> ') +
                $('<span>').text(value == null ? '' : String(value)).html();
        },

        // 树节点「移动」行内按钮：弹窗选择新父节点（下拉由 index 接口实时加载）
        moveButton: function (baseUrl, auth) {
            return {
                name: 'move',
                text: '移动',
                icon: 'fa fa-arrows',
                classname: 'btn btn-xs btn-warning',
                visible: function () {
                    return permitted(auth, 'move');
                },
                events: {
                    'click': function (e, value, row) {
                        e.stopPropagation();
                        e.preventDefault();
                        Fast.api.ajax({url: baseUrl + '/index', type: 'GET', data: {limit: 999, offset: 0, sort: 'path', order: 'asc'}}, function (data) {
                            var options = '<option value="0">（作为根节点）</option>';
                            $.each((data && data.rows) || [], function (_, item) {
                                if (parseInt(item.id, 10) === parseInt(row.id, 10)) {
                                    return;
                                }
                                options += '<option value="' + item.id + '">' +
                                    $('<span>').text(item.code + ' ' + item.name).html() + '</option>';
                            });
                            Layer.open({
                                type: 1,
                                title: '移动节点：' + $('<span>').text(row.name).html(),
                                area: ['420px', '220px'],
                                content: '<div style="padding:20px"><div class="form-group"><label>新父节点</label>' +
                                    '<select id="cpq-move-parent" class="form-control">' + options + '</select></div>' +
                                    '<p class="text-muted">服务端将校验成环并自动重建子树路径。</p></div>',
                                btn: ['移动', '取消'],
                                yes: function (index) {
                                    Fast.api.ajax({
                                        url: baseUrl + '/move',
                                        type: 'POST',
                                        data: {ids: row.id, parent_id: $('#cpq-move-parent').val()}
                                    }, function () {
                                        Layer.close(index);
                                        $('#table').bootstrapTable('refresh');
                                        return false;
                                    });
                                }
                            });
                            return false;
                        });
                        return false;
                    }
                }
            };
        },

        // 导入预览工具栏按钮：上传 xlsx/xls/csv，服务端纯校验不落库，弹窗展示错误行与合法数据
        importPreviewToolbar: function (toolbar, baseUrl, auth) {
            if (!permitted(auth, 'importpreview')) {
                return;
            }
            $(toolbar).append('<a href="javascript:;" class="btn btn-info btn-cpq-import" title="导入预览"><i class="fa fa-upload"></i> 导入预览</a>');
            $(toolbar).on('click', '.btn-cpq-import', function () {
                Layer.open({
                    type: 1,
                    title: '导入预览（仅校验，不写入）',
                    area: ['720px', '520px'],
                    content: '<div style="padding:20px">' +
                        '<div class="form-group"><input type="file" id="cpq-import-file" accept=".xlsx,.xls,.csv"></div>' +
                        '<p class="text-muted">首行为表头；服务端逐行校验并返回错误行与合法数据预览。</p>' +
                        '<div id="cpq-import-result" style="max-height:340px;overflow:auto"></div></div>',
                    btn: ['上传预览', '关闭'],
                    yes: function (index) {
                        var file = $('#cpq-import-file')[0].files[0];
                        if (!file) {
                            Toastr.error('请先选择文件');
                            return;
                        }
                        var formData = new FormData();
                        formData.append('file', file);
                        $.ajax({
                            url: Fast.api.fixurl(baseUrl + '/importpreview'),
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            success: function (ret) {
                                if (ret.code !== 1) {
                                    Toastr.error(ret.msg || '预览失败');
                                    return;
                                }
                                var result = ret.data || {};
                                var html = '<p>共 <b>' + (result.total || 0) + '</b> 行，合法 <b class="text-success">' +
                                    (result.valid_count || 0) + '</b> 行，错误 <b class="text-danger">' +
                                    ((result.errors || []).length) + '</b> 行</p>';
                                if ((result.errors || []).length) {
                                    html += '<table class="table table-condensed table-bordered"><thead><tr><th>行号</th><th>字段</th><th>错误</th></tr></thead><tbody>';
                                    $.each(result.errors, function (_, error) {
                                        html += '<tr><td>' + $('<span>').text(String(error.row)).html() + '</td><td>' +
                                            $('<span>').text(String(error.field || '')).html() + '</td><td>' +
                                            $('<span>').text(String(error.message || error.code || '')).html() + '</td></tr>';
                                    });
                                    html += '</tbody></table>';
                                }
                                if ((result.items || []).length) {
                                    html += '<p class="text-muted">合法数据预览（前 5 行）：</p><pre style="max-height:160px;overflow:auto">' +
                                        $('<span>').text(JSON.stringify(result.items.slice(0, 5), null, 2)).html() + '</pre>';
                                }
                                $('#cpq-import-result').html(html);
                            },
                            error: function () {
                                Toastr.error('上传失败');
                            }
                        });
                    }
                });
            });
        },

        // ------------------------------------------------------------------
        // M3 共享组件（GYTAI-75：待办/审批/模板/打印）
        // ------------------------------------------------------------------

        // 审批任务/实例/委托/打印任务状态配色扩展
        approvalStatusCustom: {
            active: 'info',
            completed: 'success',
            rejected: 'danger',
            returned: 'warning',
            transferred: 'gray',
            superseded: 'gray',
            cancelled: 'gray',
            succeeded: 'success',
            processing: 'info',
            failed: 'danger',
            enabled: 'success',
            disabled: 'gray',
            expired: 'danger'
        },

        // 审批动作徽标
        actionCustom: {
            confirm: 'info',
            approve: 'success',
            reject: 'danger',
            return: 'warning',
            transfer: 'primary',
            add_sign: 'primary',
            withdraw: 'gray',
            urge: 'warning',
            cancel: 'gray'
        },
        actionFormatter: function (value, row) {
            var text = row && row.action_text ? row.action_text : value;
            var color = CpqCommon.actionCustom[value] || 'default';
            return '<span class="label label-' + color + '">' + $('<span>').text(text == null ? '' : String(text)).html() + '</span>';
        },

        // SLA 剩余（秒）：超时红色「已超时」，否则倒计时文案；无 SLA 显示 —
        slaFormatter: function (value, row) {
            if (row && parseInt(row.overdue, 10) === 1) {
                return '<span class="label label-danger">已超时 ' + CpqCommon.durationText(Math.abs(parseInt(value, 10) || 0)) + '</span>';
            }
            if (value === null || value === undefined || value === '') {
                return '<span class="text-muted">—</span>';
            }
            var seconds = parseInt(value, 10) || 0;
            return '<span class="label label-' + (seconds < 3600 ? 'warning' : 'default') + '">剩余 ' + CpqCommon.durationText(seconds) + '</span>';
        },

        // 秒 → 人类可读时长
        durationText: function (seconds) {
            seconds = Math.abs(parseInt(seconds, 10) || 0);
            var days = Math.floor(seconds / 86400);
            var hours = Math.floor((seconds % 86400) / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            if (days > 0) {
                return days + '天' + hours + '小时';
            }
            if (hours > 0) {
                return hours + '小时' + minutes + '分';
            }
            return minutes + '分钟';
        },

        // 审批动作幂等键
        genActionKey: function (prefix) {
            return (prefix || 'AP') + '-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
        },

        // 版本对比工具栏按钮：勾选同编码的两个版本，逐字段对比（差异行高亮）
        versionDiffToolbar: function (toolbar, table, labels) {
            $(toolbar).append('<a href="javascript:;" class="btn btn-primary btn-cpq-diff" title="勾选两条同编码版本进行对比"><i class="fa fa-exchange"></i> 版本对比</a>');
            $(toolbar).on('click', '.btn-cpq-diff', function () {
                var selections = table.bootstrapTable('getSelections');
                if (selections.length !== 2) {
                    Toastr.error('请勾选两条记录进行版本对比');
                    return;
                }
                if (selections[0].code !== selections[1].code) {
                    Toastr.error('仅支持同一编码的不同版本对比');
                    return;
                }
                var skip = {id: 1, createtime: 1, updatetime: 1, status_text: 1};
                var keys = {};
                $.each([selections[0], selections[1]], function (_, row) {
                    $.each(row, function (key) {
                        if (!skip[key]) {
                            keys[key] = true;
                        }
                    });
                });
                var html = '<table class="table table-condensed table-bordered"><thead><tr><th>字段</th>' +
                    '<th>版本 ' + $('<span>').text(String(selections[0].version)).html() + '</th>' +
                    '<th>版本 ' + $('<span>').text(String(selections[1].version)).html() + '</th></tr></thead><tbody>';
                var hasDiff = false;
                $.each(keys, function (key) {
                    var left = selections[0][key];
                    var right = selections[1][key];
                    var changed = String(left == null ? '' : left) !== String(right == null ? '' : right);
                    if (changed) {
                        hasDiff = true;
                    }
                    html += '<tr' + (changed ? ' class="warning"' : '') + '><td>' +
                        $('<span>').text((labels && labels[key]) || key).html() + '</td><td>' +
                        $('<span>').text(left == null ? '' : String(left)).html() + '</td><td>' +
                        $('<span>').text(right == null ? '' : String(right)).html() + '</td></tr>';
                });
                html += '</tbody></table>';
                if (!hasDiff) {
                    html = '<p class="text-muted" style="padding:10px">两个版本字段内容一致。</p>' + html;
                }
                Layer.open({
                    type: 1,
                    title: '版本对比：' + $('<span>').text(String(selections[0].code)).html(),
                    area: ['760px', '560px'],
                    content: '<div style="padding:15px;max-height:520px;overflow:auto">' + html + '</div>',
                    btn: ['关闭']
                });
            });
        }
    };
    return CpqCommon;
});
