define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);

    // 模板板块与文案键（与 QuoteTemplateService::SECTION_KEYS 一一对应，服务端白名单复校）
    var SECTIONS = [
        ['show_cover', '封面'], ['show_company_info', '公司信息'], ['show_product_table', '产品表'],
        ['show_technical_params', '技术参数'], ['show_terms', '条款'], ['show_signature', '签章'],
        ['show_watermark', '水印']
    ];
    var TEXTS = [
        ['cover_title', '封面标题'], ['cover_subtitle', '封面副标题'], ['header_text', '页眉'],
        ['footer_text', '页脚'], ['watermark_text', '水印文字'], ['signature_note', '签章说明'], ['remark', '备注']
    ];

    // 结构化板块编辑 ↔ content_json（提交始终以组装后的 JSON 为准）
    function bindContentEditor(form) {
        var content = window.__CPQ_TEMPLATE_CONTENT__ || {};
        var sectionsHtml = '';
        $.each(SECTIONS, function (_, pair) {
            var checked = parseInt(content[pair[0]], 10) === 1 || content[pair[0]] === true;
            sectionsHtml += '<label class="checkbox-inline"><input type="checkbox" class="cpq-tpl-section" data-key="' + pair[0] + '"' +
                (checked ? ' checked' : '') + '> ' + pair[1] + '</label>';
        });
        $('#cpq-tpl-sections', form).html(sectionsHtml);

        var textsHtml = '';
        $.each(TEXTS, function (_, pair) {
            textsHtml += '<div class="form-group" style="margin-bottom:8px"><label class="control-label col-sm-3" style="text-align:right">' +
                pair[1] + '</label><div class="col-sm-9"><input class="form-control cpq-tpl-text" data-key="' + pair[0] + '" value="' +
                escapeHtml(content[pair[0]] || '') + '" placeholder="可引用 {{变量}}"></div></div>';
        });
        $('#cpq-tpl-texts', form).html(textsHtml);

        // 变量白名单：点击插入到最近聚焦的文案输入框
        var lastFocused = null;
        $(document).on('focus', '.cpq-tpl-text', function () {
            lastFocused = this;
        });
        var variables = window.__CPQ_TEMPLATE_VARIABLES__ || {};
        var varsHtml = '';
        $.each(variables, function (variable, label) {
            varsHtml += '<a href="javascript:;" class="label label-info cpq-tpl-var" data-var="{{' + variable + '}}" title="点击插入" style="margin:2px;display:inline-block">' +
                '{{' + escapeHtml(variable) + '}} ' + escapeHtml(label) + '</a>';
        });
        $('#cpq-tpl-variables', form).html(varsHtml);
        $(document).on('click', '.cpq-tpl-var', function () {
            var token = $(this).data('var');
            if (lastFocused) {
                var input = $(lastFocused);
                input.val(input.val() + token);
            } else {
                Toastr.info('请先点击要插入变量的文案输入框');
            }
        });

        // 提交前组装 content_json（先于 Form 提交处理执行）
        function assemble() {
            var assembled = {};
            $('.cpq-tpl-section', form).each(function () {
                assembled[$(this).data('key')] = this.checked ? 1 : 0;
            });
            $('.cpq-tpl-text', form).each(function () {
                assembled[$(this).data('key')] = $.trim($(this).val());
            });
            $('#cpq-tpl-content-json', form).val(JSON.stringify(assembled));
        }
        form.on('submit.cpqTemplate', assemble);
        // 首次渲染即组装一次，保证直接提交也有值
        assemble();
    }

    var Controller = {
        // P59 报价模板
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/quote_template/index' + location.search,
                add_url: 'cpq/quote_template/add',
                edit_url: 'cpq/quote_template/edit',
                del_url: 'cpq/quote_template/del',
                multi_url: 'cpq/quote_template/multi',
                table: 'cpq_quote_template'
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['add', 'edit', 'del', 'preview', 'publish', 'copy', 'setdefault', 'disable']);

            function postAction(url, row, confirmText, doneText) {
                Layer.confirm(confirmText, {icon: 3, title: '操作确认'}, function (index) {
                    Layer.close(index);
                    Fast.api.ajax({url: url, type: 'POST', data: {ids: row.id}}, function () {
                        Toastr.success(doneText);
                        table.bootstrapTable('refresh');
                        return false;
                    }, function (data, ret) {
                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '操作失败'), {icon: 2, title: '操作被拒绝'});
                        return false;
                    });
                });
            }

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '模板编码', operate: 'LIKE'},
                    {field: 'name', title: '模板名称', operate: 'LIKE', formatter: function (value, row) {
                        return escapeHtml(value) + (row.name_en ? '<br><small class="text-muted">' + escapeHtml(row.name_en) + '</small>' : '');
                    }},
                    {field: 'language', title: '语言', searchList: Config.languageList, formatter: function (value) {
                        return escapeHtml((Config.languageList && Config.languageList[value]) || value);
                    }},
                    {field: 'market_scope', title: '适用市场', searchList: Config.marketList, formatter: function (value) {
                        return escapeHtml((Config.marketList && Config.marketList[value]) || value);
                    }},
                    {field: 'paper_size', title: '纸张', operate: false},
                    {field: 'is_default', title: '市场默认', operate: false, formatter: CpqCommon.booleanFormatter},
                    {field: 'version', title: '版本', operate: false, formatter: function (value) { return 'v' + escapeHtml(value); }},
                    {field: 'status', title: '状态', searchList: Config.statusList, custom: statusCustom, formatter: Table.api.formatter.status},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: $.extend({}, Table.api.events.operate, {
                            'click .btn-cpq-preview': function (e, value, row) {
                                e.stopPropagation();
                                e.preventDefault();
                                Fast.api.ajax({url: 'cpq/quote_template/preview', type: 'POST', data: {ids: row.id}}, function (data, ret) {
                                    var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
                                    var missing = (payload && payload.missing_variable_texts) || [];
                                    var warning = missing.length
                                        ? '<div class="alert alert-warning">测试数据缺失变量：' + escapeHtml(missing.join('、')) + '</div>'
                                        : '';
                                    Layer.open({
                                        type: 1,
                                        title: '模板预览：' + escapeHtml(row.name) + '（测试数据渲染）',
                                        area: ['820px', '600px'],
                                        content: '<div style="padding:10px;height:100%;box-sizing:border-box">' + warning +
                                            '<iframe style="width:100%;height:' + (missing.length ? '440' : '500') + 'px;border:1px solid #ddd" id="cpq-preview-frame"></iframe></div>',
                                        success: function (layero) {
                                            var frame = $(layero).find('#cpq-preview-frame')[0];
                                            if (frame) {
                                                var doc = frame.contentWindow.document;
                                                doc.open();
                                                doc.write((payload && payload.html) || '<p>无预览内容</p>');
                                                doc.close();
                                            }
                                        }
                                    });
                                    return false;
                                }, function (data, ret) {
                                    Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '预览失败'), {icon: 2, title: '预览失败'});
                                    return false;
                                });
                            },
                            'click .btn-cpq-publish': function (e, value, row) {
                                e.stopPropagation();
                                postAction('cpq/quote_template/publish', row,
                                    '确认发布模板 ' + escapeHtml(row.code) + '？发布前服务端将校验板块与变量白名单，发布后内容不可直接修改。',
                                    '模板已发布');
                            },
                            'click .btn-cpq-setdefault': function (e, value, row) {
                                e.stopPropagation();
                                postAction('cpq/quote_template/setdefault', row,
                                    '将模板 ' + escapeHtml(row.code) + ' 设为该语言+市场的默认模板？同范围其他模板将取消默认。',
                                    '已设为市场默认模板');
                            },
                            'click .btn-cpq-copy': function (e, value, row) {
                                e.stopPropagation();
                                postAction('cpq/quote_template/copy', row,
                                    '复制模板 ' + escapeHtml(row.code) + ' 为新版本草稿？原模板保持不变。',
                                    '已复制为新版本草稿');
                            },
                            'click .btn-cpq-disable': function (e, value, row) {
                                e.stopPropagation();
                                postAction('cpq/quote_template/disable', row,
                                    '停用模板 ' + escapeHtml(row.code) + '？停用后不再参与默认匹配。',
                                    '模板已停用');
                            }
                        }),
                        buttons: [
                            {
                                name: 'preview', text: '预览', icon: 'fa fa-eye',
                                classname: 'btn btn-xs btn-info btn-cpq-preview',
                                visible: function () { return auth.preview; }
                            },
                            {
                                name: 'edit', text: '编辑', icon: 'fa fa-pencil',
                                classname: 'btn btn-xs btn-success btn-editone',
                                visible: function (row) {
                                    return auth.edit && row.status !== 'published';
                                }
                            },
                            {
                                name: 'publish', text: '发布', icon: 'fa fa-check',
                                classname: 'btn btn-xs btn-primary btn-cpq-publish',
                                visible: function (row) {
                                    return auth.publish && (row.status === 'draft' || row.status === 'disabled');
                                }
                            },
                            {
                                name: 'setdefault', text: '设为默认', icon: 'fa fa-star',
                                classname: 'btn btn-xs btn-warning btn-cpq-setdefault',
                                visible: function (row) {
                                    return auth.setdefault && row.status === 'published' && parseInt(row.is_default, 10) !== 1;
                                }
                            },
                            {
                                name: 'copy', text: '复制新版本', icon: 'fa fa-copy',
                                classname: 'btn btn-xs btn-default btn-cpq-copy',
                                visible: function (row) {
                                    return auth.copy && row.status !== 'draft';
                                }
                            },
                            {
                                name: 'disable', text: '停用', icon: 'fa fa-ban',
                                classname: 'btn btn-xs btn-danger btn-cpq-disable',
                                visible: function (row) {
                                    return auth.disable && row.status === 'published';
                                }
                            },
                            {
                                name: 'del', text: '删除', icon: 'fa fa-trash',
                                classname: 'btn btn-xs btn-danger btn-delone',
                                visible: function (row) {
                                    return auth.del && row.status === 'draft';
                                }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);
        },

        add: function () {
            var form = $('form[role=form]');
            bindContentEditor(form);
            Form.api.bindevent(form);
        },

        edit: function () {
            var form = $('form[role=form]');
            bindContentEditor(form);
            Form.api.bindevent(form);
        }
    };
    return Controller;
});
