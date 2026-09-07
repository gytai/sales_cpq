define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common'], function ($, undefined, Backend, Table, Form, moment, CpqCommon) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    var statusCustom = $.extend({}, CpqCommon.statusCustom, CpqCommon.approvalStatusCustom);
    var languageTexts = {zh: '中文', en: '英文'};

    var Controller = {
        // P60 报价打印记录：异步 PDF 任务列表 + 受控下载/哈希验证/失败重试
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/quote_document/index' + location.search
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['generate', 'download', 'verify', 'retry']);
            var pollTimer = null;

            function schedulePoll(data) {
                // 排队/生成中任务自动轮询（5s），全部终态后停止
                if (pollTimer) {
                    clearTimeout(pollTimer);
                    pollTimer = null;
                }
                var active = false;
                $.each(data || [], function (_, row) {
                    if (row.status === 'pending' || row.status === 'processing') {
                        active = true;
                        return false;
                    }
                });
                if (active) {
                    pollTimer = setTimeout(function () {
                        table.bootstrapTable('refresh', {silent: true});
                    }, 5000);
                }
            }

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: false,
                commonSearch: false,
                queryParams: function (params) {
                    params.page = Math.floor((params.offset || 0) / (params.limit || 20)) + 1;
                    params.status = $('#cpq-doc-status').val() || '';
                    params.product_line = $('#cpq-doc-line').val() || '';
                    params.keyword = $.trim($('#cpq-doc-keyword').val() || '');
                    return params;
                },
                onLoadSuccess: function (data) {
                    schedulePoll(data && data.rows ? data.rows : []);
                },
                columns: [[
                    {checkbox: false},
                    {field: 'quote_code', title: '报价单号', operate: false, formatter: function (value, row) {
                        return '<strong>' + escapeHtml(value) + '</strong>' +
                            (row.quote_name ? '<br><small class="text-muted">' + escapeHtml(row.quote_name) + '</small>' : '');
                    }},
                    {field: 'revision_no', title: '版本', operate: false, formatter: function (value) { return 'v' + escapeHtml(value); }},
                    {field: 'template_name', title: '模板', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'language', title: '语言', operate: false, formatter: function (value) { return languageTexts[value] || escapeHtml(value); }},
                    {field: 'status', title: '状态', searchList: Config.statusList, custom: statusCustom, formatter: function (value, row) {
                        var html = '<span class="label label-' + (statusCustom[value] || 'default') + '">' + escapeHtml(row.status_text || value) + '</span>';
                        if (value === 'failed' && row.error_message) {
                            html += '<br><small class="text-danger">' + escapeHtml(row.error_message) + '</small>';
                        }
                        return html;
                    }},
                    {field: 'file_hash', title: '文件哈希', operate: false, formatter: function (value) {
                        if (!value) {
                            return '<span class="text-muted">—</span>';
                        }
                        return '<span style="font-family:Menlo,Consolas,monospace" title="' + escapeHtml(value) + '">' + escapeHtml(String(value).substr(0, 12)) + '…</span>';
                    }},
                    {field: 'file_size', title: '大小', operate: false, formatter: function (value) {
                        var bytes = parseInt(value, 10) || 0;
                        if (bytes <= 0) {
                            return '<span class="text-muted">—</span>';
                        }
                        return bytes >= 1048576 ? Math.floor(bytes / 1048576 * 10) / 10 + ' MB' : Math.ceil(bytes / 1024) + ' KB';
                    }},
                    {field: 'download_count', title: '下载次数', operate: false},
                    {field: 'requested_by_name', title: '生成人', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'generated_at', title: '生成时间', operate: false, formatter: Table.api.formatter.datetime},
                    {
                        field: 'operate', title: __('Operate'), table: table,
                        events: {
                            'click .btn-cpq-download': function (e, value, row) {
                                e.stopPropagation();
                                // 受控下载：服务端哈希校验 + 计数 + 审计后输出文件流
                                window.open(Fast.api.fixurl('cpq/quote_document/download/ids/' + row.id), '_blank');
                                setTimeout(function () {
                                    table.bootstrapTable('refresh', {silent: true});
                                }, 1500);
                            },
                            'click .btn-cpq-verify': function (e, value, row) {
                                e.stopPropagation();
                                Fast.api.ajax({url: 'cpq/quote_document/verify', type: 'POST', data: {ids: row.id}}, function (data, ret) {
                                    var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
                                    Layer.alert(
                                        '<p>' + (payload && payload.match ? '<span class="text-success"><i class="fa fa-check-circle"></i> 哈希验证通过</span>'
                                            : '<span class="text-danger"><i class="fa fa-times-circle"></i> 哈希验证失败：文件与生成时不一致</span>') + '</p>' +
                                        '<p style="font-family:Menlo,Consolas,monospace;word-break:break-all"><small>记录哈希：' + escapeHtml((payload && payload.expected) || row.file_hash || '—') +
                                        '<br>实算哈希：' + escapeHtml((payload && payload.actual) || '—') + '</small></p>',
                                        {title: '哈希验证：' + escapeHtml(row.quote_code)}
                                    );
                                    return false;
                                }, function (data, ret) {
                                    Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '验证失败'), {icon: 2, title: '哈希验证失败'});
                                    return false;
                                });
                            },
                            'click .btn-cpq-retry': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认重新排队生成该打印任务？（仅失败任务可重试，已成功的正式文件不可覆盖）', {icon: 3, title: '重试确认'}, function (index) {
                                    Layer.close(index);
                                    Fast.api.ajax({url: 'cpq/quote_document/retry', type: 'POST', data: {ids: row.id}}, function () {
                                        Toastr.success('已重新排队生成');
                                        table.bootstrapTable('refresh');
                                        return false;
                                    }, function (data, ret) {
                                        Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '重试失败'), {icon: 2, title: '操作被拒绝'});
                                        return false;
                                    });
                                });
                            }
                        },
                        buttons: [
                            {
                                name: 'download', text: '下载', icon: 'fa fa-download',
                                classname: 'btn btn-xs btn-success btn-cpq-download',
                                visible: function (row) {
                                    return auth.download && row.status === 'succeeded';
                                }
                            },
                            {
                                name: 'verify', text: '验证哈希', icon: 'fa fa-shield',
                                classname: 'btn btn-xs btn-info btn-cpq-verify',
                                visible: function (row) {
                                    return auth.verify && row.status === 'succeeded';
                                }
                            },
                            {
                                name: 'retry', text: '重试', icon: 'fa fa-refresh',
                                classname: 'btn btn-xs btn-warning btn-cpq-retry',
                                visible: function (row) {
                                    return auth.retry && row.status === 'failed';
                                }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            $('#cpq-doc-status, #cpq-doc-line').on('change', function () {
                table.bootstrapTable('refresh');
            });
            $('#cpq-doc-keyword').on('keydown', function (e) {
                if (e.keyCode === 13) {
                    table.bootstrapTable('refresh');
                }
            });

            // 生成 PDF：选择已提交报价 + 语言 +（可选）模板
            $('#toolbar').on('click', '.btn-cpq-generate', function () {
                if (!auth.generate) {
                    Toastr.error('无生成 PDF 权限');
                    return;
                }
                Fast.api.ajax({url: 'cpq/quote_document/quotes', type: 'GET'}, function (data, ret) {
                    var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
                    var quotes = (payload && payload.quotes) || [];
                    if (!quotes.length) {
                        Toastr.error('暂无可打印报价（需已提交且存在冻结版本）');
                        return false;
                    }
                    var quoteOptions = '';
                    $.each(quotes, function (_, quote) {
                        quoteOptions += '<option value="' + quote.id + '">' +
                            escapeHtml(quote.code + '　' + (quote.name || '') + '（v' + quote.current_revision_no + ' / ' + (quote.customer_name || '—') + '）') + '</option>';
                    });
                    Layer.open({
                        type: 1,
                        title: '生成报价 PDF（异步任务，正式文件一经生成不可覆盖）',
                        area: ['520px', '400px'],
                        content: '<div style="padding:18px">' +
                            '<div class="form-group"><label>报价 <span class="text-danger">*</span></label>' +
                            '<select id="cpq-gen-quote" class="form-control">' + quoteOptions + '</select></div>' +
                            '<div class="form-group"><label>语言</label><select id="cpq-gen-language" class="form-control">' +
                            '<option value="zh">中文</option><option value="en">英文</option></select></div>' +
                            '<div class="form-group"><label>模板（留空=该语言+市场默认模板）</label>' +
                            '<input class="form-control selectpage" id="cpq-gen-template" data-source="cpq/quote_template/index" data-field="name" data-primary-key="id"></div>' +
                            '<p class="text-muted">同一版本+模板+语言已生成过的任务直接复用（幂等）。</p></div>',
                        btn: ['发起生成', '取消'],
                        success: function (layero) {
                            Form.events.selectpage($(layero));
                        },
                        yes: function (index) {
                            var quoteId = parseInt($('#cpq-gen-quote').val(), 10) || 0;
                            var language = $('#cpq-gen-language').val();
                            var templateId = parseInt($('#cpq-gen-template').val(), 10) || 0;
                            Layer.close(index);
                            Fast.api.ajax({
                                url: 'cpq/quote_document/generate',
                                type: 'POST',
                                data: {quote_id: quoteId, language: language, template_id: templateId}
                            }, function (data, ret) {
                                Toastr.success(ret && ret.msg ? ret.msg : 'PDF 生成任务已受理');
                                table.bootstrapTable('refresh');
                                return false;
                            }, function (data, ret) {
                                Layer.alert(escapeHtml(ret && ret.msg ? ret.msg : '任务创建失败'), {icon: 2, title: '操作被拒绝'});
                                return false;
                            });
                        }
                    });
                    return false;
                }, function (data, ret) {
                    Toastr.error(ret && ret.msg ? ret.msg : '报价候选加载失败');
                    return false;
                });
            });
        }
    };
    return Controller;
});
