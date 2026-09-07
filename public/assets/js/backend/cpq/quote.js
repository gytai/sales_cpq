define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'backend/cpq/common', 'backend/cpq/quote_wizard'], function ($, undefined, Backend, Table, Form, moment, CpqCommon, QuoteWizard) {
    'use strict';

    function escapeHtml(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    // 报价状态徽标配色（在 CPQ 公共配色上补齐报价全状态机）
    var statusCustom = $.extend({}, CpqCommon.statusCustom, {
        submitted: 'info',
        approved: 'success',
        sent: 'primary',
        accepted: 'success',
        rejected: 'danger',
        cancelled: 'gray',
        expired: 'gray',
        revised: 'gray'
    });

    var approvalTexts = {none: '无需审批', line: '产线审批', company: '公司审批', forbidden: '禁止提交'};
    var approvalCustom = {none: 'success', line: 'warning', company: 'danger', forbidden: 'inverse'};
    function approvalFormatter(value) {
        if (!value) {
            return '<span class="label label-default">—</span>';
        }
        return '<span class="label label-' + (approvalCustom[value] || 'default') + '">' + (approvalTexts[value] || value) + '</span>';
    }

    function genIdempotencyKey() {
        return 'QW-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
    }

    // 修订说明对话框：允许留空（layer prompt 空值时不会触发回调，故用自定义弹层）
    function revisionDialog(onConfirm) {
        Layer.open({
            type: 1,
            title: '创建修订版本',
            area: ['440px', '240px'],
            content: '<div style="padding:18px"><div class="form-group"><label>修订说明（可留空）</label>' +
                '<textarea id="cpq-revision-note" class="form-control" rows="3" placeholder="本次修订的原因或说明"></textarea></div>' +
                '<p class="text-muted">原报价将置为已修订（只读），生成同名新草稿。</p></div>',
            btn: ['创建修订', '取消'],
            yes: function (index) {
                var note = $.trim($('#cpq-revision-note').val());
                Layer.close(index);
                onConfirm(note ? [note] : []);
            }
        });
    }

    // 动作统一出口：成功后刷新列表或跳转，失败弹出服务端业务错误
    function postAction(url, data, onSuccess) {
        Fast.api.ajax({url: url, type: 'POST', contentType: 'application/json; charset=UTF-8', data: JSON.stringify(data)}, function (data, ret) {
            if (typeof onSuccess === 'function') {
                onSuccess(ret && ret.data && ret.data.payload ? ret.data.payload : data);
            }
            return false;
        }, function (data, ret) {
            var message = ret && ret.msg ? ret.msg : '操作失败';
            var code = ret && ret.data && ret.data.business_code ? ' [' + ret.data.business_code + ']' : '';
            Layer.alert(escapeHtml(message + code), {icon: 2, title: '操作被拒绝'});
            return false;
        });
    }

    var Controller = {
        index: function () {
            Table.api.init({extend: {
                index_url: 'cpq/quote/index' + location.search,
                detail_url: 'cpq/quote/detail'
            }});
            var table = $('#table');
            var auth = CpqCommon.readAuth(table, ['wizard', 'save', 'submit', 'withdraw', 'copy', 'revision', 'diff']);
            var viewStatus = '';

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                search: true,
                commonSearch: false,
                queryParams: function (params) {
                    params.status = viewStatus;
                    return params;
                },
                columns: [[
                    {checkbox: true},
                    {field: 'code', title: '报价编码', operate: false},
                    {field: 'name', title: '报价名称', operate: false},
                    {field: 'customer_name', title: '客户', operate: false, formatter: function (value) { return escapeHtml(value || '—'); }},
                    {field: 'product_line', title: '产品线', searchList: Config.productLineList ? Config.productLineList.reduce(function (map, line) { map[line] = line; return map; }, {}) : {}, operate: false},
                    {field: 'currency', title: '币种', operate: false},
                    {field: 'status', title: '状态', searchList: Config.statusList, operate: false, formatter: Table.api.formatter.status, custom: statusCustom},
                    {field: 'current_revision_no', title: '版本', operate: false, formatter: function (value) { return parseInt(value, 10) > 0 ? 'v' + value : '草稿'; }},
                    {field: 'final_approval_level', title: '审批级别', operate: false, formatter: approvalFormatter},
                    {field: 'updatetime', title: '更新时间', formatter: Table.api.formatter.datetime, operate: false},
                    {
                        field: 'operate', title: __('Operate'), table: table, events: {
                            'click .btn-cpq-edit': function (e, value, row) {
                                e.stopPropagation();
                                Fast.api.open('cpq/quote/wizard/ids/' + row.id, '编辑报价 ' + row.code);
                            },
                            'click .btn-cpq-submit': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认提交报价 ' + escapeHtml(row.code) + '？提交后冻结快照并进入审批。', {icon: 3, title: '提交确认'}, function (index) {
                                    Layer.close(index);
                                    postAction('cpq/quote/submit', {id: row.id, idempotency_key: genIdempotencyKey()}, function () {
                                        Toastr.success('提交成功');
                                        table.bootstrapTable('refresh');
                                    });
                                });
                            },
                            'click .btn-cpq-withdraw': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('确认撤回报价 ' + escapeHtml(row.code) + '？撤回后回到可编辑状态。', {icon: 3, title: '撤回确认'}, function (index) {
                                    Layer.close(index);
                                    postAction('cpq/quote/withdraw', {id: row.id}, function () {
                                        Toastr.success('已撤回');
                                        table.bootstrapTable('refresh');
                                    });
                                });
                            },
                            'click .btn-cpq-copy': function (e, value, row) {
                                e.stopPropagation();
                                Layer.confirm('复制报价 ' + escapeHtml(row.code) + ' 为新草稿？', {icon: 3, title: '复制确认'}, function (index) {
                                    Layer.close(index);
                                    postAction('cpq/quote/copy', {id: row.id}, function (payload) {
                                        Toastr.success('已复制为新草稿');
                                        if (payload && payload.id) {
                                            Fast.api.open('cpq/quote/wizard/ids/' + payload.id, '编辑报价 ' + (payload.code || ''));
                                        } else {
                                            table.bootstrapTable('refresh');
                                        }
                                    });
                                });
                            },
                            'click .btn-cpq-revision': function (e, value, row) {
                                e.stopPropagation();
                                revisionDialog(function (note) {
                                    postAction('cpq/quote/revision', {id: row.id, note: note}, function (payload) {
                                        Toastr.success('已创建修订版本草稿');
                                        if (payload && payload.id) {
                                            Fast.api.open('cpq/quote/wizard/ids/' + payload.id, '编辑修订 ' + (payload.code || ''));
                                        } else {
                                            table.bootstrapTable('refresh');
                                        }
                                    });
                                });
                            },
                            'click .btn-cpq-diff': function (e, value, row) {
                                e.stopPropagation();
                                Fast.api.open('cpq/quote/diff/ids/' + row.id, '版本差异 ' + row.code);
                            }
                        },
                        buttons: [
                            {
                                name: 'detail', text: '详情', title: '详情', icon: 'fa fa-list',
                                classname: 'btn btn-xs btn-info btn-dialog',
                                url: 'cpq/quote/detail/ids/{id}',
                                extend: 'data-toggle="tooltip" data-container="body" data-area=\'["85%","90%"]\''
                            },
                            {
                                name: 'edit', text: '编辑', icon: 'fa fa-pencil',
                                classname: 'btn btn-xs btn-success btn-cpq-edit',
                                visible: function (row) {
                                    return auth.wizard && (row.status === 'draft' || row.status === 'withdrawn' || row.status === 'returned');
                                }
                            },
                            {
                                name: 'submit', text: '提交', icon: 'fa fa-send',
                                classname: 'btn btn-xs btn-primary btn-cpq-submit',
                                visible: function (row) {
                                    return auth.submit && (row.status === 'draft' || row.status === 'withdrawn' || row.status === 'returned');
                                }
                            },
                            {
                                name: 'withdraw', text: '撤回', icon: 'fa fa-undo',
                                classname: 'btn btn-xs btn-warning btn-cpq-withdraw',
                                visible: function (row) {
                                    return auth.withdraw && row.status === 'submitted';
                                }
                            },
                            {
                                name: 'revision', text: '修订', icon: 'fa fa-code-fork',
                                classname: 'btn btn-xs btn-danger btn-cpq-revision',
                                visible: function (row) {
                                    return auth.revision && $.inArray(row.status, ['submitted', 'approved', 'sent', 'accepted', 'revised']) !== -1;
                                }
                            },
                            {
                                name: 'diff', text: '差异', icon: 'fa fa-exchange',
                                classname: 'btn btn-xs btn-default btn-cpq-diff',
                                visible: function (row) {
                                    return auth.diff && parseInt(row.current_revision_no, 10) > 0;
                                }
                            },
                            {
                                name: 'copy', text: '复制', icon: 'fa fa-copy',
                                classname: 'btn btn-xs btn-default btn-cpq-copy',
                                visible: function () {
                                    return auth.copy;
                                }
                            }
                        ],
                        formatter: Table.api.formatter.operate
                    }
                ]]
            });
            Table.api.bindevent(table);

            // 多视图：状态分组切换
            $('#cpq-quote-views').on('click', 'button', function () {
                $('#cpq-quote-views button').removeClass('active');
                $(this).addClass('active');
                viewStatus = $(this).data('status') || '';
                table.bootstrapTable('refresh');
            });

            // 新建报价：整页向导
            $('#toolbar').on('click', '.btn-cpq-quote-add', function () {
                Fast.api.open('cpq/quote/wizard', '新建报价');
            });
        },

        wizard: function () {
            QuoteWizard.run();
        },

        detail: function () {
            var quote = window.__CPQ_QUOTE__ || {};
            // 控制单价列仅公司级定价角色可见：表头与数据列由服务端权限 + 后端脱敏共同决定，
            // 无权限时 DOM 中不得出现「控制单价」表头或数值（而非仅用 CSS 隐藏）。
            var canViewCompanyFloor = $('.cpq-quote-detail').data('can-view-company-floor') === 1;
            var snapshotColSpan = canViewCompanyFloor ? 11 : 10;

            var base = '<dl class="dl-horizontal">';
            base += '<dt>报价编码</dt><dd>' + escapeHtml(quote.code) + '</dd>';
            base += '<dt>报价名称</dt><dd>' + escapeHtml(quote.name) + '</dd>';
            base += '<dt>状态</dt><dd><span class="label label-' + (statusCustom[quote.status] || 'default') + '">' + escapeHtml(quote.status_text || quote.status) + '</span></dd>';
            base += '<dt>客户</dt><dd>' + escapeHtml(quote.customer_name || '—') + '</dd>';
            base += '<dt>代理商</dt><dd>' + escapeHtml(quote.agent_name || '—') + '</dd>';
            base += '<dt>销售组织</dt><dd>' + escapeHtml(quote.sales_org_name || '—') + '</dd>';
            base += '<dt>产品线</dt><dd>' + escapeHtml(quote.product_line) + '</dd>';
            base += '<dt>币种</dt><dd>' + escapeHtml(quote.currency) + '</dd>';
            base += '<dt>我方公司</dt><dd>' + escapeHtml(quote.company || '—') + '</dd>';
            base += '<dt>市场范围</dt><dd>' + ({domestic: '国内', international: '国际'}[quote.market_scope] || '按客户国家推导') + '</dd>';
            base += '<dt>说明</dt><dd>' + escapeHtml(quote.description || '—') + '</dd>';
            base += '</dl>';
            $('#cpq-detail-base').html(base);

            var revision = '<dl class="dl-horizontal">';
            revision += '<dt>当前版本</dt><dd>' + (parseInt(quote.current_revision_no, 10) > 0 ? 'v' + quote.current_revision_no : '草稿（未提交）') + '</dd>';
            revision += '<dt>审批级别</dt><dd>' + approvalFormatter(quote.final_approval_level) + '</dd>';
            revision += '<dt>价格哈希</dt><dd class="text-amount">' + escapeHtml(quote.final_price_hash || '—') + '</dd>';
            revision += '<dt>提交时间</dt><dd>' + (quote.submitted_at ? moment.unix(quote.submitted_at).format('YYYY-MM-DD HH:mm:ss') : '—') + '</dd>';
            revision += '<dt>乐观锁版本</dt><dd>' + escapeHtml(quote.optimistic_lock_version) + '</dd>';
            revision += '</dl>';
            $('#cpq-detail-revision').html(revision);

            var lines = '';
            $.each(quote.lines || [], function (_, line) {
                lines += '<tr><td>' + escapeHtml(line.line_no) + '</td>' +
                    '<td>' + escapeHtml(line.model_code || line.model_id) + ' ' + escapeHtml(line.model_name || '') + '</td>' +
                    '<td class="text-amount">' + escapeHtml(line.quantity) + '</td>' +
                    '<td>' + escapeHtml(line.unit) + '</td>' +
                    '<td class="text-amount">' + escapeHtml(line.manual_discount || '—') + (line.discount_reason ? '<br><small class="text-muted">' + escapeHtml(line.discount_reason) + '</small>' : '') + '</td>' +
                    '<td class="text-amount"><small>' + escapeHtml(line.configuration_hash || '—') + '</small></td>' +
                    '<td><pre>' + escapeHtml(JSON.stringify(line.configuration || {}, null, 2)) + '</pre></td></tr>';
            });
            $('#cpq-detail-lines').html(lines || '<tr><td colspan="7" class="text-muted text-center">无明细行</td></tr>');

            // 价格快照（提交时冻结，不随主数据变化；敏感列由服务端按角色脱敏后下发）
            var snapshot = quote.price_snapshot;
            if (!snapshot || !snapshot.lines || snapshot.lines.length === 0) {
                $('#cpq-detail-snapshot-panel .panel-heading')
                    .text('价格快照（当前版本尚未冻结，提交后生成）');
                $('#cpq-snapshot-currency').text('草稿未提交，暂无冻结金额。');
                $('#cpq-snapshot-lines').html('<tr><td colspan="' + snapshotColSpan + '" class="text-muted text-center">暂无快照</td></tr>');
                $('#cpq-snapshot-totals').empty();
            } else {
                $('#cpq-snapshot-revision').text(snapshot.revision_no);
                $('#cpq-snapshot-frozen-at').text(snapshot.frozen_at || '—');
                var crossCurrency = snapshot.pricing_currency !== snapshot.quote_currency;
                var currencyText = '定价币种 ' + escapeHtml(snapshot.pricing_currency) + '；报价币种 ' + escapeHtml(snapshot.quote_currency);
                if (crossCurrency && snapshot.exchange_rate && snapshot.exchange_rate.rate) {
                    currencyText += '；汇率快照 ' + escapeHtml(snapshot.exchange_rate.rate) +
                        '（' + escapeHtml(snapshot.exchange_rate.from_currency || snapshot.pricing_currency) + '→' +
                        escapeHtml(snapshot.exchange_rate.to_currency || snapshot.quote_currency) +
                        '，' + (snapshot.exchange_rate.direction === 'inverse' ? '逆向换算' : (snapshot.exchange_rate.direction === 'direct' ? '直接汇率' : snapshot.exchange_rate.direction)) +
                        (snapshot.exchange_rate.effective_date ? '，生效日 ' + escapeHtml(snapshot.exchange_rate.effective_date) : '') + '）';
                } else if (!crossCurrency) {
                    currencyText += '；定价与报价币种一致，未发生换算';
                }
                $('#cpq-snapshot-currency').text(currencyText);
                var hasControl = false;
                var snapshotRows = '';
                $.each(snapshot.lines, function (_, line) {
                    hasControl = hasControl || typeof line.control_unit_price !== 'undefined';
                    var rowTotal = crossCurrency && line.converted && line.converted.total ? line.converted.total : line.total;
                    snapshotRows += '<tr><td>' + escapeHtml(line.line_no) + '</td>' +
                        '<td>' + escapeHtml(line.model_code) + '</td>' +
                        '<td class="text-amount">' + escapeHtml(line.quantity) + '</td>' +
                        '<td class="text-amount">' + escapeHtml(line.manual_discount || '—') + '</td>' +
                        '<td class="text-amount">' + escapeHtml(line.unit_subtotal) + ' ' + escapeHtml(line.pricing_currency) + '</td>' +
                        '<td class="text-amount">' + escapeHtml(line.fees_amount) + '</td>' +
                        '<td class="text-amount">' + escapeHtml(line.untaxed) + '</td>' +
                        '<td class="text-amount">' + escapeHtml(line.tax) + '</td>' +
                        '<td class="text-amount">' + escapeHtml(rowTotal) + ' ' + escapeHtml(line.quote_currency) + '</td>' +
                        '<td>' + approvalFormatter(line.approval_level) + '</td>' +
                        (canViewCompanyFloor ? '<td class="text-amount cpq-snapshot-control-col">' + (typeof line.control_unit_price !== 'undefined' ? escapeHtml(line.control_unit_price) + ' ' + escapeHtml(line.pricing_currency) : '—') + '</td>' : '') + '</tr>';
                });
                $('#cpq-snapshot-lines').html(snapshotRows);
                if (!hasControl) {
                    $('#cpq-detail-snapshot-panel').addClass('cpq-snapshot-no-control');
                }
                $('#cpq-snapshot-totals').html(
                    '<tr class="active"><th colspan="6">整单合计（' + escapeHtml(snapshot.quote_currency) + '）</th>' +
                    '<th class="text-amount">' + escapeHtml(snapshot.totals.untaxed) + '</th>' +
                    '<th class="text-amount">' + escapeHtml(snapshot.totals.tax) + '</th>' +
                    '<th class="text-amount">' + escapeHtml(snapshot.totals.total) + '</th>' +
                    '<th colspan="' + (canViewCompanyFloor ? 2 : 1) + '"></th></tr>'
                );
            }

            var termTypes = {payment: '付款', trade: '贸易', warranty: '质保', delivery: '交付', other: '其他'};
            var terms = '';
            $.each(quote.terms || [], function (_, term) {
                terms += '<tr><td>' + escapeHtml(termTypes[term.term_type] || term.term_type) + '</td>' +
                    '<td>' + escapeHtml(term.term_code || '—') + '</td>' +
                    '<td><pre>' + escapeHtml(term.content || '') + '</pre></td>' +
                    '<td>' + (parseInt(term.is_editable, 10) === 1 ? '<span class="label label-default">可编辑</span>' : '<span class="label label-inverse">系统锁定</span>') + '</td></tr>';
            });
            $('#cpq-detail-terms').html(terms || '<tr><td colspan="4" class="text-muted text-center">无条款</td></tr>');

            var revisions = '';
            $.each(quote.revisions || [], function (_, rev) {
                revisions += '<tr><td>v' + escapeHtml(rev.revision_no) + '</td>' +
                    '<td>' + escapeHtml(rev.status) + '</td>' +
                    '<td>' + approvalFormatter(rev.approval_level) + '</td>' +
                    '<td class="text-amount"><small>' + escapeHtml(rev.price_hash || '—') + '</small></td>' +
                    '<td>' + (parseInt(rev.submittable, 10) === 1 ? '是' : '否') + '</td>' +
                    '<td>' + (rev.frozen_at ? moment.unix(rev.frozen_at).format('YYYY-MM-DD HH:mm:ss') : '—') + '</td></tr>';
            });
            $('#cpq-detail-revisions').html(revisions || '<tr><td colspan="6" class="text-muted text-center">尚无冻结版本</td></tr>');

            // 状态化操作：按钮仅提示，服务端逐项重复校验
            var actions = $('<div></div>');
            function addButton(cls, icon, text, handler) {
                var button = $('<button type="button" class="btn ' + cls + '"><i class="fa ' + icon + '"></i> ' + text + '</button>');
                button.on('click', handler);
                actions.append(button).append(' ');
            }
            function reload() {
                                if (typeof Layer !== 'undefined') {
                    var index = Layer.alert('操作成功', {icon: 1, title: '提示'}, function (i) {
                        Layer.close(i);
                        location.reload();
                    });
                } else {
                    location.reload();
                }
            }
            if (quote.status === 'draft' || quote.status === 'withdrawn' || quote.status === 'returned') {
                addButton('btn-success', 'fa-pencil', '编辑', function () {
                    Fast.api.open('cpq/quote/wizard/ids/' + quote.id, '编辑报价 ' + quote.code);
                });
                addButton('btn-primary', 'fa-send', '提交', function () {
                    Layer.confirm('确认提交报价 ' + escapeHtml(quote.code) + '？', {icon: 3, title: '提交确认'}, function (index) {
                        Layer.close(index);
                        postAction('cpq/quote/submit', {id: quote.id, idempotency_key: genIdempotencyKey()}, reload);
                    });
                });
            }
            if (quote.status === 'submitted') {
                addButton('btn-warning', 'fa-undo', '撤回', function () {
                    Layer.confirm('确认撤回该报价？', {icon: 3, title: '撤回确认'}, function (index) {
                        Layer.close(index);
                        postAction('cpq/quote/withdraw', {id: quote.id}, reload);
                    });
                });
            }
            if ($.inArray(quote.status, ['submitted', 'approved', 'sent', 'accepted', 'revised']) !== -1) {
                addButton('btn-danger', 'fa-code-fork', '创建修订版本', function () {
                    revisionDialog(function (note) {
                        postAction('cpq/quote/revision', {id: quote.id, note: note}, function (payload) {
                            if (payload && payload.id) {
                                Fast.api.open('cpq/quote/wizard/ids/' + payload.id, '编辑修订 ' + (payload.code || ''));
                            }
                        });
                    });
                });
            }
            if (parseInt(quote.current_revision_no, 10) > 0) {
                addButton('btn-default', 'fa-exchange', '版本差异', function () {
                    Fast.api.open('cpq/quote/diff/ids/' + quote.id, '版本差异 ' + quote.code);
                });
            }
            addButton('btn-default', 'fa-copy', '复制为新草稿', function () {
                Layer.confirm('复制该报价为新草稿？', {icon: 3, title: '复制确认'}, function (index) {
                    Layer.close(index);
                    postAction('cpq/quote/copy', {id: quote.id}, function (payload) {
                        if (payload && payload.id) {
                            Fast.api.open('cpq/quote/wizard/ids/' + payload.id, '编辑报价 ' + (payload.code || ''));
                        }
                    });
                });
            });
            $('#cpq-detail-actions').append(actions);
        },

        diff: function () {
            var revisions = window.__CPQ_REVISIONS__ || [];
            var quoteId = window.__CPQ_QUOTE_ID__ || 0;
            var from = $('#cpq-diff-from');
            var to = $('#cpq-diff-to');
            from.append('<option value="0">空基线（v0）</option>');
            $.each(revisions, function (_, rev) {
                var label = 'v' + rev.revision_no + '（' + rev.status + (rev.approval_level ? ' / ' + (approvalTexts[rev.approval_level] || rev.approval_level) : '') + '）';
                from.append('<option value="' + rev.revision_no + '">' + escapeHtml(label) + '</option>');
                to.append('<option value="' + rev.revision_no + '">' + escapeHtml(label) + '</option>');
            });
            if (revisions.length >= 2) {
                from.val(String(revisions[revisions.length - 2].revision_no));
                to.val(String(revisions[revisions.length - 1].revision_no));
            } else if (revisions.length === 1) {
                from.val('0');
                to.val(String(revisions[0].revision_no));
            }

            var amountLabels = {goods_discounted: '折扣后商品金额', fees: '费用', untaxed: '未税金额', tax: '税额', total: '含税总额', margin_amount: '毛利额'};
            var changeTexts = {added: '新增', removed: '删除', modified: '修改'};
            var fieldTexts = {model: '型号', quantity: '数量', configuration: '配置', price: '价格'};

            $('#cpq-diff-run').on('click', function () {
                var fromNo = from.val();
                var toNo = to.val();
                $('#cpq-diff-error').addClass('hidden');
                Fast.api.ajax({url: 'cpq/quote/diffdata', type: 'POST', data: {id: quoteId, from: fromNo, to: toNo}}, function (data, ret) {
                    var payload = ret && ret.data && ret.data.payload ? ret.data.payload : data;
                    $('#cpq-diff-empty').addClass('hidden');
                    $('#cpq-diff-result').removeClass('hidden');
                    $('#cpq-diff-from-no').text('v' + payload.from_revision_no);
                    $('#cpq-diff-to-no').text('v' + payload.to_revision_no);

                    var totalsFrom = (payload.summary && payload.summary.totals && payload.summary.totals.from) || {};
                    var totalsTo = (payload.summary && payload.summary.totals && payload.summary.totals.to) || {};
                    var keys = {};
                    $.each([totalsFrom, totalsTo], function (_, totals) {
                        $.each(totals, function (key) { keys[key] = true; });
                    });
                    var totalsHtml = '';
                    $.each(keys, function (key) {
                        var left = totalsFrom[key] == null ? '—' : String(totalsFrom[key]);
                        var right = totalsTo[key] == null ? '—' : String(totalsTo[key]);
                        totalsHtml += '<tr' + (left !== right ? ' class="warning"' : '') + '><th>' + escapeHtml(amountLabels[key] || key) + '</th>' +
                            '<td class="text-amount">' + escapeHtml(left) + '</td>' +
                            '<td class="text-amount">' + escapeHtml(right) + '</td></tr>';
                    });
                    $('#cpq-diff-totals').html(totalsHtml || '<tr><td colspan="3" class="text-muted text-center">无汇总数据</td></tr>');

                    var linesHtml = '';
                    var count = 0;
                    $.each(payload.lines || [], function (_, line) {
                        count++;
                        var rowClass = line.change === 'added' ? 'diff-added' : (line.change === 'removed' ? 'diff-removed' : 'diff-modified');
                        var fields = '';
                        if (line.fields) {
                            $.each(line.fields, function (field, change) {
                                fields += '<div><strong>' + escapeHtml(fieldTexts[field] || field) + '</strong>：' +
                                    '<code>' + escapeHtml(change.from == null ? '—' : String(change.from)) + '</code> → ' +
                                    '<code>' + escapeHtml(change.to == null ? '—' : String(change.to)) + '</code></div>';
                            });
                        }
                        linesHtml += '<tr class="' + rowClass + '"><td>' + escapeHtml(line.line_no) + '</td>' +
                            '<td>' + escapeHtml(changeTexts[line.change] || line.change) + '</td>' +
                            '<td>' + (fields || '<span class="text-muted">—</span>') + '</td></tr>';
                    });
                    $('#cpq-diff-count').text(String(count));
                    $('#cpq-diff-lines').html(linesHtml || '<tr><td colspan="3" class="text-muted text-center">两个版本明细行一致</td></tr>');
                    return false;
                }, function (data, ret) {
                    $('#cpq-diff-result').addClass('hidden');
                    $('#cpq-diff-error').removeClass('hidden').text(ret && ret.msg ? ret.msg : '差异计算失败');
                    return false;
                });
            });
        }
    };
    return Controller;
});
