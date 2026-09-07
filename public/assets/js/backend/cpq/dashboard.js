/**
 * P01 销售驾驶舱（GYTAI-78）。
 *
 * 首屏一次 AJAX 加载全部区块；金额均为服务端 Decimal 字符串，
 * 本文件不做任何浮点换算，字符串直接交给 ECharts/文本渲染；
 * KPI/漏斗/分布点击携带规范化筛选下钻到报价列表。
 */
define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'moment', 'echarts', 'echarts-theme', 'backend/cpq/report_common'],
    function ($, _, Backend, Table, Form, moment, echarts, __, ReportCommon) {
        'use strict';

        var STATUS_LABELS = {
            draft: '草稿', submitted: '待审批', approved: '已批准',
            sent: '已发送', accepted: '已接受', expired: '已过期'
        };

        var Controller = {
            index: function () {
                var currentFilters = {};
                var charts = [];

                function initChart(id) {
                    var el = document.getElementById(id);
                    if (!el) {
                        return null;
                    }
                    var chart = echarts.init(el, 'walden');
                    charts.push(chart);
                    return chart;
                }

                function disposeCharts() {
                    $.each(charts, function (_, chart) {
                        chart.dispose();
                    });
                    charts = [];
                }

                function drillTo(extra) {
                    location.href = ReportCommon.drilldownUrl('cpq/quote/index', currentFilters, extra || {});
                }

                function kpiCard(options) {
                    var card = ReportCommon.renderKpi(options);
                    if (options.drilldown) {
                        card.on('click', function () {
                            drillTo(options.drilldown);
                        });
                    }
                    return card;
                }

                function renderKpis(kpis) {
                    var row = $('#cpq-kpi-row').empty();
                    if (!kpis) {
                        return;
                    }
                    var month = kpis.month_new || {};
                    var periodText = month.period ? (month.period.from + ' ~ ' + month.period.to) : '';
                    row.append(kpiCard({
                        label: '本期新建报价',
                        value: String(month.count == null ? '-' : month.count),
                        sub: periodText,
                        drilldown: month.drilldown
                    }));
                    row.append(kpiCard({
                        label: '待审批',
                        value: String(kpis.pending_approval == null ? '-' : kpis.pending_approval.count),
                        drilldown: kpis.pending_approval && kpis.pending_approval.drilldown
                    }));
                    row.append(kpiCard({
                        label: '已批准',
                        value: String(kpis.approved == null ? '-' : kpis.approved.count),
                        drilldown: kpis.approved && kpis.approved.drilldown
                    }));
                    row.append(kpiCard({
                        label: '已驳回',
                        value: String(kpis.rejected == null ? '-' : kpis.rejected.count),
                        drilldown: kpis.rejected && kpis.rejected.drilldown
                    }));
                    row.append(kpiCard({
                        label: '即将到期（7天内）',
                        value: String(kpis.expiring_soon == null ? '-' : kpis.expiring_soon.count),
                        drilldown: kpis.expiring_soon && kpis.expiring_soon.drilldown
                    }));
                    var expected = kpis.expected_amount || {};
                    var items = expected.items || [];
                    var amountText = items.length
                        ? $.map(items, function (item) {
                            return ReportCommon.escapeHtml(item.currency + ' ' + item.amount);
                        }).join('<br>')
                        : '-';
                    row.append($(
                        '<div class="col-sm-4 col-md-2"><div class="panel panel-default cpq-kpi-card" style="margin-bottom:12px;cursor:pointer">' +
                        '<div class="panel-body" style="padding:12px 15px">' +
                        '<div class="text-muted" style="font-size:12px">预期金额（活跃）</div>' +
                        '<div style="font-size:16px;font-weight:600;line-height:1.5">' + amountText + '</div>' +
                        '</div></div></div>'
                    ).on('click', function () {
                        drillTo(expected.drilldown || {});
                    }));
                }

                function renderRisks(risks) {
                    var row = $('#cpq-risk-row').empty();
                    if (!risks) {
                        return;
                    }
                    var defs = [
                        {key: 'need_line_approval', label: '待产线审批'},
                        {key: 'need_company_approval', label: '待公司审批'},
                        {key: 'below_company_floor', label: '低于公司底线'},
                        {key: 'missing_price_policy', label: '缺失价格政策'}
                    ];
                    $.each(defs, function (_, def) {
                        var item = risks[def.key] || {};
                        row.append(kpiCard({
                            label: def.label,
                            value: String(item.count == null ? 0 : item.count),
                            drilldown: item.drilldown && item.drilldown.status ? item.drilldown : null
                        }));
                    });
                }

                function renderFunnelChart(funnel) {
                    var chart = initChart('cpq-chart-funnel');
                    if (!chart) {
                        return;
                    }
                    var segments = (funnel && funnel.segments) || [];
                    chart.setOption($.extend({}, ReportCommon.chartDefaults, {
                        tooltip: {
                            trigger: 'item',
                            formatter: function (info) {
                                var segment = segments[info.dataIndex] || {};
                                var amounts = segment.amounts || {};
                                var amountLines = '';
                                $.each(amounts, function (currency, amount) {
                                    amountLines += '<br>' + ReportCommon.escapeHtml(currency) + ' ' + ReportCommon.escapeHtml(amount);
                                });
                                return ReportCommon.escapeHtml(info.name) + '：' + info.value + ' 单' + amountLines;
                            }
                        },
                        series: [{
                            type: 'funnel',
                            left: '10%',
                            width: '80%',
                            top: 10,
                            bottom: 10,
                            minSize: '12%',
                            label: {show: true, position: 'inside'},
                            data: $.map(segments, function (segment) {
                                return {
                                    name: STATUS_LABELS[segment.status] || segment.status,
                                    value: segment.count,
                                    drilldown: segment.drilldown
                                };
                            })
                        }]
                    }));
                    chart.on('click', function (params) {
                        var segment = segments[params.dataIndex];
                        if (segment && segment.drilldown) {
                            drillTo(segment.drilldown);
                        }
                    });
                }

                function renderTrendChart(trend) {
                    var chart = initChart('cpq-chart-trend');
                    if (!chart) {
                        return;
                    }
                    var months = (trend && trend.months) || [];
                    chart.setOption($.extend({}, ReportCommon.chartDefaults, {
                        tooltip: {trigger: 'axis', formatter: ReportCommon.tooltipFormatter},
                        legend: {data: ['报价数', '批准数', '通过率'], bottom: 0},
                        xAxis: {type: 'category', data: $.map(months, function (m) { return m.month; })},
                        yAxis: [
                            {type: 'value', name: '数量', minInterval: 1},
                            {type: 'value', name: '通过率'}
                        ],
                        series: [
                            {name: '报价数', type: 'line', smooth: true, data: $.map(months, function (m) { return m.quote_count; })},
                            {name: '批准数', type: 'line', smooth: true, data: $.map(months, function (m) { return m.approved_count; })},
                            {name: '通过率', type: 'line', smooth: true, yAxisIndex: 1, data: $.map(months, function (m) { return m.approval_rate; })}
                        ]
                    }));
                    chart.on('click', function (params) {
                        var m = months[params.dataIndex];
                        if (m && m.drilldown) {
                            drillTo(m.drilldown);
                        }
                    });
                }

                function renderDistributionChart(containerId, rows, title) {
                    var chart = initChart(containerId);
                    if (!chart) {
                        return;
                    }
                    chart.setOption($.extend({}, ReportCommon.chartDefaults, {
                        title: {text: title, left: 'center', textStyle: {fontSize: 13}},
                        tooltip: {trigger: 'item', formatter: ReportCommon.tooltipFormatter},
                        series: [{
                            type: 'pie',
                            radius: ['30%', '65%'],
                            center: ['50%', '55%'],
                            label: {formatter: '{b}: {c}'},
                            data: $.map(rows || [], function (row) {
                                return {name: row.name, value: row.quote_count};
                            })
                        }]
                    }));
                    chart.on('click', function (params) {
                        var row = (rows || [])[params.dataIndex];
                        if (row && row.drilldown && !$.isEmptyObject(row.drilldown)) {
                            drillTo(row.drilldown);
                        }
                    });
                }

                function renderTodos(todos) {
                    var body = $('#cpq-todo-body').empty();
                    $.each(todos || [], function (_, task) {
                        var slaText;
                        if (task.overdue) {
                            slaText = '<span class="label label-danger">已超时</span>';
                        } else if (task.sla_remaining == null) {
                            slaText = '-';
                        } else {
                            slaText = '剩余 ' + Math.floor(task.sla_remaining / 3600) + '小时' + Math.floor((task.sla_remaining % 3600) / 60) + '分';
                        }
                        var arrived = task.arrived_at
                            ? Table.api.formatter.datetime(task.arrived_at, null, {})
                            : '-';
                        body.append(
                            '<tr>' +
                            '<td>' + ReportCommon.escapeHtml(task.quote_code) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(task.quote_name) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(task.customer_name) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(task.node_text) + '</td>' +
                            '<td>' + ReportCommon.escapeHtml(task.currency ? task.currency + ' ' + task.total_amount : task.total_amount) + '</td>' +
                            '<td>' + arrived + '</td>' +
                            '<td>' + slaText + '</td>' +
                            '</tr>'
                        );
                    });
                    if (!body.children().length) {
                        body.append('<tr><td colspan="7" class="text-muted text-center">暂无待审批任务</td></tr>');
                    }
                }

                function renderQuickLinks(links) {
                    var box = $('#cpq-quick-links').empty();
                    $.each(links || [], function (_, link) {
                        $('<a href="javascript:;" class="btn btn-default btn-block" style="margin-bottom:6px"></a>')
                            .text(link.title)
                            .on('click', function () {
                                Fast.api.open(link.url, link.title);
                            })
                            .appendTo(box);
                    });
                }

                function render(data) {
                    renderKpis(data.kpis);
                    renderRisks(data.risks);
                    renderFunnelChart(data.funnel);
                    renderTrendChart(data.trend);
                    var distribution = data.product_distribution || {};
                    renderDistributionChart('cpq-chart-business-unit', distribution.business_units, '按事业部');
                    renderDistributionChart('cpq-chart-product-line', distribution.product_lines, '按产品线');
                    renderDistributionChart('cpq-chart-series', distribution.series, '按产品系列');
                    renderDistributionChart('cpq-chart-model', distribution.models, '按产品型号');
                    renderTodos(data.todos);
                    renderQuickLinks(data.quick_links);

                    // 首屏就绪打点：全部区块渲染完成后一次性标记
                    performance.mark('cpq-first-screen-ready');
                    window.__CPQ_FIRST_SCREEN_READY__ = true;
                }

                function load(filters) {
                    currentFilters = ReportCommon.canonicalFilters(filters);
                    disposeCharts();
                    Fast.api.ajax({
                        url: 'cpq/dashboard/index',
                        type: 'GET',
                        data: currentFilters
                    }, function (data, ret) {
                        render((ret && ret.data) || {});
                        return false;
                    }, function (data, ret) {
                        Layer.alert(ReportCommon.escapeHtml(ret && ret.msg ? ret.msg : '加载失败'), {icon: 2, title: '驾驶舱加载失败'});
                        return false;
                    });
                }

                ReportCommon.bindFilterForm('#cpq-filter-form', load);
                load({});
            }
        };

        return Controller;
    });
